/* Isolated native regression. Link with --wrap=pthread_create.
 * The test-only gate makes ar_cancel() win before any worker dequeues a job.
 * No timing sleeps, PHP hooks, or production fault-injection code are used. */
#include "../../ext/native.h"
#include <assert.h>
#include <errno.h>
#include <fcntl.h>
#include <limits.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>

#ifdef NDEBUG
#error This regression requires assertions
#endif

static pthread_mutex_t gate_mutex = PTHREAD_MUTEX_INITIALIZER;
static pthread_cond_t gate_condition = PTHREAD_COND_INITIALIZER;
static int gate_open;
typedef struct { void *(*start)(void *); void *argument; } start_context;

int __real_pthread_create(pthread_t *, const pthread_attr_t *, void *(*)(void *), void *);
static void *gated_start(void *argument) {
    start_context context = *(start_context *)argument;
    free(argument);
    assert(!pthread_mutex_lock(&gate_mutex));
    while (!gate_open)
        assert(!pthread_cond_wait(&gate_condition, &gate_mutex));
    assert(!pthread_mutex_unlock(&gate_mutex));
    return context.start(context.argument);
}
int __wrap_pthread_create(pthread_t *thread, const pthread_attr_t *attributes,
                          void *(*start)(void *), void *argument) {
    start_context *context = malloc(sizeof(*context));
    if (!context) return ENOMEM;
    *context = (start_context){start, argument};
    int result = __real_pthread_create(thread, attributes, gated_start, context);
    if (result) free(context);
    return result;
}
static void set_gate(int open) {
    assert(!pthread_mutex_lock(&gate_mutex));
    gate_open = open;
    if (open) assert(!pthread_cond_broadcast(&gate_condition));
    assert(!pthread_mutex_unlock(&gate_mutex));
}
static ar_job *complete(ar_engine *engine) {
    for (int attempt = 0; attempt < 100; attempt++) {
        assert(ar_wait(engine, NULL, 0, 100) >= 0);
        ar_job *job = ar_collect(engine);
        if (job) {
            assert(!job->done_next && engine->pending == 0);
            assert(!ar_collect(engine)); /* exactly one completion */
            return job;
        }
    }
    assert(!"Worker failed to complete within bounded regression wait");
    return NULL;
}
static void submit_cancel_release(ar_engine *engine, ar_job *job) {
    assert(!ar_submit(engine, job));
    assert(engine->thread_count == 4);
    ar_cancel(engine, job->id);
    assert(atomic_load(&job->cancelled));
    set_gate(1);
}
static void success(ar_job *job) {
    assert(!job->error[0] && !job->system_errno);
    assert(job->result && job->result->type == V_NULL);
}
static void finish(ar_engine *engine, ar_job *job) {
    ar_job_free(job);
    assert(!engine->files && !engine->dbs && !engine->jobs && !engine->allocations);
    assert(atomic_load(&engine->memory_bytes) == 0);
    ar_engine_free(engine);
}

/* 0: success, 1: real EBADF, 2: result-allocation rejection after close. */
static void file_close_case(int backend, int failure) {
    set_gate(0);
    ar_engine *engine = ar_engine_new(); assert(engine);
    engine->use_epoll = backend;
    ar_file *file = ar_calloc(engine, 1, sizeof(*file)); assert(file);
    char path[] = "/tmp/asyncara-close-XXXXXX";
    int descriptor = mkstemp(path); assert(descriptor >= 0);
    assert(!unlink(path));
    if (failure == 1) {
        assert(!close(descriptor));
        descriptor = INT_MAX; /* invalid descriptor, no reuse race */
    }
    file->fd = descriptor;
    file->id = ++engine->next_file;
    engine->files = file;
    ar_job *job = ar_job_new(engine, OP_FILE_CLOSE); assert(job);
    job->file = file;
    if (failure == 2) engine->memory_limit = atomic_load(&engine->memory_bytes);
    submit_cancel_release(engine, job);
    assert(complete(engine) == job);
    assert(!engine->files && !job->file);
    errno = 0;
    assert(fcntl(descriptor, F_GETFD) == -1 && errno == EBADF);
    if (failure == 1) {
        assert(job->system_errno == EBADF && job->error[0]);
        assert(strcmp(job->error, "Operation cancelled"));
    } else if (failure == 2) {
        assert(!strcmp(job->error, "Native memory limit or allocation failure"));
        assert(!job->result && atomic_load(&engine->memory_rejections) > 0);
    } else success(job);
    finish(engine, job);
}

