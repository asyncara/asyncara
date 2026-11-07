#include "../../ext/native.h"
#include <assert.h>
#include <errno.h>
#include <stdio.h>
#include <stdlib.h>
#include <time.h>
static int fail_alloc, fail_clock;
void *__real_calloc(size_t,size_t);
void *__wrap_calloc(size_t n,size_t size) { if(fail_alloc){errno=ENOMEM;return NULL;}return __real_calloc(n,size); }
int __real_clock_gettime(clockid_t,struct timespec*);
int __wrap_clock_gettime(clockid_t id,struct timespec *ts) {if(fail_clock){errno=EIO;return -1;}return __real_clock_gettime(id,ts);}
static void *produce(void *ptr) {for(int i=0;i<10000;i++)ar_trace_emit(ptr,AR_TRACE_JOB,21,i,0,0,0);return NULL;}
int main(void) {
    ar_engine *e=ar_engine_new();assert(e);ar_trace_status s;
    ar_trace_event *events=calloc(AR_TRACE_MAX,sizeof(*events));assert(events);
    assert(!ar_trace_read(e,events,&s)&&s.count==0&&s.bytes==0);
    assert(ar_trace_configure(e,AR_TRACE_MAX+1,AR_TRACE_ALL)<0);
    fail_alloc=1;assert(ar_trace_configure(e,16,AR_TRACE_ALL)<0&&!atomic_load(&e->trace));fail_alloc=0;
    assert(!ar_trace_configure(e,16,AR_TRACE_ALL));ar_trace *t=atomic_load(&e->trace);
    assert(!atomic_flag_test_and_set(&t->writer));errno=E2BIG;
    ar_trace_emit(e,AR_TRACE_JOB,21,0,0,0,0);assert(errno==E2BIG);
    assert(ar_trace_read(e,events,&s)<0&&errno==EBUSY);atomic_flag_clear(&t->writer);
    fail_clock=1;errno=E2BIG;ar_trace_emit(e,AR_TRACE_JOB,21,0,0,0,0);assert(errno==E2BIG);fail_clock=0;
    pthread_t threads[4];for(int i=0;i<4;i++)assert(!pthread_create(&threads[i],NULL,produce,e));
    for(int i=0;i<4;i++)assert(!pthread_join(threads[i],NULL));
    assert(!ar_trace_read(e,events,&s)&&s.count==16&&s.clock_failures==1&&s.dropped>=1);
    assert(s.attempted==s.count+s.overwritten+s.dropped+s.clock_failures);
    for(size_t i=1;i<s.count;i++)assert(events[i].sequence>events[i-1].sequence&&events[i].ns>=events[i-1].ns);
    assert(!ar_trace_configure(e,16,0));uint64_t seq=s.attempted;ar_trace_emit(e,AR_TRACE_JOB,21,0,0,0,0);
    assert(!ar_trace_read(e,events,&s)&&s.attempted==seq);
    assert(ar_trace_configure(e,32,AR_TRACE_ALL)<0);
    printf("PASS attempted=%llu retained=%zu overwritten=%llu contentionDropped=%llu clockFailures=%llu bytes=%zu\n",
        (unsigned long long)s.attempted,s.count,(unsigned long long)s.overwritten,(unsigned long long)s.dropped,(unsigned long long)s.clock_failures,s.bytes);
    free(events);ar_engine_free(e);return 0;
}
