/* Independent libcurl control: no Asyncara/PHP, identical TLS/body/deadline. */
#include <curl/curl.h>
#include <stdint.h>
#include <stdio.h>
#include <time.h>
#include <string.h>
static size_t bytes;
static size_t body(char *data,size_t size,size_t count,void *arg) {
    (void)data;(void)arg;
    if(size && count>SIZE_MAX/size)return 0;
    size_t n=size*count;if(n>16777216-bytes)return 0;bytes+=n;return n;
}
static double now(clockid_t clock) {struct timespec t;clock_gettime(clock,&t);return t.tv_sec+t.tv_nsec/1e9;}
int main(int argc,char **argv) {
    if(argc!=5)return 2;
    if(curl_global_init(CURL_GLOBAL_DEFAULT))return 3;
    CURL *easy=curl_easy_init();CURLM *multi=curl_multi_init();if(!easy||!multi)return 4;
#define SET(o,v) do{if(curl_easy_setopt(easy,o,v)!=CURLE_OK)return 5;}while(0)
    SET(CURLOPT_URL,argv[1]);SET(CURLOPT_CAINFO,argv[2]);SET(CURLOPT_SSL_VERIFYPEER,1L);SET(CURLOPT_SSL_VERIFYHOST,2L);
    SET(CURLOPT_HTTP_VERSION,CURL_HTTP_VERSION_2TLS);SET(CURLOPT_NOSIGNAL,1L);SET(CURLOPT_TIMEOUT_MS,5000L);SET(CURLOPT_BUFFERSIZE,128L*1024L);SET(CURLOPT_WRITEFUNCTION,body);
    if(*argv[3]){SET(CURLOPT_PROXY,argv[3]);SET(CURLOPT_NOPROXY,"");SET(CURLOPT_PROXY_CAINFO,argv[4]);SET(CURLOPT_PROXY_SSL_VERIFYPEER,1L);SET(CURLOPT_PROXY_SSL_VERIFYHOST,2L);}
    double start=now(CLOCK_MONOTONIC),cpu=now(CLOCK_PROCESS_CPUTIME_ID);int running=0;
    if(curl_multi_add_handle(multi,easy)!=CURLM_OK)return 6;
    do{if(curl_multi_perform(multi,&running)!=CURLM_OK)return 7;if(running&&curl_multi_poll(multi,NULL,0,1000,NULL)!=CURLM_OK)return 8;}while(running);
    int left;CURLMsg *m=curl_multi_info_read(multi,&left);if(!m||m->msg!=CURLMSG_DONE)return 9;
    long version=0;curl_easy_getinfo(easy,CURLINFO_HTTP_VERSION,&version);
    printf("{\"curlCode\":%d,\"message\":\"%s\",\"bytes\":%zu,\"seconds\":%.9f,\"cpuSeconds\":%.9f,\"httpVersionEnum\":%ld,\"curlVersion\":\"%s\"}\n",(int)m->data.result,curl_easy_strerror(m->data.result),bytes,now(CLOCK_MONOTONIC)-start,now(CLOCK_PROCESS_CPUTIME_ID)-cpu,version,curl_version_info(CURLVERSION_NOW)->version);
    curl_multi_remove_handle(multi,easy);curl_easy_cleanup(easy);curl_multi_cleanup(multi);curl_global_cleanup();return 0;
}
