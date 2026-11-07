/* Identical real pipe readiness workload for baseline/candidate. No mock wait. */
#include "../../ext/native.h"
#include <assert.h>
#include <errno.h>
#include <fcntl.h>
#include <poll.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>
#include <unistd.h>
static uint64_t ns(void){struct timespec t;assert(!clock_gettime(CLOCK_MONOTONIC,&t));return (uint64_t)t.tv_sec*1000000000+t.tv_nsec;}
void *__real_calloc(size_t,size_t);
static int allocation_countdown;
void *__wrap_calloc(size_t n,size_t size){
    if(allocation_countdown && !--allocation_countdown){errno=ENOMEM;return NULL;}
    return __real_calloc(n,size);
}
int main(int argc,char **argv){
    ar_engine *e=ar_engine_new();assert(e);
    if(argc>1&&!strcmp(argv[1],"--allocation")){
        int pair[2];assert(!pipe2(pair,O_NONBLOCK|O_CLOEXEC));
        ar_watch watches[64];
        for(size_t i=0;i<64;i++)watches[i]=(ar_watch){.fd=pair[0],.events=POLLIN,.key=i};
        for(int backend=0;backend<=1;backend++){
            e->use_epoll=backend;
            for(int allocation=1;allocation<=2;allocation++){
                allocation_countdown=allocation;
                assert(ar_wait(e,watches,64,0)==-1&&errno==ENOMEM);
                assert(!allocation_countdown);
                assert(ar_wait(e,watches,64,0)==0);
            }
        }
        assert(!close(pair[0])&&!close(pair[1]));
        ar_engine_free(e);puts("PASS watcher index allocation failure and recovery");return 0;
    }
    if(argc>1&&!strcmp(argv[1],"--bounds")){
        assert(ar_wait(e,NULL,AR_MAX_WATCHERS,0)==-1&&errno==EOVERFLOW);
        assert(ar_wait(e,NULL,SIZE_MAX,0)==-1&&errno==EOVERFLOW);
        ar_engine_free(e);puts("PASS watcher bounds before dereference/arithmetic");return 0;
    }
    int n=argc>1?atoi(argv[1]):2048,repeats=argc>2?atoi(argv[2]):20,backend=argc>3?atoi(argv[3]):1;
    assert(n>0&&n<=8191&&repeats>0);e->use_epoll=backend;
    int (*pipes)[2]=calloc(n,sizeof(*pipes));ar_watch *w=calloc((size_t)n*2,sizeof(*w));assert(pipes&&w);
    for(int i=0;i<n;i++){
        assert(!pipe2(pipes[i],O_NONBLOCK|O_CLOEXEC));if(i%2)assert(write(pipes[i][1],"x",1)==1);
        w[2*i]=(ar_watch){.fd=pipes[i][0],.events=POLLIN,.key=i};
        w[2*i+1]=(ar_watch){.fd=pipes[i][0],.events=POLLOUT,.key=i};
    }
    unsigned seed=104729;
    for(int i=2*n-1;i>0;i--){seed=seed*1664525u+1013904223u;int j=(int)(seed%(unsigned)(i+1));ar_watch tmp=w[i];w[i]=w[j];w[j]=tmp;}
    uint64_t start=ns();
    for(int r=0;r<repeats;r++){
        assert(ar_wait(e,w,(size_t)n*2,0)>=0);
        for(int i=0;i<2*n;i++)assert(!!w[i].ready==(w[i].events==POLLIN&&(w[i].key%2)));
    }
    uint64_t elapsed=ns()-start;
    for(int i=0;i<n;i++){assert(!close(pipes[i][0]));assert(!close(pipes[i][1]));}
    free(pipes);free(w);assert(atomic_load(&e->memory_bytes)==0);ar_engine_free(e);
    printf("{\"status\":\"PASS\",\"backend\":\"%s\",\"uniqueDescriptors\":%d,\"watchers\":%d,\"waits\":%d,\"nanoseconds\":%llu}\n",backend?"epoll":"poll",n,n*2,repeats,(unsigned long long)elapsed);
}
