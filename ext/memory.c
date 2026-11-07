#include "native.h"
#include <assert.h>
#include <errno.h>
#include <stdlib.h>
#include <string.h>
#include <sys/mman.h>
#include <unistd.h>
#include <time.h>

/* Diagnostic storage has a separate hard bound, like transient wait control
 * buffers. It must not consume the payload reserve needed to drain operations.
 * Publication is once per request; the pointer remains valid through worker
 * joins. Disabling capture never frees memory underneath a publisher. */
uint64_t ar_trace_clock(void) {
    int saved = errno;
    struct timespec now;
    uint64_t value = 0;
    if (!clock_gettime(CLOCK_MONOTONIC, &now) && now.tv_sec >= 0 &&
        (uint64_t)now.tv_sec <= (uint64_t)(INT64_MAX - 999999999) / 1000000000)
        value = (uint64_t)now.tv_sec * 1000000000 + now.tv_nsec;
    errno = saved;
    return value;
}
int ar_trace_configure(ar_engine *e, size_t capacity, unsigned mask) {
    if (capacity < 16 || capacity > AR_TRACE_MAX || (mask & ~AR_TRACE_ALL)) { errno = EINVAL; return -1; }
    ar_trace *t = atomic_load_explicit(&e->trace, memory_order_acquire);
    if (t && capacity != t->capacity) { errno = EINVAL; return -1; }
    if (!t) {
        if (!mask) return 0;
        t = calloc(1, sizeof(*t) + capacity * sizeof(ar_trace_event));
        if (!t) return -1;
        t->writer = (atomic_flag)ATOMIC_FLAG_INIT;
        atomic_init(&t->mask, mask);
        atomic_init(&t->sequence, 0); atomic_init(&t->dropped, 0);
        atomic_init(&t->clock_failures, 0); atomic_init(&t->scope, 0);
        t->capacity = capacity;
        if (!atomic_is_lock_free(&e->trace) || !atomic_is_lock_free(&t->mask) ||
            !atomic_is_lock_free(&t->sequence) || !atomic_is_lock_free(&t->scope)) {
            free(t); errno = ENOTSUP; return -1;
        }
        atomic_store_explicit(&e->trace, t, memory_order_release);
    } else atomic_store_explicit(&t->mask, mask, memory_order_relaxed);
    ar_trace_emit(e, AR_TRACE_LIFE, 1, e->request_id, capacity, mask, 0);
    return 0;
}
void ar_trace_emit(ar_engine *e, unsigned category, unsigned code, int64_t id, int64_t a, int64_t b, int64_t c) {
    ar_trace *t = atomic_load_explicit(&e->trace, memory_order_acquire);
    if (!t || !(atomic_load_explicit(&t->mask, memory_order_relaxed) & category)) return;
    int saved = errno;
    if (atomic_flag_test_and_set_explicit(&t->writer, memory_order_acquire)) {
        atomic_fetch_add_explicit(&t->sequence, 1, memory_order_relaxed);
        atomic_fetch_add_explicit(&t->dropped, 1, memory_order_relaxed);
        return; /* No spinning and no blocking lock on any producer. */
    }
    uint64_t seq = atomic_fetch_add_explicit(&t->sequence, 1, memory_order_relaxed) + 1;
    uint64_t ns = ar_trace_clock();
    if (ns) {
        t->events[t->next] = (ar_trace_event){seq, ns, atomic_load_explicit(&t->scope, memory_order_relaxed), id, a, b, c, category, code};
        t->next = (t->next + 1) % t->capacity;
        if (t->count < t->capacity) t->count++;
        t->written++;
    } else atomic_fetch_add_explicit(&t->clock_failures, 1, memory_order_relaxed);
    atomic_flag_clear_explicit(&t->writer, memory_order_release);
    errno = saved;
}
/* Caller supplies AR_TRACE_MAX event slots before admission. No Zend calls or
 * allocation while the ring is held. A busy snapshot is explicitly retryable. */
int ar_trace_read(ar_engine *e, ar_trace_event *events, ar_trace_status *status) {
    memset(status, 0, sizeof(*status));
    ar_trace *t = atomic_load_explicit(&e->trace, memory_order_acquire);
    if (!t) return 0;
    if (atomic_flag_test_and_set_explicit(&t->writer, memory_order_acquire)) { errno = EBUSY; return -1; }
    status->capacity = t->capacity; status->count = t->count;
    status->bytes = sizeof(*t) + t->capacity * sizeof(ar_trace_event);
    status->attempted = atomic_load_explicit(&t->sequence, memory_order_relaxed);
    status->dropped = atomic_load_explicit(&t->dropped, memory_order_relaxed);
    status->clock_failures = atomic_load_explicit(&t->clock_failures, memory_order_relaxed);
    status->mask = atomic_load_explicit(&t->mask, memory_order_relaxed);
    status->overwritten = t->written - t->count;
    size_t first = (t->next + t->capacity - t->count) % t->capacity;
    for (size_t i = 0; i < t->count; i++) events[i] = t->events[(first + i) % t->capacity];
    atomic_flag_clear_explicit(&t->writer, memory_order_release);
    return 0;
}
#ifndef __has_feature
#define __has_feature(x) 0
#endif
#if defined(__SANITIZE_ADDRESS__) || __has_feature(address_sanitizer)
#include <sanitizer/asan_interface.h>
#define AR_ASAN_MAPPINGS 1
#endif

