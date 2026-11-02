/* Linux can report a joined thread briefly before its task_struct is reaped. */
#include "../../ext/native.h"
#include <assert.h>
#include <stdio.h>
#include <unistd.h>

static int threads(void) {
    FILE *file = fopen("/proc/self/status", "r");
    assert(file);
    char line[256];
    int count = 0;
    while (fgets(line, sizeof(line), file)) {
        if (sscanf(line, "Threads: %d", &count) == 1)
            break;
    }
    fclose(file);
    return count;
}

int main(void) {
    int transient = 0;
    for (int i = 0; i < 2000; i++) {
        ar_engine *engine = ar_engine_new();
        assert(engine);
        ar_job *job = ar_job_new(engine, OP_READ);
        assert(job);
        job->a = ar_strdup(engine, "/dev/null");
        assert(!ar_submit(engine, job));
        ar_engine_free(engine);
        int immediate = threads();
        if (immediate != 1) {
            transient++;
            usleep(10000);
            int settled = threads();
            printf("round=%d immediate=%d after10ms=%d\n", i, immediate, settled);
            assert(settled == 1);
        }
    }
    printf("transientSamples=%d of 2000\n", transient);
}
