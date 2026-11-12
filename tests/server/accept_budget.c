/* Real server accept loop, with only accept4's error result controlled. */
#include "../../ext/native.h"
#include <assert.h>
#include <errno.h>
#include <stdio.h>
#include <sys/socket.h>

static int injected, calls;
int __real_accept4(int, struct sockaddr *, socklen_t *, int);
int __wrap_accept4(int fd, struct sockaddr *address, socklen_t *length, int flags) {
    if (!injected) return __real_accept4(fd, address, length, flags);
    calls++;
    errno = injected;
    return -1;
}

int main(void) {
    void *server = ar_server_new((unsigned char *)"127.0.0.1:0", 11,
        8, 1024, 1024, 65536, 1000, 10, 0);
    assert(server);
    for (int cycle = 0; cycle < 1000; cycle++) {
        injected = ECONNABORTED;
        calls = 0;
        assert(ar_server_drive(server) == 0);
        assert(calls == 64); /* Persistent errors cannot monopolize this turn. */
        injected = 0;
        assert(ar_server_drive(server) == 0); /* Listener remains usable. */
    }
    injected = EBADF;
    calls = 0;
    assert(ar_server_drive(server) == -1 && calls == 1);
    assert(ar_server_drive(server) == -1 && calls == 1); /* Still poisoned. */
    ar_server_free(server);
    puts("PASS: 64000 transient accept errors, 1000 bounded turns, fatal error preserved");
}