/* Alignment is preserved for every C type. Owners outlive allocations: all
 * worker joins and buffer destruction precede engine destruction. Library-owned
 * allocations (curl, SQLite, TLS, connector internals) are outside this ledger. */
typedef union {
    max_align_t alignment;
    struct {
        ar_engine *engine;
        size_t bytes;
        size_t requested;
        size_t mapping;
    } value;
} ar_allocation;

/* Large worker buffers must not remain in thread malloc arenas after request
 * shutdown. This changes only Asyncara-owned allocations, never mallopt/global
 * allocator policy. Charge page rounding as well as the ownership header. */
#define AR_MAPPING_THRESHOLD (1024u * 1024u)

static int reserve(ar_engine *e, size_t bytes) {
    size_t current = atomic_load(&e->memory_bytes);
    do {
        if (current > e->memory_limit || bytes > e->memory_limit - current) {
            atomic_fetch_add(&e->memory_rejections, 1);
            errno = ENOMEM;
            return -1;
        }
    } while (!atomic_compare_exchange_weak(&e->memory_bytes, &current, current + bytes));
    size_t peak = atomic_load(&e->memory_peak);
    while (peak < current + bytes && !atomic_compare_exchange_weak(&e->memory_peak, &peak, current + bytes)) {
    }
    return 0;
}

void *ar_alloc(ar_engine *e, size_t bytes) {
    if (bytes > SIZE_MAX - sizeof(ar_allocation)) {
        errno = ENOMEM;
        return NULL;
    }
    size_t total = bytes + sizeof(ar_allocation);
    size_t mapping = 0;
    if (bytes >= AR_MAPPING_THRESHOLD) {
        long page = sysconf(_SC_PAGESIZE);
        if (page <= 0 || total > SIZE_MAX - ((size_t)page - 1)) {
            errno = ENOMEM;
            return NULL;
        }
        total = (total + (size_t)page - 1) / (size_t)page * (size_t)page;
        mapping = total;
    }
    if (reserve(e, total))
        return NULL;
    ar_allocation *allocation = mapping ? mmap(NULL, mapping, PROT_READ | PROT_WRITE,
                                               MAP_PRIVATE | MAP_ANONYMOUS, -1, 0) : malloc(total);
    if (allocation == MAP_FAILED || !allocation) {
        atomic_fetch_sub(&e->memory_bytes, total);
        return NULL;
    }
#ifdef AR_ASAN_MAPPINGS
    if (mapping) {
        __asan_unpoison_memory_region(allocation, mapping);
        __asan_poison_memory_region((char *)(allocation + 1) + bytes,
                                   mapping - sizeof(ar_allocation) - bytes);
    }
#endif
    allocation->value.engine = e;
    allocation->value.bytes = total;
    allocation->value.requested = bytes;
    allocation->value.mapping = mapping;
    return allocation + 1;
}

void *ar_calloc(ar_engine *e, size_t count, size_t bytes) {
    if (bytes && count > SIZE_MAX / bytes) {
        errno = ENOMEM;
        return NULL;
    }
    void *ptr = ar_alloc(e, count * bytes);
    if (ptr)
        memset(ptr, 0, count * bytes);
    return ptr;
}

void *ar_realloc(ar_engine *e, void *ptr, size_t bytes) {
    if (!ptr)
        return ar_alloc(e, bytes);
    if (bytes > SIZE_MAX - sizeof(ar_allocation)) {
        errno = ENOMEM;
        return NULL;
    }
    ar_allocation *old = (ar_allocation *)ptr - 1;
    assert(old->value.engine == e);
    if (old->value.mapping || bytes >= AR_MAPPING_THRESHOLD) {
        /* Count both buffers during relocation; failure preserves old ownership. */
        void *next = ar_alloc(e, bytes);
        if (!next) return NULL;
        memcpy(next, ptr, old->value.requested < bytes ? old->value.requested : bytes);
        ar_free(ptr);
        return next;
    }
    size_t before = old->value.bytes, after = bytes + sizeof(ar_allocation);
    if (after > before && reserve(e, after - before))
        return NULL;
    ar_allocation *next = realloc(old, after);
    if (!next) {
        if (after > before)
            atomic_fetch_sub(&e->memory_bytes, after - before);
        return NULL;
    }
    if (after < before)
        atomic_fetch_sub(&e->memory_bytes, before - after);
    next->value.bytes = after;
    next->value.requested = bytes;
    return next + 1;
}

char *ar_strdup(ar_engine *e, const char *string) {
    size_t bytes = strlen(string) + 1;
    char *copy = ar_alloc(e, bytes);
    if (copy)
        memcpy(copy, string, bytes);
    return copy;
}

void ar_free(void *ptr) {
    if (!ptr)
        return;
    ar_allocation *allocation = (ar_allocation *)ptr - 1;
    size_t before = atomic_fetch_sub(&allocation->value.engine->memory_bytes, allocation->value.bytes);
    assert(before >= allocation->value.bytes);
    (void)before; /* Keep release builds with NDEBUG warning-free. */
    if (allocation->value.mapping) {
#ifdef AR_ASAN_MAPPINGS
        __asan_unpoison_memory_region(allocation, allocation->value.mapping);
#endif
        int result = munmap(allocation, allocation->value.mapping);
        assert(result == 0);
        (void)result;
    } else {
        free(allocation);
    }
}
