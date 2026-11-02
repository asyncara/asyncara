#include <curl/curl.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>
typedef struct { size_t bytes; char body[14]; } response;
static size_t sink(char *p, size_t s, size_t n, void *ctx) {
    response *r = ctx;
    if (s && n > (sizeof(r->body) - r->bytes) / s) return 0;
    memcpy(r->body + r->bytes, p, s * n);
    r->bytes += s * n;
    return s * n;
}
static double now(void) {
    struct timespec t;
    clock_gettime(CLOCK_MONOTONIC, &t);
    return t.tv_sec + t.tv_nsec / 1e9;
}
static CURL *request(CURLM *multi, const char *url) {
    CURL *c = curl_easy_init();
    response *r = calloc(1, sizeof(*r));
    if (!c || !r) exit(1);
    curl_easy_setopt(c, CURLOPT_URL, url);
    curl_easy_setopt(c, CURLOPT_FORBID_REUSE, 1L);
    curl_easy_setopt(c, CURLOPT_WRITEFUNCTION, sink);
    curl_easy_setopt(c, CURLOPT_WRITEDATA, r);
    curl_easy_setopt(c, CURLOPT_PRIVATE, r);
    curl_easy_setopt(c, CURLOPT_TIMEOUT, 10L);
    curl_easy_setopt(c, CURLOPT_NOSIGNAL, 1L);
    curl_multi_add_handle(multi, c);
    return c;
}
static int compare(const void *a, const void *b) {
    double x = *(const double *)a, y = *(const double *)b;
    return (x > y) - (x < y);
}
int main(int argc, char **argv) {
    if (argc != 2)
        return 2;
    curl_global_init(CURL_GLOBAL_DEFAULT);
    printf("{\"mode\":\"native-curl-http\",\"samples\":[");
    for (int sample = 0; sample < 3; sample++) {
        CURLM *multi = curl_multi_init();
        int sent = 4, done = 0, running = 0;
        double lat[24], start = now();
        for (int i = 0; i < 4; i++)
            request(multi, argv[1]);
        while (done < 24) {
            curl_multi_perform(multi, &running);
            int remaining;
            CURLMsg *msg;
            while ((msg = curl_multi_info_read(multi, &remaining))) {
                if (msg->msg != CURLMSG_DONE)
                    continue;
                if (msg->data.result != CURLE_OK) {
                    fprintf(stderr, "curl baseline failed\n");
                    return 1;
                }
                long status;
                double seconds;
                curl_easy_getinfo(msg->easy_handle, CURLINFO_RESPONSE_CODE, &status);
                curl_easy_getinfo(msg->easy_handle, CURLINFO_TOTAL_TIME, &seconds);
                response *r;
                curl_easy_getinfo(msg->easy_handle, CURLINFO_PRIVATE, &r);
                if (status != 200 || r->bytes != 14 || memcmp(r->body, "hello asyncara", 14))
                    return 1;
                lat[done++] = seconds * 1000;
                curl_multi_remove_handle(multi, msg->easy_handle);
                curl_easy_cleanup(msg->easy_handle);
                free(r);
                if (sent < 24) {
                    request(multi, argv[1]);
                    sent++;
                }
            }
            if (done < 24) {
                int n;
                curl_multi_poll(multi, NULL, 0, 1000, &n);
            }
        }
        double elapsed = now() - start;
        qsort(lat, 24, sizeof(double), compare);
        printf("%s{\"seconds\":%.9f,\"operations\":24,\"operationsPerSecond\":%.3f,\"medianMs\":%.6f,"
               "\"p95Ms\":%.6f,\"p99Ms\":%.6f}",
               sample ? "," : "", elapsed, 24 / elapsed, lat[11], lat[21], lat[22]);
        curl_multi_cleanup(multi);
    }
    printf("]}\n");
    curl_global_cleanup();
    return 0;
}
