/* Production ar_wait/ar_done, real epoll/poll, test-only link boundary control. */
#include "../../ext/native.h"
#include <assert.h>
#include <errno.h>
#include <poll.h>
#include <signal.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/epoll.h>
#include <sys/socket.h>
#include <sys/time.h>
#include <unistd.h>
static ar_engine *engine;
static ar_job *publishing;
static pthread_mutex_t mutex=PTHREAD_MUTEX_INITIALIZER;
static pthread_cond_t condition=PTHREAD_COND_INITIALIZER;
static int requested,finished,stop,action;
static atomic_int interrupted_writes;
static int actual_eintr;
static unsigned long long kernel_calls, injected_waits;
static volatile sig_atomic_t signals;
ssize_t __real_write(int,const void*,size_t);
ssize_t __wrap_write(int fd,const void *p,size_t n){
 if(engine&&fd==engine->wake[1]&&atomic_load(&interrupted_writes)>0){atomic_fetch_sub(&interrupted_writes,1);errno=EINTR;return -1;}
 return __real_write(fd,p,n);
}
static void *publisher(void *unused){
 (void)unused;sigset_t set;sigemptyset(&set);sigaddset(&set,SIGALRM);assert(!pthread_sigmask(SIG_BLOCK,&set,NULL));
 assert(!pthread_mutex_lock(&mutex));
 for(;;){while(!requested&&!stop)assert(!pthread_cond_wait(&condition,&mutex));if(stop)break;
  requested=0;ar_job *job=publishing;assert(!pthread_mutex_unlock(&mutex));ar_done(engine,job);
  assert(!pthread_mutex_lock(&mutex));finished=1;assert(!pthread_cond_broadcast(&condition));
 }assert(!pthread_mutex_unlock(&mutex));return NULL;
}
static int boundary(void){
 int selected=action;action=0;
 if(selected==1){assert(!pthread_mutex_lock(&mutex));finished=0;requested=1;assert(!pthread_cond_broadcast(&condition));while(!finished)assert(!pthread_cond_wait(&condition,&mutex));assert(!pthread_mutex_unlock(&mutex));}
 if(selected==2){errno=EINTR;return 1;}
 if(selected==3){struct itimerval timer={.it_value={.tv_usec=1000}};assert(!setitimer(ITIMER_REAL,&timer,NULL));}
 return 0;
}
int __real_poll(struct pollfd*,nfds_t,int);
int __wrap_poll(struct pollfd *fds,nfds_t n,int timeout){if(boundary()){injected_waits++;return -1;}kernel_calls++;int r=__real_poll(fds,n,timeout);if(r<0&&errno==EINTR)actual_eintr++;return r;}
/* Fortified libc headers can redirect production poll to __poll_chk. Retain
 * that bounds check and the real syscall; control both linker entry points. */
