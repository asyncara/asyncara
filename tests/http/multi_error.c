#include "../../ext/native.h"
#include <string.h>
#include <stdio.h>
#include <assert.h>
/* Real multi setup/ownership; inject only the documented action error result. */
CURLMcode __real_curl_multi_socket_action(CURLM *,curl_socket_t,int,int *);
static int fail_action;
CURLMcode __wrap_curl_multi_socket_action(CURLM *m,curl_socket_t fd,int events,int *running) {
    if (fail_action) { fail_action=0; return CURLM_INTERNAL_ERROR; }
    return __real_curl_multi_socket_action(m,fd,events,running);
}
int main(void) {
    assert(curl_global_init(CURL_GLOBAL_DEFAULT)==CURLE_OK);
    ar_engine *e=ar_engine_new();assert(e);
    ar_job *j;
    for (int cycle=0;cycle<3;cycle++) {
        for(int n=0;n<8;n++){j=ar_job_new(e,OP_HTTP);assert(j);j->a=ar_strdup(e,"http://127.0.0.1:1/");assert(ar_submit(e,j)==0);}
        fail_action=1;assert(ar_wait(e,NULL,0,0)>=0);assert(!e->multi && !e->sockets);
        j=ar_collect(e);int count=0;while(j){ar_job *next=j->done_next;assert(strstr(j->error,"libcurl multi failed"));ar_job_free(j);j=next;count++;}
        assert(count==8 && e->pending==0 && !e->allocations && !atomic_load(&e->memory_bytes));
    }
    ar_engine_free(e);curl_global_cleanup();puts("PASS HTTP completion ownership, batched start, multi failure/recovery");
}
