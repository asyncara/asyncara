/* Isolated fault-enabled Rust library + real C ownership/wait paths. No PHP,
 * no user workload injection. Every case runs in its own process so a baseline
 * abort is retained rather than suppressing it or poisoning the next case. */
#include "../../ext/native.h"
#include <arpa/inet.h>
#include <assert.h>
#include <fcntl.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/socket.h>
#include <unistd.h>

extern void ar_hyper_test_fault(unsigned);
void __real_ar_hyper_free(void *);
static unsigned frees;
void __wrap_ar_hyper_free(void *ptr) { if (ptr) frees++; __real_ar_hyper_free(ptr); }

static ar_job *submit(ar_engine *e, const char *url) {
    ar_job *j = ar_job_new(e, OP_HTTP); assert(j);
    j->a = ar_strdup(e, url); j->length = 3000; assert(j->a);
    ar_value method = {.type = V_STRING, .string = "POST", .length = 4};
    ar_value *items[] = {&method}; char *keys[] = {"method"};
    ar_value options = {.type = V_ARRAY, .count = 1, .items = items, .keys = keys};
    if (!(j->id & 1)) {
        j->b = ar_strdup(e, "owned request body"); assert(j->b);
        j->b_len = strlen(j->b); j->params = &options;
    }
    /* Same synchronous borrowed option view as the PHP submission boundary. */
    assert(!ar_submit(e, j)); j->params = NULL; return j;
}
int main(int argc, char **argv) {
    assert(argc == 3);
    unsigned point = (unsigned)atoi(argv[1]); assert(point >= 11 && point <= 18);
    assert(curl_global_init(CURL_GLOBAL_DEFAULT) == CURLE_OK);
    int server = socket(AF_INET, SOCK_STREAM | SOCK_CLOEXEC | SOCK_NONBLOCK, 0); assert(server >= 0);
    struct sockaddr_in addr = {.sin_family = AF_INET, .sin_addr.s_addr = htonl(INADDR_LOOPBACK)};
    assert(!bind(server, (struct sockaddr *)&addr, sizeof(addr)) && !listen(server, 16));
    socklen_t length = sizeof(addr); assert(!getsockname(server, (struct sockaddr *)&addr, &length));
    char url[96]; snprintf(url, sizeof(url), "http://127.0.0.1:%u/", ntohs(addr.sin_port));
    ar_engine *e = ar_engine_new(); assert(e); e->hyper_requested = 1; e->use_epoll = atoi(argv[2]);
    ar_job *first = submit(e, url); submit(e, url); submit(e, url);
    unsigned expected = 3;
    int peers[3] = {-1,-1,-1};
    if (point == 16) {
        unsigned accepted = 0;
        for (unsigned tries = 0; accepted < 3 && tries < 100; tries++) {
            assert(ar_wait(e, NULL, 0, 0) >= 0);
            int fd;
            while (accepted < 3 && (fd = accept4(server, NULL, NULL, SOCK_NONBLOCK | SOCK_CLOEXEC)) >= 0)
                peers[accepted++] = fd;
        }
        assert(accepted == 3);
        const char response[] = "HTTP/1.1 200 OK\r\nContent-Length: 1\r\n\r\nx";
        for (unsigned i = 0; i < 3; i++) assert(send(peers[i], response, sizeof(response)-1, MSG_NOSIGNAL) == sizeof(response)-1);
    }
    ar_hyper_test_fault(point);
    if (point == 11) { submit(e, url); expected++; }
    else if (point == 13 || point == 18) ar_cancel(e, first->id);
    else if (point == 17) {
        size_t count = ar_hyper_connections(e->hyper);
        /* Same error branch as asyncara_info, without Zend allocation. */
        if (count == SIZE_MAX) ar_hyper_failed(e);
    } else assert(ar_wait(e, NULL, 0, 0) >= 0);
    assert(!e->hyper && frees == 1);
    unsigned seen = 0, count = 0;
    for (ar_job *j = ar_collect(e); j;) {
        ar_job *next = j->done_next;
        assert(j->id >= 1 && (unsigned)j->id <= expected);
        assert(!(seen & (1u << j->id))); seen |= 1u << j->id;
        assert(j->done);
        if (point == 18 && j->id == 1) assert(!strcmp(j->error, "Operation cancelled"));
        else assert(strstr(j->error, "Hyper engine failed"));
        count++; ar_job_free(j); j = next;
    }
    assert(count == expected && !e->pending && !e->jobs && !e->allocations);
    assert(!atomic_load(&e->memory_bytes));
    assert(e->hyper_submitted == 3 && e->hyper_completed == 3);
    /* Fresh state can be created after teardown; no poisoned object is reused. */
    ar_job *next = submit(e, url); assert(e->hyper && !next->done);
    ar_cancel(e, next->id); assert(ar_collect(e) == next);
    assert(!strcmp(next->error, "Operation cancelled")); ar_job_free(next);
    assert(!e->pending && !atomic_load(&e->memory_bytes));
    ar_engine_free(e); assert(frees == 2);
    for (unsigned i = 0; i < 3; i++) if (peers[i] >= 0) close(peers[i]);
    close(server); curl_global_cleanup();
    printf("PASS fault=%u backend=%s: one teardown, %u unique completions, zero owned bytes, fresh-engine recovery\n",
           point, atoi(argv[2]) ? "epoll" : "poll", expected);
}
