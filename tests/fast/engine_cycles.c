/* Operation-count request-engine teardown, using real native workers. */
#include "../../ext/native.h"
#include <assert.h>
#include <dirent.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>
static int entries(const char *path){DIR *d=opendir(path);assert(d);int n=0;struct dirent *p;while((p=readdir(d)))if(strcmp(p->d_name,".")&&strcmp(p->d_name,".."))n++;assert(!closedir(d));return n;}
static long rss(void){FILE *f=fopen("/proc/self/status","r");assert(f);char line[256];long n=0;while(fgets(line,sizeof(line),f))if(sscanf(line,"VmRSS: %ld",&n)==1)break;assert(!fclose(f));return n;}
int main(int argc,char **argv){
 int n=argc>1?atoi(argv[1]):10000;assert(n>0);assert(curl_global_init(CURL_GLOBAL_DEFAULT)==CURLE_OK);
 int baseline=entries("/proc/self/fd");struct timespec start;assert(!clock_gettime(CLOCK_MONOTONIC,&start));
 for(int backend=0;backend<2;backend++)for(int i=0;i<n;i++){
  ar_engine *e=ar_engine_new();assert(e);e->use_epoll=backend;
  ar_job *j=ar_job_new(e,OP_READ);assert(j);j->a=ar_strdup(e,"/dev/null");assert(j->a);assert(!ar_submit(e,j));assert(e->thread_count==4);
  if(i%3==0)ar_cancel(e,j->id);
  if(i%2){
   ar_job *done=NULL;for(int attempt=0;attempt<100&&!done;attempt++){assert(ar_wait(e,NULL,0,100)>=0);done=ar_collect(e);}
   assert(done==j&&!done->done_next);assert(!e->pending&&!e->jobs);ar_job_free(done);assert(!atomic_load(&e->memory_bytes));
  }
  // Even cycles intentionally shut down before collecting the pending job.
  // ar_engine_free cancels, joins workers and checks its native ledger itself.
  ar_engine_free(e);
  assert(entries("/proc/self/fd")==baseline);
  if((i+1)%1000==0||i+1==n){
   // Linux may briefly retain an exiting task directory after pthread_join.
   // Descriptor/ledger checks are strict; task-directory observations are raw.
   printf("{\"backend\":\"%s\",\"engineCycles\":%d,\"workerStarts\":%d,\"fds\":%d,\"observedTaskDirectories\":%d,\"rssKiB\":%ld}\n",backend?"epoll":"poll",i+1,4*(i+1),baseline,entries("/proc/self/task"),rss());fflush(stdout);
  }
 }
 struct timespec end;assert(!clock_gettime(CLOCK_MONOTONIC,&end));curl_global_cleanup();
 printf("{\"status\":\"PASS\",\"cycles\":%d,\"workerStarts\":%d,\"wallSeconds\":%.6f}\n",2*n,8*n,(double)(end.tv_sec-start.tv_sec)+(end.tv_nsec-start.tv_nsec)/1e9);return 0;
}
