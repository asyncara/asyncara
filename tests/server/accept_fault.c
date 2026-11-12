/* Test-only accept4 interposition. Commands and acknowledgements use an inherited
 * SOCK_SEQPACKET socket; no waits, clocks, files or runtime code are replaced. */
#ifndef _GNU_SOURCE
#define _GNU_SOURCE
#endif
#include <dlfcn.h>
#include <errno.h>
#include <stdint.h>
#include <stdlib.h>
#include <sys/socket.h>

static int control = -1;
static int (*next_accept4)(int, struct sockaddr *, socklen_t *, int);
#ifdef ASYNCARA_TEST_LINK_ACCEPT
int __real_accept4(int, struct sockaddr *, socklen_t *, int);
#define accept4 __wrap_accept4
#endif

__attribute__((constructor)) static void initialize(void) {
    const char *value = getenv("ASYNCARA_TEST_ACCEPT_CONTROL_FD");
    if (value) control = atoi(value);
#ifdef ASYNCARA_TEST_LINK_ACCEPT
    next_accept4 = __real_accept4;
#else
    next_accept4 = dlsym(RTLD_NEXT, "accept4");
#endif
}

int accept4(int fd, struct sockaddr *address, socklen_t *length, int flags) {
    int saved = errno;
    int32_t fault;
    if (control >= 0 && recv(control, &fault, sizeof(fault), MSG_DONTWAIT) == sizeof(fault)) {
        /* The controller asserts every ACK, so a missed injection cannot pass. */
        (void)send(control, &fault, sizeof(fault), MSG_DONTWAIT | MSG_NOSIGNAL);
        errno = fault;
        return -1;
    }
    errno = saved;
    if (next_accept4) return next_accept4(fd, address, length, flags);
    errno = ENOSYS;
    return -1;
}
