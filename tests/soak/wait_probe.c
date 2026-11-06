/* Linux/glibc diagnostic only. Preload into an explicitly owned test process.
 * Measure libc readiness waits without ptrace or any Zend Engine access.
 * Logging occurs AFTER measurement, only for waits longer than 50 ms.
 * Keep no descriptors across calls/fork/exec or request boundaries.
 */
#define _GNU_SOURCE
#include <dlfcn.h>
#include <errno.h>
#include <fcntl.h>
#include <inttypes.h>
#include <limits.h>
#include <poll.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/epoll.h>
#include <time.h>
#include <unistd.h>

static int (*next_poll)(struct pollfd *, nfds_t, int);
static int (*next_epoll_wait)(int, struct epoll_event *, int, int);
static char log_path[PATH_MAX];

static void fail(void) {
    static const char message[] = "Asyncara wait probe failed: initialization, clock or log I/O error\n";
    size_t offset = 0;
    while (offset < sizeof(message) - 1) {
        ssize_t written = write(STDERR_FILENO, message + offset, sizeof(message) - 1 - offset);
        if (written < 0 && errno == EINTR) continue;
        if (written <= 0) break;
        offset += (size_t)written;
    }
    /* This is a failed diagnostic, never silently unmeasured application work. */
    _exit(125);
}

static void record(const char *buffer, int length) {
    if (length <= 0 || length >= 512) fail();
    int fd = open(log_path, O_WRONLY | O_APPEND | O_CLOEXEC | O_NOFOLLOW);
    if (fd < 0) fail();
    ssize_t written;
    do { written = write(fd, buffer, (size_t)length); } while (written < 0 && errno == EINTR);
    int closed = close(fd);
    if (written != length || closed != 0) fail();
}

static uint64_t nanos(clockid_t clock) {
    struct timespec value;
    if (clock_gettime(clock, &value)) fail();
    return (uint64_t)value.tv_sec * UINT64_C(1000000000) + (uint64_t)value.tv_nsec;
}

__attribute__((constructor)) static void initialize(void) {
    const char *path = getenv("ASYNCARA_WAIT_LOG");
    if (!path || !*path || strlen(path) >= sizeof(log_path)) fail();
    memcpy(log_path, path, strlen(path) + 1);
    /* POSIX dlsym interposition; no engine pointers or private memory patching. */
    next_poll = (int (*)(struct pollfd *, nfds_t, int))dlsym(RTLD_NEXT, "poll");
    next_epoll_wait = (int (*)(int, struct epoll_event *, int, int))dlsym(RTLD_NEXT, "epoll_wait");
    if (!next_poll || !next_epoll_wait) fail();
    char buffer[512];
    record(buffer, snprintf(buffer, sizeof(buffer),
        "{\"type\":\"loaded\",\"pid\":%ld,\"clock\":\"CLOCK_MONOTONIC\"}\n", (long)getpid()));
}

static void finish(const char *operation, int timeout, int result, int saved_errno,
                   uint64_t start, uint64_t end, uint64_t cpu_start) {
    uint64_t cpu_end = nanos(CLOCK_THREAD_CPUTIME_ID);
    if (end - start > UINT64_C(50000000)) {
        char buffer[512];
        record(buffer, snprintf(buffer, sizeof(buffer),
            "{\"type\":\"wait\",\"operation\":\"%s\",\"pid\":%ld,\"tid\":%ld,"
            "\"timeoutMs\":%d,\"result\":%d,\"errno\":%d,"
            "\"startedMonotonicNs\":%" PRIu64 ",\"finishedMonotonicNs\":%" PRIu64 ","
            "\"elapsedNs\":%" PRIu64 ",\"threadCpuNs\":%" PRIu64 "}\n",
            operation, (long)getpid(), (long)gettid(), timeout, result, saved_errno,
            start, end, end - start, cpu_end - cpu_start));
    }
    errno = saved_errno;
}

int poll(struct pollfd *fds, nfds_t count, int timeout) {
    int incoming_errno = errno;
    uint64_t cpu = nanos(CLOCK_THREAD_CPUTIME_ID), start = nanos(CLOCK_MONOTONIC);
    errno = incoming_errno;
    int result = next_poll(fds, count, timeout), saved_errno = errno;
    uint64_t end = nanos(CLOCK_MONOTONIC);
    finish("poll", timeout, result, saved_errno, start, end, cpu);
    return result;
}

int epoll_wait(int fd, struct epoll_event *events, int maximum, int timeout) {
    int incoming_errno = errno;
    uint64_t cpu = nanos(CLOCK_THREAD_CPUTIME_ID), start = nanos(CLOCK_MONOTONIC);
    errno = incoming_errno;
    int result = next_epoll_wait(fd, events, maximum, timeout), saved_errno = errno;
    uint64_t end = nanos(CLOCK_MONOTONIC);
    finish("epoll_wait", timeout, result, saved_errno, start, end, cpu);
    return result;
}
