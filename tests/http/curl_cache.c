/* Exercise the actual collection/cache owner with a retained-buffer rejection.
 * The real HTTP/2 fixture separately reproduces this without fault injection. */
#include "../../ext/native.h"
#include <assert.h>
#include <stdarg.h>
#include <stdio.h>
static CURLcode probe_result;
static unsigned probes, cleanups;
CURLcode __real_curl_easy_setopt(CURL *, CURLoption, ...);
CURLcode __wrap_curl_easy_setopt(CURL *easy, CURLoption option, ...) {
    assert(option == CURLOPT_BUFFERSIZE);
    va_list args; va_start(args, option); long size = va_arg(args, long); va_end(args);
    assert(size == 0L); probes++;
    return probe_result ? probe_result : __real_curl_easy_setopt(easy, option, size);
}
void __real_curl_easy_cleanup(CURL *);
void __wrap_curl_easy_cleanup(CURL *easy) { cleanups++; __real_curl_easy_cleanup(easy); }
int main(void) {
    assert(curl_global_init(CURL_GLOBAL_DEFAULT) == CURLE_OK);
    ar_engine *engine = ar_engine_new(); assert(engine);
    for (int i = 0; i < 200; i++) {
        ar_job *job = ar_job_new(engine, OP_HTTP); assert(job);
        job->curl = curl_easy_init(); assert(job->curl);
        probe_result = i % 2 ? CURLE_BAD_FUNCTION_ARGUMENT : CURLE_OUT_OF_MEMORY;
        unsigned before = cleanups;
        ar_job_free(job);
        if (engine->http_idle_count || cleanups != before + 1 || probes != (unsigned)i + 1) {
            fputs("FAIL completed easy handle cached despite unverified receive-buffer reset\n", stderr);
            ar_engine_free(engine); curl_global_cleanup(); return 2;
        }
        assert(!engine->allocations && !atomic_load(&engine->memory_bytes));
    }
    probe_result = CURLE_OK;
    ar_job *job = ar_job_new(engine, OP_HTTP); assert(job);
    job->curl = curl_easy_init(); assert(job->curl); ar_job_free(job);
    assert(engine->http_idle_count == 1 && probes == 201 && cleanups == 200);
    ar_engine_free(engine); assert(cleanups == 201); curl_global_cleanup();
    puts("PASS 200 rejected cache admissions, reusable handle retained, exact release");
}
