/* Exercise readiness, errors, interruption and fd lifetime under interposition. */
#define _GNU_SOURCE
#include <assert.h>
#include <dirent.h>
#include <errno.h>
#include <poll.h>
#include <pthread.h>
#include <signal.h>
#include <stdio.h>
#include <string.h>
#include <sys/epoll.h>
#include <sys/time.h>
#include <unistd.h>

static void interrupt_wait(int signal_number) { (void)signal_number; }
static int descriptors(void) {
    DIR *directory = opendir("/proc/self/fd");
    assert(directory);
    int count = 0;
    while (readdir(directory)) count++;
    assert(closedir(directory) == 0);
    return count;
}

static void *thread_wait(void *unused) {
    (void)unused;
    errno = ENOMEM;
    assert(poll(NULL, 0, 120) == 0 && errno == ENOMEM);
    int ep = epoll_create1(EPOLL_CLOEXEC);
    assert(ep >= 0);
    struct epoll_event event;
    errno = E2BIG;
    assert(epoll_wait(ep, &event, 1, 120) == 0 && errno == E2BIG);
    assert(close(ep) == 0);
    return NULL;
}

int main(int argc, char **argv) {
    int before = descriptors();
    if (argc == 2 && !strcmp(argv[1], "threads")) {
        pthread_t threads[4];
        for (int i = 0; i < 4; i++) assert(pthread_create(&threads[i], NULL, thread_wait, NULL) == 0);
        for (int i = 0; i < 4; i++) assert(pthread_join(threads[i], NULL) == 0);
        assert(before == descriptors());
        return 0;
    }
    if (argc != 1) return 2;
    errno = E2BIG;
    assert(poll(NULL, 0, 120) == 0 && errno == E2BIG);
    int ep = epoll_create1(EPOLL_CLOEXEC);
    assert(ep >= 0);
    struct epoll_event event;
    errno = E2BIG;
    assert(epoll_wait(ep, &event, 1, 120) == 0 && errno == E2BIG);
    assert(epoll_wait(-1, &event, 1, 0) == -1 && errno == EBADF);
    int pipefd[2];
    assert(pipe(pipefd) == 0 && write(pipefd[1], "x", 1) == 1);
    struct pollfd watched = {.fd = pipefd[0], .events = POLLIN};
    assert(poll(&watched, 1, 120) == 1 && (watched.revents & POLLIN));
    event.events = EPOLLIN; event.data.u64 = 123;
    assert(epoll_ctl(ep, EPOLL_CTL_ADD, pipefd[0], &event) == 0);
    assert(epoll_wait(ep, &event, 1, 120) == 1 && (event.events & EPOLLIN) && event.data.u64 == 123);
    assert(close(pipefd[0]) == 0 && close(pipefd[1]) == 0);
    struct sigaction action = {.sa_handler = interrupt_wait};
    assert(sigemptyset(&action.sa_mask) == 0 && sigaction(SIGALRM, &action, NULL) == 0);
    struct itimerval timer = {.it_value = {.tv_usec = 10000}, .it_interval = {.tv_usec = 10000}};
    assert(setitimer(ITIMER_REAL, &timer, NULL) == 0);
    assert(poll(NULL, 0, 1000) == -1 && errno == EINTR);
    assert(epoll_wait(ep, &event, 1, 1000) == -1 && errno == EINTR);
    timer = (struct itimerval){0};
    assert(setitimer(ITIMER_REAL, &timer, NULL) == 0 && close(ep) == 0);
    int after = descriptors();
    assert(before == after);
    printf("{\"pid\":%ld,\"fdsBefore\":%d,\"fdsAfter\":%d}\n", (long)getpid(), before, after);
    return 0;
}
