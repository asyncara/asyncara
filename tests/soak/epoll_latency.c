/* Linux control: measure the same 5 ms wait without PHP, curl or Asyncara.
 * This reports oversleep; it does not assign its cause or relax runtime tests. */
#include <errno.h>
#include <math.h>
#include <stdio.h>
#include <stdlib.h>
#include <sys/epoll.h>
#include <time.h>
#include <unistd.h>

static double now(clockid_t clock) {
    struct timespec value;
    if (clock_gettime(clock, &value)) { perror("clock_gettime"); exit(1); }
    return value.tv_sec + value.tv_nsec / 1e9;
}

int main(int argc, char **argv) {
    char *end = NULL;
    double seconds = argc == 2 ? strtod(argv[1], &end) : 0;
    if (argc != 2 || end == argv[1] || *end || !isfinite(seconds) || seconds <= 0 || seconds > 3600) {
        fprintf(stderr, "Usage: epoll_latency SECONDS (0 < seconds <= 3600)\n"); return 2;
    }
    int fd = epoll_create1(EPOLL_CLOEXEC);
    if (fd < 0) { perror("epoll_create1"); return 1; }
    struct epoll_event event;
    double start = now(CLOCK_MONOTONIC), max_wait = 0;
    unsigned long waits = 0, oversleeps = 0;
    while (now(CLOCK_MONOTONIC) - start < seconds) {
        double cpu = now(CLOCK_PROCESS_CPUTIME_ID), before = now(CLOCK_MONOTONIC);
        int result = epoll_wait(fd, &event, 1, 5);
        double elapsed = now(CLOCK_MONOTONIC) - before;
        if (result < 0 && errno == EINTR) continue;
        if (result != 0) { perror("unexpected epoll_wait result"); close(fd); return 1; }
        waits++;
        if (elapsed > max_wait) max_wait = elapsed;
        if (elapsed > .05) {
            oversleeps++;
            printf("{\"type\":\"oversleep\",\"unixSeconds\":%.9f,\"elapsedSeconds\":%.9f,\"cpuSeconds\":%.9f,\"requestedMilliseconds\":5}\n",
                now(CLOCK_REALTIME), elapsed, now(CLOCK_PROCESS_CPUTIME_ID) - cpu);
            fflush(stdout);
        }
    }
    close(fd);
    printf("{\"type\":\"complete\",\"seconds\":%.9f,\"waits\":%lu,\"over50ms\":%lu,\"maxWaitSeconds\":%.9f}\n",
        now(CLOCK_MONOTONIC) - start, waits, oversleeps, max_wait);
    return 0;
}
