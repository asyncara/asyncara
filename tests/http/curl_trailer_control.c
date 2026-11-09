/* Dependency-only leak reproducer. No Asyncara or PHP code is linked.
 * Run against transfer_geometry_server.py /chunked/1 under Valgrind.
 * A second argument of1 disables connection reuse as a differential control;
 * this is not a production Asyncara workaround or a throughput comparison. */
#include <curl/curl.h>
#include <assert.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
static size_t bodies, trailers;
static size_t body(char *p, size_t s, size_t n, void *ctx) {
    (void)ctx; assert(s * n == 1 && p[0] == '\0'); bodies++; return s * n;
}
static size_t header(char *p, size_t s, size_t n, void *ctx) {
    (void)ctx;
    if (s * n == 12 && !memcmp(p, "X-End: yes\r\n", 12)) trailers++;
    return s * n;
}
int main(int argc, char **argv) {
    assert(argc == 3); assert(!curl_global_init(CURL_GLOBAL_DEFAULT));
    CURL *easy = curl_easy_init(); assert(easy);
    assert(!curl_easy_setopt(easy, CURLOPT_URL, argv[1]));
    assert(!curl_easy_setopt(easy, CURLOPT_PROXY, ""));
    assert(!curl_easy_setopt(easy, CURLOPT_WRITEFUNCTION, body));
    assert(!curl_easy_setopt(easy, CURLOPT_HEADERFUNCTION, header));
    assert(!curl_easy_setopt(easy, CURLOPT_TIMEOUT_MS, 3000L));
    assert(!curl_easy_setopt(easy, CURLOPT_FORBID_REUSE, (long)atoi(argv[2])));
    for (unsigned i = 0; i < 10; i++) assert(!curl_easy_perform(easy));
    assert(bodies == 10 && trailers == 10);
    printf("curl=%s requests=%zu trailers=%zu forbid_reuse=%s\n", curl_version(), bodies, trailers, argv[2]);
    curl_easy_cleanup(easy); curl_global_cleanup();
}
