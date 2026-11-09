/* Cost control, not public HTTP throughput: real curl requests remain pending
 * while an independent socket wakes the production readiness path. */
#include "../../ext/native.h"
#include <arpa/inet.h>
#include <assert.h>
#include <poll.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/resource.h>
#include <sys/socket.h>
#include <sys/wait.h>
#include <time.h>
#include <unistd.h>
static double seconds(void) {
    struct timespec t; assert(!clock_gettime(CLOCK_MONOTONIC, &t));
    return t.tv_sec + t.tv_nsec / 1e9;
}
static double cpu(void) {
    struct rusage u; assert(!getrusage(RUSAGE_SELF, &u));
    return u.ru_utime.tv_sec + u.ru_stime.tv_sec + (u.ru_utime.tv_usec + u.ru_stime.tv_usec) / 1e6;
}
int main(int argc, char **argv) {
    assert(argc == 3); int epoll = atoi(argv[1]), iterations = atoi(argv[2]);
    assert((epoll == 0 || epoll == 1) && iterations > 0 && iterations <= 20000);
    enum { CONNECTIONS = 256, JOBS = 1024 };
    assert(curl_global_init(CURL_GLOBAL_DEFAULT) == CURLE_OK);
    int server = socket(AF_INET, SOCK_STREAM | SOCK_CLOEXEC, 0); assert(server >= 0);
    struct sockaddr_in addr = {.sin_family=AF_INET,.sin_addr.s_addr=htonl(INADDR_LOOPBACK)};
    assert(!bind(server, (struct sockaddr *)&addr, sizeof(addr)) && !listen(server, CONNECTIONS));
    socklen_t len = sizeof(addr); assert(!getsockname(server, (struct sockaddr *)&addr, &len));
    int control[2]; assert(!socketpair(AF_UNIX, SOCK_STREAM | SOCK_CLOEXEC, 0, control));
    pid_t child = fork(); assert(child >= 0);
    if (!child) {
        alarm(120); close(control[0]); int peers[CONNECTIONS];
        for (int i=0; i<CONNECTIONS; i++) {
            peers[i]=accept(server, NULL, NULL); assert(peers[i]>=0);
            char request[4096]; size_t used=0;
            do {
                ssize_t n=read(peers[i], request+used, sizeof(request)-used-1);
                assert(n>0); used+=(size_t)n; request[used]=0;
            } while (!strstr(request,"\r\n\r\n") && used<sizeof(request)-1);
            assert(strstr(request,"\r\n\r\n"));
        }
        assert(write(control[1],"R",1)==1);
        char finish; assert(read(control[1],&finish,1)==1 && finish=='F');
        for (int i=0;i<CONNECTIONS;i++) close(peers[i]);
        close(server); close(control[1]); _exit(0);
    }
    close(control[1]); close(server);
    ar_engine *e=ar_engine_new(); assert(e); e->use_epoll=epoll;
    e->http_max_connections=e->http_max_host_connections=CONNECTIONS;
    char url[80]; snprintf(url,sizeof(url),"http://127.0.0.1:%u/",ntohs(addr.sin_port));
    long ids[JOBS];
    for (int i=0;i<JOBS;i++) {
        ar_job *j=ar_job_new(e,OP_HTTP); assert(j); ids[i]=j->id;
        j->a=ar_strdup(e,url); j->length=300000; assert(j->a && !ar_submit(e,j));
    }
    ar_watch ready={.fd=control[0],.events=POLLIN};
    double deadline=seconds()+20;
    while (!ready.ready && seconds()<deadline) assert(ar_wait(e,&ready,1,1000)>=0);
    assert(ready.ready && e->pending==JOBS && !e->done_head);
    int sockets=0; for(ar_socket *s=e->sockets;s;s=s->next)sockets++;
    assert(sockets==CONNECTIONS);
    for(int i=0;i<100;i++)assert(ar_wait(e,&ready,1,0)>0 && ready.ready);
    double start=seconds(), before=cpu();
    for(int i=0;i<iterations;i++)assert(ar_wait(e,&ready,1,0)>0 && ready.ready);
    double cost=cpu()-before, wall=seconds()-start;
    assert(!e->done_head && e->pending==JOBS);
    for(int i=0;i<JOBS;i++)ar_cancel(e,ids[i]);
    ar_job *j=ar_collect(e); int collected=0;
    while(j){ar_job *next=j->done_next;assert(j->done && j->error[0]);ar_job_free(j);j=next;collected++;}
    assert(collected==JOBS && !e->pending && !atomic_load(&e->memory_bytes));
    assert(write(control[0],"F",1)==1);close(control[0]);
    int status;assert(waitpid(child,&status,0)==child && WIFEXITED(status) && !WEXITSTATUS(status));
    ar_engine_free(e);curl_global_cleanup();
    printf("{\"backend\":\"%s\",\"waits\":%d,\"pendingJobs\":%d,\"sockets\":%d,\"wallSeconds\":%.9f,\"cpuSeconds\":%.9f,\"collected\":%d}\n",epoll?"epoll":"poll",iterations,JOBS,sockets,wall,cost,collected);
}
