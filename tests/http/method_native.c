/* Direct C callers also reject HEAD uploads before curl multi registration. */
#include "../../ext/native.h"
#include <assert.h>
#include <stdio.h>
#include <string.h>
static unsigned registrations;
CURLMcode __real_curl_multi_add_handle(CURLM *, CURL *);
CURLMcode __wrap_curl_multi_add_handle(CURLM *multi, CURL *easy) {
    registrations++;
    return __real_curl_multi_add_handle(multi, easy);
}
int main(void) {
    assert(curl_global_init(CURL_GLOBAL_DEFAULT) == CURLE_OK);
    ar_engine *e = ar_engine_new(); assert(e);
    for (int i = 0; i < 1000; i++) {
        ar_job *j = ar_job_new(e, OP_HTTP); assert(j);
        j->a = ar_strdup(e, "http://127.0.0.1:9/not-sent"); assert(j->a);
        j->params = ar_value_new(e, V_ARRAY); assert(j->params);
        assert(!ar_add(j->params, "method", ar_string(e, "HEAD", 4)));
        if (i % 2) { j->b = ar_strdup(e, "x"); j->b_len = 1; assert(j->b); }
        else { j->stream_upload = 1; j->upload_size = i % 3 ? -1 : 0; }
        assert(!ar_submit(e, j));
        if (!j->done || !strstr(j->error, "HEAD") || registrations != 0) {
            fputs("FAIL HEAD upload reached curl multi instead of being rejected\n", stderr);
            ar_cancel(e, j->id); assert(ar_collect(e) == j); ar_job_free(j);
            ar_engine_free(e); curl_global_cleanup(); return 2;
        }
        assert(ar_collect(e) == j); ar_job_free(j);
        assert(!e->pending && !atomic_load(&e->memory_bytes));
    }
    ar_engine_free(e); curl_global_cleanup();
    puts("PASS 1000 native HEAD rejections, zero multi registrations, exact completion cleanup");
}