/* A failed SQLite close must retain a usable native owner for a later retry. */
static void sqlite_close_case(int backend, int busy) {
    set_gate(0);
    ar_engine *engine = ar_engine_new(); assert(engine);
    engine->use_epoll = backend;
    ar_db *db = ar_calloc(engine, 1, sizeof(*db)); assert(db);
    assert(sqlite3_open_v2(":memory:", &db->handle,
                          SQLITE_OPEN_READWRITE | SQLITE_OPEN_CREATE | SQLITE_OPEN_FULLMUTEX,
                          NULL) == SQLITE_OK);
    db->id = ++engine->next_db;
    engine->dbs = db;
    sqlite3_stmt *statement = NULL;
    if (busy) assert(sqlite3_prepare_v2(db->handle, "SELECT 1", -1, &statement, NULL) == SQLITE_OK);
    ar_job *job = ar_job_new(engine, OP_DB_CLOSE); assert(job);
    job->db = db;
    submit_cancel_release(engine, job);
    assert(complete(engine) == job);
    if (busy) {
        assert(!strcmp(job->error, sqlite3_errstr(SQLITE_BUSY)));
        assert(engine->dbs == db && job->db == db && db->handle && !db->busy);
        assert(sqlite3_finalize(statement) == SQLITE_OK);
        ar_job_free(job);
        job = ar_job_new(engine, OP_DB_CLOSE); assert(job);
        job->db = db;
        assert(!ar_submit(engine, job));
        assert(complete(engine) == job);
    }
    success(job);
    assert(!engine->dbs && !job->db);
    finish(engine, job);
}

/* Narrow exemption: cancellation still prevents an ordinary queued write. */
static void cancelled_write_control(int backend) {
    set_gate(0);
    ar_engine *engine = ar_engine_new(); assert(engine);
    engine->use_epoll = backend;
    char path[] = "/tmp/asyncara-close-control-XXXXXX";
    int descriptor = mkstemp(path); assert(descriptor >= 0);
    assert(write(descriptor, "keep", 4) == 4);
    ar_job *job = ar_job_new(engine, OP_WRITE); assert(job);
    job->a = ar_strdup(engine, path);
    job->b = ar_strdup(engine, "replace");
    job->b_len = 7;
    assert(job->a && job->b);
    submit_cancel_release(engine, job);
    assert(complete(engine) == job);
    assert(!strcmp(job->error, "Operation cancelled") && !job->result);
    char data[8] = {0};
    assert(pread(descriptor, data, sizeof(data), 0) == 4 && !memcmp(data, "keep", 4));
    assert(!close(descriptor) && !unlink(path));
    finish(engine, job);
}
int main(int argc, char **argv) {
    assert(argc <= 2);
    const char *selection = argc == 2 ? argv[1] : "all";
    int files = !strcmp(selection, "all") || !strcmp(selection, "file");
    int sqlite = !strcmp(selection, "all") || !strcmp(selection, "sqlite");
    int control = !strcmp(selection, "all") || !strcmp(selection, "control");
    assert(files || sqlite || control);
    assert(curl_global_init(CURL_GLOBAL_DEFAULT) == CURLE_OK);
    for (int backend = 0; backend <= 1; backend++) {
        if (files) for (int failure = 0; failure < 3; failure++) file_close_case(backend, failure);
        if (sqlite) {
            sqlite_close_case(backend, 0);
            sqlite_close_case(backend, 1);
        }
        if (control) cancelled_write_control(backend);
        printf("Accepted close regression selection=%s backend=%s: PASS\n",
               selection, backend ? "epoll" : "poll");
    }
    curl_global_cleanup();
    return 0;
}
