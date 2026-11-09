/* Real readiness and completion queues. Override only curl's returned timer
 * budget to distinguish unrelated readiness from a due library timer. */
#include "../../ext/native.h"
#include <arpa/inet.h>
#include <assert.h>
#include <errno.h>
#include <poll.h>
#include <stdio.h>
#include <string.h>
#include <sys/socket.h>
#include <unistd.h>
static int timeout_actions, notifications, forced_timer = -1;
static ar_engine *engine;
CURLMcode __real_curl_multi_timeout(CURLM *, long *);
CURLMcode __wrap_curl_multi_timeout(CURLM *multi, long *timeout) {
    if (forced_timer >= 0) { *timeout = forced_timer; return CURLM_OK; }
    return __real_curl_multi_timeout(multi, timeout);
}
CURLMcode __real_curl_multi_socket_action(CURLM *, curl_socket_t, int, int *);
CURLMcode __wrap_curl_multi_socket_action(CURLM *m, curl_socket_t fd, int events, int *running) {
    if (fd == CURL_SOCKET_TIMEOUT) timeout_actions++;
    return __real_curl_multi_socket_action(m, fd, events, running);
}
ssize_t __real_write(int, const void *, size_t);
ssize_t __wrap_write(int fd, const void *bytes, size_t n) {
    if (engine && fd == engine->wake[1]) notifications++;
    return __real_write(fd, bytes, n);
}
int main(void) {
    assert(curl_global_init(CURL_GLOBAL_DEFAULT) == CURLE_OK);
    for (int backend=0; backend<2; backend++) {
        engine=ar_engine_new(); assert(engine); engine->use_epoll=backend;
        int server=socket(AF_INET, SOCK_STREAM|SOCK_CLOEXEC,0); assert(server>=0);
        struct sockaddr_in addr={.sin_family=AF_INET,.sin_addr.s_addr=htonl(INADDR_LOOPBACK)};
        assert(!bind(server,(struct sockaddr *)&addr,sizeof(addr)) && !listen(server,8));
        socklen_t len=sizeof(addr);assert(!getsockname(server,(struct sockaddr *)&addr,&len));
        char url[80];snprintf(url,sizeof(url),"http://127.0.0.1:%u/",ntohs(addr.sin_port));
        ar_job *job=ar_job_new(engine,OP_HTTP);assert(job);job->a=ar_strdup(engine,url);job->length=10000;assert(!ar_submit(engine,job));
        assert(ar_wait(engine,NULL,0,100)>=0);
        int peer=accept(server,NULL,NULL);assert(peer>=0);
        int pair[2];assert(!socketpair(AF_UNIX,SOCK_STREAM|SOCK_CLOEXEC,0,pair));
        assert(write(pair[1],"x",1)==1);
        ar_watch watch={.fd=pair[0],.events=POLLIN};
        forced_timer=1000;int before=timeout_actions;
        assert(ar_wait(engine,&watch,1,0)>0 && watch.ready);
        assert(timeout_actions==before); /* readiness is not a timer expiry */
        forced_timer=0;assert(ar_wait(engine,&watch,1,0)>0);
        assert(timeout_actions==before+1); /* a due timer is still dispatched */
        notifications=0;ar_cancel(engine,job->id);
        assert(job->done && notifications==0); /* interpreter cannot sleep past itself */
        assert(ar_wait(engine,NULL,0,-1)>=0);assert(ar_collect(engine)==job);ar_job_free(job);
        assert(!engine->pending && !atomic_load(&engine->memory_bytes));
        /* Worker completion still publishes a real wake byte. */
        job=ar_job_new(engine,OP_READ);assert(job);engine->jobs=job;engine->pending=1;
        ar_done(engine,job);assert(notifications==1);assert(ar_collect(engine)==job);ar_job_free(job);
        close(pair[0]);close(pair[1]);close(peer);close(server);
        ar_engine_free(engine);engine=NULL;forced_timer=-1;
    }
    curl_global_cleanup();puts("PASS HTTP due timer dispatch, local collection, worker wake, epoll/poll");
}
