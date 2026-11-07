/* Test-only link wrappers; production ar_wait and real kernel readiness. */
#include "../../ext/native.h"
#include <assert.h>
#include <errno.h>
#include <limits.h>
#include <poll.h>
#include <stdio.h>
#include <stdlib.h>
#include <sys/epoll.h>
#include <sys/socket.h>
#include <time.h>
#include <unistd.h>
static int armed, seen, wanted, fail_clock, clock_reads;
static int64_t now_ns=INT64_C(123456789000000), allocation_delay, registration_delay;
int __real_clock_gettime(clockid_t,struct timespec*);
int __wrap_clock_gettime(clockid_t id,struct timespec *ts){
 if(!armed||id!=CLOCK_MONOTONIC)return __real_clock_gettime(id,ts);
 clock_reads++;
 if(fail_clock==clock_reads){errno=EIO;return -1;}
 ts->tv_sec=now_ns/1000000000;ts->tv_nsec=now_ns%1000000000;return 0;
}
void *__real_calloc(size_t,size_t);
void *__wrap_calloc(size_t n,size_t size){void *p=__real_calloc(n,size);if(armed){now_ns+=allocation_delay;allocation_delay=0;}return p;}
int __real_epoll_ctl(int,int,int,struct epoll_event*);
int __wrap_epoll_ctl(int ep,int op,int fd,struct epoll_event *ev){int rc=__real_epoll_ctl(ep,op,fd,ev);if(armed)now_ns+=registration_delay;return rc;}
static void check_timeout(int timeout){
 if(!armed)return;
 seen++;
 if(timeout!=wanted){fprintf(stderr,"wait budget restarted: expected=%d actual=%d clockReads=%d\n",wanted,timeout,clock_reads);exit(2);}
}
int __real_poll(struct pollfd*,nfds_t,int);
int __wrap_poll(struct pollfd *fds,nfds_t n,int t){check_timeout(t);return __real_poll(fds,n,t);}
int __real___poll_chk(struct pollfd*,nfds_t,int,size_t);
int __wrap___poll_chk(struct pollfd *fds,nfds_t n,int t,size_t b){check_timeout(t);return __real___poll_chk(fds,n,t,b);}
int __real_epoll_wait(int,struct epoll_event*,int,int);
int __wrap_epoll_wait(int fd,struct epoll_event *ev,int n,int t){check_timeout(t);return __real_epoll_wait(fd,ev,n,t);}
static void trial(ar_engine *e,int requested,int64_t delay,int64_t registration,int expected){
 int pair[2];assert(!socketpair(AF_UNIX,SOCK_STREAM|SOCK_NONBLOCK|SOCK_CLOEXEC,0,pair));
 /* Ready socket makes infinite/INT_MAX controls safe while retaining syscall. */
 assert(write(pair[1],"x",1)==1);
 ar_watch watch={.fd=pair[0],.events=POLLIN};
 allocation_delay=delay;registration_delay=registration;wanted=expected;seen=clock_reads=0;armed=1;
 int rc=ar_wait(e,&watch,1,requested);armed=0;
 assert(rc>0&&watch.ready&&seen==1);assert(close(pair[0])==0&&close(pair[1])==0);
}
int main(int argc,char **argv){
 int iterations=argc>1?atoi(argv[1]):10000;assert(iterations>0);assert(curl_global_init(CURL_GLOBAL_DEFAULT)==CURLE_OK);
 for(int backend=0;backend<2;backend++){
  ar_engine *e=ar_engine_new();assert(e);e->use_epoll=backend;
  for(int i=0;i<iterations;i++){
   trial(e,5,20000000,0,0); /* elapsed preparation exhausts caller's budget */
   trial(e,5,4200000,0,1); /* round UP residual 0.8ms, never fire early */
   trial(e,5,0,backend?3000000:0,backend?0:5); /* epoll registration consumes budget */
   trial(e,0,20000000,0,0);trial(e,-1,20000000,0,-1);
   trial(e,INT_MAX,20000000,0,INT_MAX-20);
  }
  armed=fail_clock=1;seen=clock_reads=0;errno=0;assert(ar_wait(e,NULL,0,5)==-1&&errno==EIO&&seen==0);armed=fail_clock=0;
  /* A failed second clock read occurs after buffers/epoll setup. The next
   * real wait and engine cleanup must remain usable. */
  armed=1;fail_clock=2;seen=clock_reads=0;errno=0;
  assert(ar_wait(e,NULL,0,5)==-1&&errno==EIO&&seen==0);armed=fail_clock=0;
  trial(e,5,0,0,5);
  int64_t saved=now_ns;now_ns=INT64_MAX-1;armed=1;clock_reads=0;errno=0;
  assert(ar_wait(e,NULL,0,INT_MAX)==-1&&errno==EOVERFLOW);armed=0;now_ns=saved;
  assert(atomic_load(&e->memory_bytes)==0);ar_engine_free(e);
  printf("{\"status\":\"PASS\",\"backend\":\"%s\",\"kernelWaits\":%d,\"iterations\":%d}\n",backend?"epoll":"poll",iterations*6,iterations);
 }
 curl_global_cleanup();return 0;
}
