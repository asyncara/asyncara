/* Real worker queue, filesystem and wait backends. Inject only failing native
 * syscalls, never replace publication/cleanup logic with mocks. */
#include "../../ext/native.h"
#include <assert.h>
#include <dirent.h>
#include <errno.h>
#include <fcntl.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>

static int fault, sync_calls;
static ar_job *publishing;
int __real_fsync(int);
int __real_renameat(int, const char *, int, const char *);
int __wrap_fsync(int fd) {
    sync_calls++;
    if ((fault == 1 && sync_calls == 1) || (fault == 3 && sync_calls == 2)) {
        errno = EIO; return -1;
    }
    if (fault == 4) atomic_store(&publishing->cancelled, 1);
    return __real_fsync(fd);
}
int __wrap_renameat(int oldfd, const char *old, int newfd, const char *new) {
    if (fault == 2) { errno = ENOSPC; return -1; }
    return __real_renameat(oldfd, old, newfd, new);
}
static void complete(ar_engine *e, ar_job *j) {
    assert(!ar_submit(e, j));
    for (int i = 0; i < 100; i++) {
        assert(ar_wait(e, NULL, 0, 100) >= 0);
        ar_job *done = ar_collect(e);
        if (done) {
            assert(done == j && !done->done_next && !e->pending && !ar_collect(e));
            return;
        }
    }
    assert(!"Missing worker completion");
}
static void run(int backend, int mode) {
    fault = 0; sync_calls = 0;
    char dir[] = "/tmp/asyncara-stage-native-XXXXXX";
    assert(mkdtemp(dir));
    char target[128]; assert(snprintf(target, sizeof(target), "%s/target", dir) > 0);
    int fd = open(target, O_WRONLY | O_CREAT | O_EXCL, 0600); assert(fd >= 0);
    assert(write(fd, "OLD", 3) == 3 && !close(fd));
    ar_engine *e = ar_engine_new(); assert(e); e->use_epoll = backend;
    ar_file *file = ar_calloc(e, 1, sizeof(*file)); assert(file);
    file->fd = -1; file->id = ++e->next_file; e->files = file;
    ar_job *j = ar_job_new(e, OP_FILE_STAGE); assert(j);
    j->file = file; j->a = ar_strdup(e, target); assert(j->a);
    complete(e, j); assert(!j->error[0] && file->stage_name[0]); ar_job_free(j);
    j = ar_job_new(e, OP_FILE_WRITE); assert(j); j->file = file;
    j->b = ar_strdup(e, "NEW"); j->b_len = 3; assert(j->b);
    complete(e, j); assert(!j->error[0]); ar_job_free(j);
    fault = mode;
    j = ar_job_new(e, OP_FILE_PUBLISH); assert(j); j->file = file; j->offset = 1;
    publishing = j;
    size_t limit = e->memory_limit;
    if (mode == 5) e->memory_limit = atomic_load(&e->memory_bytes); /* result allocation */
    complete(e, j);
    e->memory_limit = limit;
    int committed = mode == 0 || mode == 3;
    assert(j->committed == committed);
    assert((!j->error[0]) == (mode == 0));
    if (mode == 1 || mode == 3) assert(j->system_errno == EIO);
    if (mode == 2) assert(j->system_errno == ENOSPC);
    if (mode == 4) assert(!strcmp(j->error, "Operation cancelled"));
    if (mode == 5) assert(atomic_load(&e->memory_rejections));
    ar_job_free(j); publishing = NULL; fault = 0;
    fd = open(target, O_RDONLY); assert(fd >= 0); char bytes[4] = {0};
    assert(read(fd, bytes, sizeof(bytes)) == 3 && !close(fd));
    assert(!strcmp(bytes, committed ? "NEW" : "OLD"));
    int filefd = file->fd, dirfd = file->directory_fd;
    j = ar_job_new(e, OP_FILE_CLOSE); assert(j); j->file = file;
    atomic_store(&j->cancelled, 1); /* accepted close must still clean staging */
    complete(e, j); assert(!j->error[0] && !e->files); ar_job_free(j);
    assert(fcntl(filefd, F_GETFD) == -1 && errno == EBADF);
    assert(fcntl(dirfd, F_GETFD) == -1 && errno == EBADF);
    DIR *scan = opendir(dir); assert(scan); struct dirent *entry;
    while ((entry = readdir(scan))) assert(strncmp(entry->d_name, ".asyncara-", 9));
    assert(!closedir(scan));
    assert(!e->allocations && !atomic_load(&e->memory_bytes)); ar_engine_free(e);
    assert(!unlink(target) && !rmdir(dir));
}
int main(void) {
    assert(curl_global_init(CURL_GLOBAL_DEFAULT) == CURLE_OK);
    for (int backend = 0; backend < 2; backend++)
        for (int mode = 0; mode < 6; mode++) run(backend, mode);
    curl_global_cleanup();
    puts("PASS staged publication: fsync/rename/ENOSPC/cancellation/allocation/mandatory-close, epoll+poll");
}
