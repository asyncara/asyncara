#include "../../ext/native.h"
#include <assert.h>
#include <errno.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/mman.h>
#include <unistd.h>
static int fail_mapping;
void *__real_mmap(void *, size_t, int, int, int, off_t);
void *__wrap_mmap(void *address, size_t length, int protection, int flags, int fd, off_t offset) {
    if (fail_mapping) { fail_mapping=0;errno=ENOMEM;return MAP_FAILED; }
    return __real_mmap(address,length,protection,flags,fd,offset);
}
static void *churn(void *argument) {
    ar_engine *engine=argument;
    for(int i=0;i<20;i++) {
        unsigned char *p=ar_alloc(engine,12*1024*1024);assert(p);
        memset(p,42,12*1024*1024);
        ar_free(p);
    }
    return NULL;
}
int main(int argc,char **argv) {
    (void)argv;
    const size_t large=1024*1024+7;
    ar_engine engine={0};engine.memory_limit=256*1024*1024;
    unsigned char *p=ar_alloc(&engine,large);assert(p);
    if(argc>1) { volatile unsigned char *bad=p;bad[large]=1;abort(); }
    memset(p,42,large);
    long page=sysconf(_SC_PAGESIZE);assert(page>0);
    size_t held=atomic_load(&engine.memory_bytes);assert(held>large&&held%(size_t)page==0);
    unsigned char *q=ar_realloc(&engine,p,large*2);assert(q);
    assert(q[0]==42&&q[large-1]==42);
    p=ar_realloc(&engine,q,128);assert(p&&p[127]==42);ar_free(p);
    p=ar_alloc(&engine,128);assert(p);memset(p,17,128);
    q=ar_realloc(&engine,p,large);assert(q&&q[127]==17);
    held=atomic_load(&engine.memory_bytes);
    engine.memory_limit=held;
    assert(!ar_realloc(&engine,q,large*2));assert(q[127]==17);
    assert(atomic_load(&engine.memory_bytes)==held);
    engine.memory_limit=256*1024*1024;
    fail_mapping=1;assert(!ar_realloc(&engine,q,large*2));assert(q[127]==17);
    assert(atomic_load(&engine.memory_bytes)==held);
    uintptr_t base=(uintptr_t)q/(size_t)page*(size_t)page;ar_free(q);
    unsigned char resident=0;errno=0;
    assert(mincore((void *)base,(size_t)page,&resident)==-1&&errno==ENOMEM);
    fail_mapping=1;assert(!ar_alloc(&engine,large));assert(!atomic_load(&engine.memory_bytes));
    pthread_t threads[4];
    for(int i=0;i<4;i++)assert(!pthread_create(&threads[i],NULL,churn,&engine));
    for(int i=0;i<4;i++)assert(!pthread_join(threads[i],NULL));
    assert(!atomic_load(&engine.memory_bytes));
    puts("Mapped allocation boundaries, relocation, quota, OS failure, release and workers: PASS");
}
