/* Compile the production callbacks in this translation unit, not mock parsers.
 * This target owns only Asyncara buffers; HTTP/TLS parsing remains libcurl's. */
#include "../../ext/backend.c"
#include <assert.h>
void *__real_malloc(size_t);
void *__real_calloc(size_t,size_t);
void *__real_realloc(void *,size_t);
static unsigned fail_at,calls;
static int fail(void) { return fail_at && ++calls == fail_at; }
void *__wrap_malloc(size_t n) { if(fail()){errno=ENOMEM;return NULL;}return __real_malloc(n); }
void *__wrap_calloc(size_t n,size_t s) { if(fail()){errno=ENOMEM;return NULL;}return __real_calloc(n,s); }
void *__wrap_realloc(void *p,size_t n) { if(fail()){errno=ENOMEM;return NULL;}return __real_realloc(p,n); }
int LLVMFuzzerTestOneInput(const unsigned char *data,size_t size) {
    if(size<2)return 0;
    fail_at=data[0];calls=0;
    ar_engine *e=ar_engine_new();if(!e)return 0;
    e->memory_limit=4096+(size_t)data[1]*1024;
    ar_job *j=ar_job_new(e,OP_HTTP);
    if(!j){ar_engine_free(e);return 0;}
    j->stream_response=j->stream_upload=1;
    char payload[AR_HTTP_STREAM_LIMIT],output[AR_HTTP_STREAM_LIMIT];
    memset(payload,0xA5,sizeof(payload));
    for(size_t i=2;i+2<size && i<386;i+=3) {
        size_t n=((size_t)data[i+1]+1)*256;
        switch(data[i]%8) {
        case 0: {
            size_t before=j->body_len,rc=receive_body(payload,1,n,j);
            if(rc==CURL_WRITEFUNC_PAUSE || !rc)assert(j->body_len==before);
            else assert(rc==n && j->body_len==before+n);
            break;
        }
        case 1: j->body_len=0;j->receive_paused=0;break;
        case 2: (void)receive_header(payload,1,n,j);break;
        case 3: atomic_store(&j->cancelled,data[i+2]&1);break;
        case 4:
            if(!j->upload)j->upload=ar_alloc(e,AR_HTTP_STREAM_LIMIT);
            if(j->upload){memcpy(j->upload,payload,n);j->upload_len=n;j->upload_offset=0;}
            break;
        case 5: {
            size_t before=j->upload_len-j->upload_offset;
            size_t rc=send_body(output,1,n,j);
            if(rc!=CURL_READFUNC_ABORT && rc!=CURL_READFUNC_PAUSE)assert(rc<=before && rc<=n);
            break;
        }
        case 6: j->upload_eof=data[i+2]&1;break;
        default:
            assert(receive_body(payload,SIZE_MAX,2,j)==0);
            assert(send_body(output,SIZE_MAX,2,j)==CURL_READFUNC_ABORT);
            break;
        }
        assert(j->body_len<=AR_HTTP_STREAM_LIMIT && j->headers_len<=65536);
        assert(j->upload_offset<=j->upload_len && j->upload_len<=AR_HTTP_STREAM_LIMIT);
    }
    ar_job_free(j);
    assert(!e->allocations && atomic_load(&e->memory_bytes)==0);
    fail_at=0;ar_engine_free(e);return 0;
}
