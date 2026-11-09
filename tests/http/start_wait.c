/* Kernel timeout observed at the real production boundary. Buffered admission
 * must not manufacture an empty scheduler turn; streaming still needs prompt
 * producer/consumer dispatch. Actual loopback TCP, no mock readiness. */
#include "../../ext/native.h"
#include <arpa/inet.h>
#include <assert.h>
#include <poll.h>
#include <stdio.h>
#include <string.h>
#include <sys/epoll.h>
#include <time.h>
#include <unistd.h>
static int requested = -2;
static int logical_clock;
static struct timespec instant;
int __real_clock_gettime(clockid_t, struct timespec *);
int __wrap_clock_gettime(clockid_t clock, struct timespec *value) {
    /* Optional arithmetic control for memory instrumentation: startup under
     * Valgrind may legitimately exhaust the real 50ms preparation budget.
     * Keep the real-kernel default separate; no production clock is changed. */
    if (logical_clock && clock == CLOCK_MONOTONIC) { *value = instant; return 0; }
    return __real_clock_gettime(clock, value);
}
int __real_epoll_wait(int, struct epoll_event *, int, int);
int __wrap_epoll_wait(int fd, struct epoll_event *events, int count, int timeout) {
    requested = timeout; return __real_epoll_wait(fd, events, count, timeout);
}
int __real_poll(struct pollfd *, nfds_t, int);
int __wrap_poll(struct pollfd *fds, nfds_t count, int timeout) {
    requested = timeout; return __real_poll(fds, count, timeout);
}
int __wrap___poll_chk(struct pollfd *fds, nfds_t count, int timeout, size_t bytes) {
    assert(count <= bytes / sizeof(*fds)); return __wrap_poll(fds, count, timeout);
}
int main(int argc, char **argv) {
    assert(argc == 1 || (argc == 2 && !strcmp(argv[1], "--logical-clock")));
    logical_clock = argc == 2;
    assert(!__real_clock_gettime(CLOCK_MONOTONIC, &instant));
    assert(curl_global_init(CURL_GLOBAL_DEFAULT) == CURLE_OK);
    int server = socket(AF_INET, SOCK_STREAM | SOCK_CLOEXEC, 0); assert(server >= 0);
    struct sockaddr_in addr = {.sin_family = AF_INET, .sin_addr.s_addr = htonl(INADDR_LOOPBACK)};
    assert(!bind(server, (struct sockaddr *)&addr, sizeof(addr)) && !listen(server, 16));
    socklen_t length = sizeof(addr); assert(!getsockname(server, (struct sockaddr *)&addr, &length));
    char url[80]; snprintf(url, sizeof(url), "http://127.0.0.1:%u/", ntohs(addr.sin_port));
    for (int backend = 0; backend < 2; backend++) for (int streaming = 0; streaming < 2; streaming++) {
        ar_engine *e = ar_engine_new(); assert(e); e->use_epoll = backend;
        ar_job *j = ar_job_new(e, OP_HTTP); assert(j); j->a = ar_strdup(e, url); assert(j->a);
        j->length = 2000; j->stream_response = streaming;
        assert(!ar_submit(e, j)); requested = -2;
        assert(ar_wait(e, NULL, 0, 50) >= 0);
        printf("backend=%s streaming=%d kernelTimeout=%d\n", backend ? "epoll" : "poll", streaming, requested); fflush(stdout);
        assert(streaming ? requested == 0 : requested > 0 && requested <= 50);
        int peer = accept(server, NULL, NULL); assert(peer >= 0);
        ar_cancel(e, j->id); assert(ar_collect(e) == j); ar_job_free(j);
        assert(!e->pending && !atomic_load(&e->memory_bytes)); ar_engine_free(e); assert(!close(peer));
    }
    assert(!close(server)); curl_global_cleanup();
    puts(logical_clock ? "PASS controlled-clock admission, real epoll+poll readiness" :
                        "PASS real-clock buffered admission and streaming dispatch, epoll+poll");
}