int __real___poll_chk(struct pollfd*,nfds_t,int,size_t);
int __wrap___poll_chk(struct pollfd *fds,nfds_t n,int timeout,size_t bytes){if(boundary()){injected_waits++;return -1;}kernel_calls++;int r=__real___poll_chk(fds,n,timeout,bytes);if(r<0&&errno==EINTR)actual_eintr++;return r;}
int __real_epoll_wait(int,struct epoll_event*,int,int);
int __wrap_epoll_wait(int fd,struct epoll_event *ev,int n,int timeout){if(boundary()){injected_waits++;return -1;}kernel_calls++;int r=__real_epoll_wait(fd,ev,n,timeout);if(r<0&&errno==EINTR)actual_eintr++;return r;}
static void handler(int number){(void)number;signals++;}
static void completion(int iterations){
 for(int i=0;i<iterations;i++){
  ar_job *job=ar_job_new(engine,OP_READ);assert(job);
  /* A submitted completion owner: no fake wait backend or substitute queue. */
  engine->jobs=job;engine->pending=1;publishing=job;
  atomic_store(&interrupted_writes,1+(i%3));action=1;
  int rc=ar_wait(engine,NULL,0,20);
  assert(!action&&finished&&job->done); // prove injection reached production publication
  if(rc<=0){fprintf(stderr,"Lost completion wakeup: iteration=%d backend=%s rc=%d remainingInjectedEINTR=%d\n",i,engine->use_epoll?"epoll":"poll",rc,atomic_load(&interrupted_writes));exit(2);}
  assert(ar_collect(engine)==job);assert(!engine->pending&&!engine->jobs&&!ar_collect(engine));ar_job_free(job);
  assert(atomic_load(&engine->memory_bytes)==0);
 }
 // Full nonblocking pipe is already readable: EAGAIN must not spin or fail.
 char bytes[4096]={0};while(__real_write(engine->wake[1],bytes,sizeof(bytes))>0){}assert(errno==EAGAIN);
 ar_job *job=ar_job_new(engine,OP_READ);assert(job);engine->jobs=job;engine->pending=1;publishing=job;action=1;
 assert(ar_wait(engine,NULL,0,20)>0);assert(ar_collect(engine)==job);ar_job_free(job);
}
static void descriptors(int iterations){
 for(int i=0;i<iterations;i++){
  int pair[2];assert(!socketpair(AF_UNIX,SOCK_STREAM|SOCK_NONBLOCK|SOCK_CLOEXEC,0,pair));
  ar_watch watches[2]={{.fd=pair[0],.events=POLLIN},{.fd=pair[0],.events=POLLOUT}};
  assert(ar_wait(engine,watches,2,0)>=0);assert(!watches[0].ready&&watches[1].ready);
  assert(write(pair[1],"xy",2)==2);assert(ar_wait(engine,watches,2,20)>0&&watches[0].ready&&watches[1].ready);
  char byte;assert(read(pair[0],&byte,1)==1&&byte=='x');assert(ar_wait(engine,watches,1,0)>0&&watches[0].ready);assert(read(pair[0],&byte,1)==1&&byte=='y');
  int old=pair[0];assert(!close(pair[0])&&!close(pair[1]));
  assert(!socketpair(AF_UNIX,SOCK_STREAM|SOCK_NONBLOCK|SOCK_CLOEXEC,0,pair));
  if(pair[0]!=old){assert(pair[1]!=old);assert(dup2(pair[0],old)==old);assert(!close(pair[0]));pair[0]=old;}
  watches[0].fd=old;assert(ar_wait(engine,watches,1,0)>=0&&!watches[0].ready);
  assert(write(pair[1],"z",1)==1);assert(ar_wait(engine,watches,1,20)>0&&watches[0].ready);assert(read(old,&byte,1)==1&&byte=='z');
  assert(!close(pair[1]));assert(ar_wait(engine,watches,1,20)>0&&watches[0].ready);assert(!close(old));
  action=2;assert(ar_wait(engine,NULL,0,1000)==0); // injected syscall EINTR path
 }
 struct sigaction sa={0},old;sa.sa_handler=handler;sigemptyset(&sa.sa_mask);assert(!sigaction(SIGALRM,&sa,&old));
 actual_eintr=0;
 for(int attempt=0;attempt<10&&!actual_eintr;attempt++){action=3;assert(ar_wait(engine,NULL,0,20)==0);}
 struct itimerval clear={0};assert(!setitimer(ITIMER_REAL,&clear,NULL));assert(!sigaction(SIGALRM,&old,NULL));
 assert(actual_eintr>0&&signals>0); // separate real kernel interruption evidence
}
int main(int argc,char **argv){
 int n=argc>1?atoi(argv[1]):10000;const char *selection=argc>2?argv[2]:"all";assert(n>0);
 assert(curl_global_init(CURL_GLOBAL_DEFAULT)==CURLE_OK);
 for(int backend=0;backend<2;backend++){
  if(argc>3&&backend!=atoi(argv[3]))continue;
  kernel_calls=injected_waits=0;engine=ar_engine_new();assert(engine);engine->use_epoll=backend;stop=0;requested=finished=0;
  pthread_t thread;assert(!pthread_create(&thread,NULL,publisher,NULL));
  if(strcmp(selection,"kernel"))completion(n);
  if(strcmp(selection,"wakeup"))descriptors(n);
  assert(!pthread_mutex_lock(&mutex));stop=1;assert(!pthread_cond_broadcast(&condition));assert(!pthread_mutex_unlock(&mutex));assert(!pthread_join(thread,NULL));
  assert(atomic_load(&engine->memory_bytes)==0);ar_engine_free(engine);engine=NULL;
  printf("{\"status\":\"PASS\",\"backend\":\"%s\",\"iterationsPerPath\":%d,\"selection\":\"%s\",\"actualKernelEINTR\":%d,\"realKernelWaitCalls\":%llu,\"injectedWaitEINTR\":%llu}\n",backend?"epoll":"poll",n,selection,actual_eintr,kernel_calls,injected_waits);
 }
 curl_global_cleanup();return 0;
}
