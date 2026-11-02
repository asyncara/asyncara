#include "native.h"
#include <assert.h>
#include <errno.h>
#include <stdlib.h>
#include <string.h>
#include <sys/mman.h>
#include <unistd.h>
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
