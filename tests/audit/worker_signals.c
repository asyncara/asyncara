#include "../../ext/native.h"
#include <assert.h>
#include <signal.h>
#include <stdio.h>
#include <unistd.h>
static volatile sig_atomic_t signals_seen;
static void signal_handler(int number) { (void)number; signals_seen = 1; }
int main(void) {
    struct sigaction action = {0}, original;
    action.sa_handler = signal_handler;
    sigemptyset(&action.sa_mask);
    assert(!sigaction(SIGUSR1, &action, &original));
    sigset_t before, after;
    assert(!pthread_sigmask(SIG_SETMASK, NULL, &before));
    ar_engine *e = ar_engine_new(); assert(e);
    ar_job *job = ar_job_new(e, OP_READ); assert(job);
    job->a = ar_strdup(e, "/dev/null"); assert(job->a);
    assert(!ar_submit(e, job));
    assert(e->thread_count == 4);
    assert(!pthread_sigmask(SIG_SETMASK, NULL, &after));
    for (int number = 1; number < NSIG; number++)
        assert(sigismember(&before, number) == sigismember(&after, number));
    for (int i = 0; i < e->thread_count; i++) assert(!pthread_kill(e->threads[i], SIGUSR1));
    usleep(20000);
    assert(signals_seen == 0 && "Native workers must not run interpreter signal handlers");
    raise(SIGUSR1);
    assert(signals_seen == 1 && "Interpreter signal mask must be restored");
    ar_engine_free(e);
    assert(!sigaction(SIGUSR1, &original, NULL));
    puts("Native worker signal isolation and caller mask restoration: PASS");
}
