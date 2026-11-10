#include "../../ext/native.h"
#include <assert.h>
#include <errno.h>
#include <poll.h>
#include <stdio.h>
#include <sys/epoll.h>
static int calls, armed;
int __real_epoll_wait(int, struct epoll_event *, int, int);
int __real_poll(struct pollfd *, nfds_t, int);
int __wrap_epoll_wait(int ep, struct epoll_event *events, int max, int timeout) {
    if (!armed) return __real_epoll_wait(ep, events, max, timeout);
    calls++; errno = EINTR; return -1;
}
int __wrap_poll(struct pollfd *fds, nfds_t n, int timeout) {
    if (!armed) return __real_poll(fds, n, timeout);
    calls++; errno = EINTR; return -1;
}
int main(void) {
    ar_engine *e = ar_engine_new(); assert(e);
    e->server = ar_server_new((unsigned char *)"127.0.0.1:0", 11, 8, 1024, 1024, 65536, 1000, 10, 0);
    assert(e->server);
    ar_server_status info; assert(!ar_server_info(e->server, &info));
    ar_watch watch = {.fd=info.notification_fd, .events=POLLIN};
    armed = 1;
    int rc = ar_wait(e, &watch, 1, 10);
    fprintf(stderr, "wait=%d errno=%d calls=%d backend=%s\n", rc, errno, calls, e->use_epoll ? "epoll" : "poll");
    assert(calls==1 && rc==0 && errno==EINTR);
    ar_engine_free(e); puts("PASS: EINTR survives accept(EAGAIN) after wait");
}
