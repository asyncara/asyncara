#include "../../ext/native.h"
#include <assert.h>
#include <errno.h>
#include <stdlib.h>
#include <string.h>
/* Injection is confined to Asyncara object-file calls, not shared libraries. */
void *__real_malloc(size_t);
void *__real_calloc(size_t, size_t);
void *__real_realloc(void *, size_t);
static unsigned fail_at, calls;
static int fail(void) { return fail_at && ++calls == fail_at; }
void *__wrap_malloc(size_t n) { if (fail()) { errno=ENOMEM; return NULL; } return __real_malloc(n); }
void *__wrap_calloc(size_t n,size_t size) { if (fail()) { errno=ENOMEM; return NULL; } return __real_calloc(n,size); }
void *__wrap_realloc(void *p,size_t n) { if (fail()) { errno=ENOMEM; return NULL; } return __real_realloc(p,n); }
int LLVMFuzzerTestOneInput(const unsigned char *data,size_t size) {
    if (!size) return 0;
    fail_at=data[0]; calls=0;
    ar_engine *e=ar_engine_new();
    if (!e) return 0;
    e->memory_limit=4096+(size>1?(size_t)data[1]*128:0);
    void *slots[8]={0};
    ar_value *values=ar_value_new(e,V_ARRAY);
    for (size_t i=2;i+2<size;i+=3) {
        size_t slot=data[i]%8, length=(size_t)data[i+1]*16+data[i+2];
        switch (data[i]%6) {
        case 0: ar_free(slots[slot]); slots[slot]=ar_calloc(e,length,2); break;
        case 1: { void *next=ar_realloc(e,slots[slot],length); if(next)slots[slot]=next; break; }
        case 2: ar_free(slots[slot]); slots[slot]=NULL; break;
        case 3: if(values) (void)ar_add(values,data[i+1]&1?"key":NULL,ar_string(e,(const char *)data+i,3)); break;
        case 4: {
            ar_job *job=ar_job_new(e,OP_READ);
            if(job){job->a=ar_strdup(e,"fuzz-path");job->result=ar_string(e,(const char *)data+i,3);ar_job_free(job);}
            break;
        }
        default: assert(!ar_alloc(e,SIZE_MAX)); assert(!ar_calloc(e,SIZE_MAX,2)); break;
        }
    }
    for(size_t i=0;i<8;i++)ar_free(slots[i]);
    ar_value_free(values);
    assert(!e->allocations);
    assert(atomic_load(&e->memory_bytes)==0);
    fail_at=0;
    ar_engine_free(e);
    return 0;
}
