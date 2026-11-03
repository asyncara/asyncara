#include "../../ext/native.h"
#include <assert.h>
#include <errno.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>
#include <unistd.h>

static atomic_int fault, file_syncs, directory_syncs, writes;
int __real_fsync(int fd);
int __real_renameat(int, const char *, int, const char *);
void *__real_malloc(size_t size);
ssize_t __real_write(int fd, const void *data, size_t length);
ssize_t __wrap_write(int fd, const void *data, size_t length) {
    struct stat st;
    int mode = atomic_load(&fault);
    if ((mode == 5 || mode == 6) && !fstat(fd, &st) && S_ISREG(st.st_mode)) {
        if (mode == 6) { errno = ENOSPC; return -1; }
        if (!atomic_fetch_add(&writes, 1)) { errno = EINTR; return -1; }
        if (length > 1) length = 1;
    }
    return __real_write(fd, data, length);
}
void *__wrap_malloc(size_t size) {
    if (atomic_load(&fault) == 4) {
        atomic_store(&fault, 0);
        return NULL;
    }
    return __real_malloc(size);
}
int __wrap_fsync(int fd) {
    struct stat st;
    assert(!fstat(fd, &st));
    int directory = S_ISDIR(st.st_mode);
    atomic_fetch_add(directory ? &directory_syncs : &file_syncs, 1);
    if (atomic_load(&fault) == (directory ? 3 : 1)) { errno = EIO; return -1; }
    return __real_fsync(fd);
}
int __wrap_renameat(int from, const char *a, int to, const char *b) {
    if (atomic_load(&fault) == 2) { errno = EXDEV; return -1; }
    return __real_renameat(from, a, to, b);
}
int main(void) {
    char directory[] = "/tmp/asyncara-atomic-native-XXXXXX", path[256];
    assert(mkdtemp(directory));
    snprintf(path, sizeof(path), "%s/target", directory);
    ar_engine *engine = ar_engine_new();
    assert(engine);
    for (int mode = 0; mode <= 6; mode++) {
        FILE *old = fopen(path, "w"); assert(old); fputs("old", old); assert(!fclose(old));
        ar_job *job = ar_job_new(engine, OP_ATOMIC_WRITE); assert(job);
        job->a = ar_strdup(engine, path); job->b = ar_strdup(engine, "new");
        assert(job->a && job->b); job->b_len = 3; job->length = 0600; job->offset = 1;
        atomic_store(&fault, mode); atomic_store(&file_syncs, 0); atomic_store(&directory_syncs, 0); atomic_store(&writes, 0);
        assert(!ar_submit(engine, job));
        ar_job *done = NULL;
        while (!done) { assert(ar_wait(engine, NULL, 0, 1000) >= 0); done = ar_collect(engine); }
        assert(done == job);
        if (!mode || mode == 5) assert(!job->error[0] && job->committed && file_syncs == 1 && directory_syncs == 1);
        else {
            assert(job->error[0]);
            assert(job->committed == (mode == 3));
            if (mode != 4) assert(job->system_errno == (mode == 2 ? EXDEV : mode == 6 ? ENOSPC : EIO));
        }
        FILE *read = fopen(path, "r"); assert(read); char data[4] = {0};
        assert(fread(data, 1, 3, read) == 3); fclose(read);
        assert(!strcmp(data, !mode || mode == 3 || mode == 5 ? "new" : "old"));
        ar_job_free(job); assert(!atomic_load(&engine->memory_bytes));
    }
    ar_engine_free(engine);
    assert(!unlink(path));
    assert(!rmdir(directory)); /* also asserts that no temporary file survived */
    puts("Atomic fsync/rename/allocation failures and publication state: PASS");
}
