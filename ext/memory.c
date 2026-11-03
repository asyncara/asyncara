#include "native.h"
#include <assert.h>
#include <errno.h>
#include <stdlib.h>
#include <string.h>

/* Alignment is preserved for every C type. Owners outlive allocations: all
 * worker joins and buffer destruction precede engine destruction. Library-owned
 * allocations (curl, SQLite, TLS, connector internals) are outside this ledger. */
typedef union {
    max_align_t alignment;
    struct {
        ar_engine *engine;
        size_t bytes;
    } value;
} ar_allocation;

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
    if (reserve(e, total))
        return NULL;
    ar_allocation *allocation = malloc(total);
    if (!allocation) {
        atomic_fetch_sub(&e->memory_bytes, total);
        return NULL;
    }
    allocation->value.engine = e;
    allocation->value.bytes = total;
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
    free(allocation);
}
