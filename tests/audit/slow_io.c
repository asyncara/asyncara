#define _GNU_SOURCE
#include <dlfcn.h>
#include <fcntl.h>
#include <netdb.h>
#include <stdarg.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>
#include <unistd.h>
/* Test-only syscall delay. Loaded explicitly into audit child processes. */
void *dlopen(const char *filename, int flags) {
    void *(*real_dlopen)(const char *, int) = dlsym(RTLD_NEXT, "dlopen");
    const char *target = getenv("ASYNCARA_AUDIT_EXTENSION");
    /* PHP 8.3/8.4 may load extensions with DEEPBIND, bypassing this fault
     * injector. Change lookup only for the explicitly selected audit module;
     * the same compiled artifact is tested, with no engine memory patching. */
#ifdef RTLD_DEEPBIND
    if (filename && target && strcmp(filename, target) == 0)
        flags &= ~RTLD_DEEPBIND;
#endif
    return real_dlopen ? real_dlopen(filename, flags) : NULL;
}
int open(const char *path, int flags, ...) {
    int (*real_open)(const char *, int, ...) = dlsym(RTLD_NEXT, "open");
    mode_t mode = 0;
    if (flags & O_CREAT) { va_list args; va_start(args, flags); mode = (mode_t)va_arg(args, int); va_end(args); }
    if (strstr(path, "audit-delay")) {
        const char *marker = getenv("ASYNCARA_AUDIT_MARKER");
        if (marker) { int fd = real_open(marker, O_CREAT|O_WRONLY|O_TRUNC, 0600); if (fd >= 0) { write(fd,"1",1); close(fd); } }
        struct timespec wait = {.tv_sec = 0, .tv_nsec = 250000000};
        while (nanosleep(&wait, &wait) != 0) {}
    }
    return real_open(path, flags, mode);
}

int getaddrinfo(const char *node, const char *service, const struct addrinfo *hints, struct addrinfo **result) {
    int (*real_getaddrinfo)(const char *, const char *, const struct addrinfo *, struct addrinfo **) = dlsym(RTLD_NEXT, "getaddrinfo");
    if (node && strcmp(node, "audit-delay.invalid") == 0) {
        struct timespec wait = {.tv_sec = 0, .tv_nsec = 250000000};
        while (nanosleep(&wait, &wait) != 0) {}
        node = "localhost";
    }
    return real_getaddrinfo(node, service, hints, result);
}
