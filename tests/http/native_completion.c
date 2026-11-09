#include "../../ext/backend.c"
#include <assert.h>
#include <arpa/inet.h>
#include <sys/wait.h>
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
    ar_job *j=ar_job_new(e,OP_HTTP);assert(j);
    char bytes[65536];memset(bytes,'a',sizeof(bytes));
    for (int i=0;i<32;i++)assert(receive_body(bytes,1,sizeof(bytes),j)==sizeof(bytes));
    assert(j->body_len==2097152 && !memcmp(j->body,bytes,sizeof(bytes)));
    ar_job_free(j);assert(!atomic_load(&e->memory_bytes));
    /* Real HTTP completion retains the original buffers on the job, without
     * constructing a generic native response tree. Collection frees once. */
    for (int backend=0;backend<2;backend++) {
        e->use_epoll=backend;
        int server=socket(AF_INET,SOCK_STREAM|SOCK_CLOEXEC,0);assert(server>=0);
        struct sockaddr_in addr={.sin_family=AF_INET,.sin_addr.s_addr=htonl(INADDR_LOOPBACK)};
        assert(!bind(server,(struct sockaddr *)&addr,sizeof(addr)) && !listen(server,8));
        socklen_t length=sizeof(addr);assert(!getsockname(server,(struct sockaddr *)&addr,&length));
        pid_t child=fork();assert(child>=0);
        if(!child){
            alarm(10);int peer=accept(server,NULL,NULL);assert(peer>=0);char request[4096];assert(read(peer,request,sizeof(request))>0);
            const char response[]="HTTP/1.1 200 OK\r\nContent-Length: 4\r\nConnection: close\r\n\r\nbody";
            assert(write(peer,response,sizeof(response)-1)==sizeof(response)-1);close(peer);close(server);_exit(0);
        }
        char url[80];snprintf(url,sizeof(url),"http://127.0.0.1:%u/",ntohs(addr.sin_port));
        j=ar_job_new(e,OP_HTTP);assert(j);j->a=ar_strdup(e,url);j->length=2000;assert(!ar_submit(e,j));
        for(int turn=0;!j->done && turn<20;turn++)assert(ar_wait(e,NULL,0,1000)>=0);
        assert(j->done && !j->error[0] && !j->result && j->body_len==4 && !memcmp(j->body,"body",4));
        char *original=j->body;assert(ar_collect(e)==j && j->body==original);ar_job_free(j);
        int status;assert(waitpid(child,&status,0)==child && WIFEXITED(status) && WEXITSTATUS(status)==0);close(server);
        assert(!e->pending && !atomic_load(&e->memory_bytes));
    }
    /* Empty first file, partial second-file reads and invalid part ordering
     * use the actual libcurl read callback and the same shared bounded slot. */
    j=ar_job_new(e,OP_HTTP);assert(j);j->upload_size=-1;
    ar_http_part parts[3]={{j,0},{j,1},{j,2}};char output[4];
    assert(send_part(output,1,4,&parts[0])==CURL_READFUNC_PAUSE);
    assert(send_part(output,1,4,&parts[1])==CURL_READFUNC_ABORT);
    j->upload_eof=1;assert(send_part(output,1,4,&parts[0])==0);
    assert(send_part(output,1,4,&parts[1])==CURL_READFUNC_PAUSE && j->upload_index==1 && !j->upload_eof);
    j->upload=ar_strdup(e,"abcdef");assert(j->upload);j->upload_len=6;j->upload_eof=1;
    assert(send_part(output,1,4,&parts[1])==4 && !memcmp(output,"abcd",4));
    assert(send_part(output,1,4,&parts[2])==CURL_READFUNC_ABORT);
    assert(send_part(output,1,4,&parts[1])==2 && !memcmp(output,"ef",2));
    assert(send_part(output,1,4,&parts[1])==0);
    assert(send_part(output,1,4,&parts[2])==CURL_READFUNC_PAUSE && j->upload_index==2);
    assert(send_part(output,1,4,&parts[1])==CURL_READFUNC_ABORT);
    atomic_store(&j->cancelled,1);assert(send_part(output,1,4,&parts[2])==CURL_READFUNC_ABORT);
    ar_job_free(j);assert(!atomic_load(&e->memory_bytes));
    for (int cycle=0;cycle<3;cycle++) {
        for(int n=0;n<8;n++){j=ar_job_new(e,OP_HTTP);assert(j);j->a=ar_strdup(e,"http://127.0.0.1:1/");assert(ar_submit(e,j)==0);}
        fail_action=1;assert(ar_wait(e,NULL,0,0)>=0);assert(!e->multi && !e->sockets);
        j=ar_collect(e);int count=0;while(j){ar_job *next=j->done_next;assert(strstr(j->error,"libcurl multi failed"));ar_job_free(j);j=next;count++;}
        assert(count==8 && e->pending==0 && !e->allocations && !atomic_load(&e->memory_bytes));
    }
    ar_engine_free(e);curl_global_cleanup();puts("PASS HTTP completion ownership, batched start, multi failure/recovery");
}
