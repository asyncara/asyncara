#include "../../ext/native.h"
#include <assert.h>
#include <errno.h>
#include <stdlib.h>
#include <stdio.h>
/* Link-time injection into Asyncara code only. No threads run in this test. */
void *__real_malloc(size_t);
void *__real_calloc(size_t, size_t);
void *__real_realloc(void *, size_t);
static int fail_malloc, fail_calloc, fail_realloc;
void *__wrap_malloc(size_t n) { if (fail_malloc) { fail_malloc = 0; errno = ENOMEM; return NULL; } return __real_malloc(n); }
void *__wrap_calloc(size_t n, size_t size) { if (fail_calloc) { fail_calloc = 0; errno = ENOMEM; return NULL; } return __real_calloc(n,size); }
void *__wrap_realloc(void *p, size_t n) { if (fail_realloc) { fail_realloc = 0; errno = ENOMEM; return NULL; } return __real_realloc(p,n); }
int main(void) {
    fail_calloc = 1;
    assert(ar_engine_new() == NULL);
    ar_engine *e = ar_engine_new(); assert(e);
    fail_malloc = 1; assert(ar_alloc(e, 64) == NULL);
    assert(atomic_load(&e->memory_bytes) == 0);
    void *p = ar_alloc(e, 64); assert(p);
    size_t owned = atomic_load(&e->memory_bytes);
    fail_realloc = 1; assert(ar_realloc(e, p, 128) == NULL);
    assert(atomic_load(&e->memory_bytes) == owned);
    fail_realloc = 1; assert(ar_realloc(e, p, 16) == NULL);
    assert(atomic_load(&e->memory_bytes) == owned);
    ar_free(p); assert(atomic_load(&e->memory_bytes) == 0);
    fail_malloc = 1; assert(ar_job_new(e, OP_READ) == NULL); assert(!e->allocations);
    fail_calloc = 1; assert(ar_wait(e, NULL, 0, 0) == -1 && errno == ENOMEM);
    assert(ar_wait(e, NULL, 0, 0) == 0);
    ar_engine_free(e);
    puts("Injected native allocation failures and recovery: PASS");
}
