/* Real production ar_wait. Wrappers count kernel registrations and inject
 * one-shot failures; readiness and descriptor reuse use real Linux sockets. */
#include "native.h"
#include <assert.h>
#include <dirent.h>
#include <errno.h>
#include <poll.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/epoll.h>
#include <sys/socket.h>
#include <unistd.h>
static int creates, fail_create, fail_ctl, fail_wait, fail_alloc;
int __real_epoll_create1(int);
int __wrap_epoll_create1(int flags) {
    if (fail_create) { fail_create = 0; errno = EMFILE; return -1; }
    creates++; return __real_epoll_create1(flags);
}
int __real_epoll_ctl(int,int,int,struct epoll_event *);
int __wrap_epoll_ctl(int ep,int op,int fd,struct epoll_event *ev) {
    if (fail_ctl) { fail_ctl = 0; errno = ENOMEM; return -1; }
    return __real_epoll_ctl(ep,op,fd,ev);
}
int __real_epoll_wait(int,struct epoll_event *,int,int);
int __wrap_epoll_wait(int ep,struct epoll_event *ev,int n,int timeout) {
    if (fail_wait) { fail_wait = 0; errno = EINTR; return -1; }
    return __real_epoll_wait(ep,ev,n,timeout);
}
void *__real_calloc(size_t,size_t);
void *__wrap_calloc(size_t n,size_t size) {
    if (fail_alloc && !--fail_alloc) { errno = ENOMEM; return NULL; }
    return __real_calloc(n,size);
}
static int descriptors(void) {
    DIR *d = opendir("/proc/self/fd"); assert(d); int n = 0;
    while (readdir(d)) n++;
    assert(!closedir(d)); return n;
}
static void pair(int p[2]) { assert(!socketpair(AF_UNIX,SOCK_STREAM|SOCK_NONBLOCK|SOCK_CLOEXEC,0,p)); }
static void ready(ar_engine *e, ar_watch *w, int peer) {
    char b; assert(write(peer,"x",1)==1);
    assert(ar_wait(e,w,1,0)>0 && w->ready); assert(read(w->fd,&b,1)==1);
    assert(ar_wait(e,w,1,0)==0 && !w->ready);
}
int main(int argc,char **argv) {
    assert(!setenv("ASYNCARA_BACKEND","epoll",1));
    assert(!curl_global_init(CURL_GLOBAL_DEFAULT));
    if (argc>1 && !strcmp(argv[1],"--initialization")) {
        int before=descriptors();
        for (int allocation=1;allocation<=2;allocation++) {
            fail_alloc=allocation; assert(!ar_engine_new() && errno==ENOMEM);
            fail_alloc=0; assert(descriptors()==before);
        }
        fail_create=1; assert(!ar_engine_new() && errno==EMFILE);
        assert(descriptors()==before);
        ar_engine *valid=ar_engine_new(); assert(valid); ar_engine_free(valid);
        assert(descriptors()==before); curl_global_cleanup();
        puts("PASS initialization allocation/epoll failure cleanup"); return 0;
    }
    int initial = descriptors(), p[2]; pair(p);
    ar_engine *e = ar_engine_new(); assert(e); e->use_epoll = 1;
    ar_watch w = {.fd=p[0],.events=POLLIN};
    int iterations = argc>1 ? atoi(argv[1]) : 10000; assert(iterations>0);
    for (int i=0;i<iterations;i++) ready(e,&w,p[1]);
    int stable_creates = creates;
    /* Preserve all safety checks in a baseline comparison, even when the
     * baseline intentionally cannot satisfy the final reuse assertion. */
    w.events=POLLOUT; assert(ar_wait(e,&w,1,0)>0 && w.ready);
    w.events=POLLIN; assert(!ar_wait(e,&w,1,0) && !w.ready);
    int retained=dup(p[0]), replacement[2]; assert(retained>=0); pair(replacement);
    assert(!close(p[0])); assert(dup2(replacement[0],p[0])==p[0]); assert(!close(replacement[0]));
    ready(e,&w,replacement[1]);
    /* The old OFD stays alive through retained. Its readiness must not be
     * mistaken for the new socket that reuses exactly the same integer fd. */
    assert(write(p[1],"o",1)==1);
    assert(!ar_wait(e,&w,1,0) && !w.ready);
    char b; assert(read(retained,&b,1)==1 && b=='o');
    int extra[2]; pair(extra);
    ar_watch vector[2] = {w,{.fd=extra[0],.events=POLLIN}};
    assert(write(extra[1],"e",1)==1);
    assert(ar_wait(e,vector,2,0)>0 && !vector[0].ready && vector[1].ready);
    ar_watch swap=vector[0]; vector[0]=vector[1]; vector[1]=swap;
    assert(ar_wait(e,vector,2,0)>0 && vector[0].ready && !vector[1].ready);
    assert(read(extra[0],&b,1)==1);
    ready(e,&w,replacement[1]);
    fail_wait=1; assert(ar_wait(e,&w,1,0)==0); ready(e,&w,replacement[1]);
    fail_ctl=1; assert(ar_wait(e,&w,1,0)==-1 && errno==ENOMEM); ready(e,&w,replacement[1]);
    /* A changed vector must rebuild; failure must leave the engine reusable. */
    fail_create=1; assert(ar_wait(e,vector,2,0)==-1 && errno==EMFILE); ready(e,&w,replacement[1]);
    ar_watch invalid={.fd=-1,.events=POLLIN};
    assert(ar_wait(e,&invalid,1,0)==-1 && errno==EBADF); ready(e,&w,replacement[1]);
    int growth[8][2]; ar_watch growing[8];
    for(int i=0;i<8;i++) { pair(growth[i]); growing[i]=(ar_watch){.fd=growth[i][0],.events=POLLIN}; }
    for(int allocation=3;allocation<=4;allocation++) {
        fail_alloc=allocation; int rc=ar_wait(e,growing,8,0), saved=errno;
        /* Baseline has only three first-wait allocations. A non-injected
         * position is allowed here; the candidate covers both growth buffers. */
        int remaining=fail_alloc; fail_alloc=0;
        if (!remaining) assert(rc==-1 && saved==ENOMEM); else assert(rc==0);
        ready(e,&w,replacement[1]);
    }
    assert(!ar_wait(e,growing,8,0));
    for(int i=0;i<8;i++) assert(!close(growth[i][0]) && !close(growth[i][1]));
    ready(e,&w,replacement[1]);
    assert(atomic_load(&e->memory_bytes)==0); ar_engine_free(e);
    assert(write(replacement[1],"z",1)==1 && read(p[0],&b,1)==1 && b=='z');
    assert(!close(p[0]) && !close(p[1]) && !close(retained) && !close(replacement[1]));
    assert(!close(extra[0]) && !close(extra[1])); assert(descriptors()==initial);
    /* Probe every allocation in a first wait, including partial cache init.
     * Stop at the first allocation position beyond the actual call count. */
    int failures=0;
    for(int allocation=1;allocation<16;allocation++) {
        e=ar_engine_new(); assert(e); e->use_epoll=1; pair(p); w.fd=p[0];
        fail_alloc=allocation; int rc=ar_wait(e,&w,1,0), saved=errno, remaining=fail_alloc; fail_alloc=0;
        if (!remaining) { assert(rc==-1 && saved==ENOMEM); failures++; } else assert(rc==0);
        ready(e,&w,p[1]); ar_engine_free(e); assert(!close(p[0]) && !close(p[1]));
        assert(descriptors()==initial); if(remaining) break;
    }
    assert(failures>=3); curl_global_cleanup();
    printf("{\"safety\":\"PASS\",\"stableWaits\":%d,\"epollCreates\":%d,\"allocationFailures\":%d}\n",iterations*2,stable_creates,failures);
    if (stable_creates!=1) { fprintf(stderr,"reuse invariant failed: expected one epoll instance, got %d\n",stable_creates); return 2; }
    puts("PASS stable reuse, interest changes, dup-held fd reuse, vector changes, EINTR, failures, exact fd cleanup");
    return 0;
}
