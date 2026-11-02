#include "../ext/native.h"
#include <assert.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
static ar_job *wait_one(ar_engine *e) {
    ar_job *j = NULL;
    while (!j) {
        assert(ar_wait(e, NULL, 0, 1000) >= 0);
        j = ar_collect(e);
    }
    return j;
}
int main(void) {
    assert(curl_global_init(CURL_GLOBAL_DEFAULT) == CURLE_OK);
    char path[] = "/tmp/asyncara-native-XXXXXX";
    int fd = mkstemp(path);
    assert(fd >= 0);
    close(fd);
    for (int round = 0; round < 40; round++) {
        ar_engine *e = ar_engine_new();
        assert(e);
        size_t original_limit = e->memory_limit;
        e->memory_limit = 4096;
        unsigned char *allocation = ar_alloc(e, 32);
        assert(allocation);
        memset(allocation, 42, 32);
        assert(!ar_realloc(e, allocation, 8192));
        assert(allocation[0] == 42 && allocation[31] == 42);
        assert(!ar_alloc(e, SIZE_MAX));
        assert(!ar_calloc(e, SIZE_MAX, 2));
        ar_free(allocation);
        assert(atomic_load(&e->memory_bytes) == 0);
        e->memory_limit = original_limit;
        ar_job *j = ar_job_new(e, OP_WRITE);
        j->a = ar_strdup(e, path);
        j->b = ar_strdup(e, "hello");
        j->b_len = 5;
        assert(!ar_submit(e, j));
        j = wait_one(e);
        assert(!j->error[0]);
        ar_job_free(j);
        for (int i = 0; i < 32; i++) {
            j = ar_job_new(e, OP_READ);
            j->a = ar_strdup(e, path);
            assert(!ar_submit(e, j));
            if (i % 3 == 0)
                ar_cancel(e, j->id);
        }
        while (e->pending) {
            j = wait_one(e);
            while (j) {
                ar_job *next = j->done_next;
                ar_job_free(j);
                j = next;
            }
        }
        j = ar_job_new(e, OP_DB_OPEN);
        j->a = ar_strdup(e, ":memory:");
        ar_db *db = ar_calloc(e, 1, sizeof(*db));
        db->id = ++e->next_db;
        db->next = e->dbs;
        e->dbs = db;
        j->db = db;
        assert(!ar_submit(e, j));
        j = wait_one(e);
        assert(!j->error[0]);
        ar_job_free(j);
        j = ar_job_new(e, OP_DB_QUERY);
        j->a = ar_strdup(e, "select 42 as n, x'610062' as binary");
        j->db = db;
        assert(!ar_submit(e, j));
        j = wait_one(e);
        assert(!j->error[0]);
        assert(j->result->items[0]->items[0]->integer == 42);
        ar_job_free(j);
        j = ar_job_new(e, OP_DB_CLOSE);
        j->db = db;
        assert(!ar_submit(e, j));
        j = wait_one(e);
        assert(!j->error[0]);
        ar_job_free(j);
        assert(!e->dbs);
        j = ar_job_new(e, OP_FILE_OPEN);
        j->a = ar_strdup(e, path);
        j->b = ar_strdup(e, "r");
        ar_file *file = ar_calloc(e, 1, sizeof(*file));
        file->id = ++e->next_file;
        file->fd = -1;
        file->next = e->files;
        e->files = file;
        j->file = file;
        assert(!ar_submit(e, j));
        j = wait_one(e);
        assert(!j->error[0]);
        ar_job_free(j);
        j = ar_job_new(e, OP_FILE_READ);
        j->file = file;
        j->length = 5;
        assert(!ar_submit(e, j));
        j = wait_one(e);
        assert(!j->error[0] && j->result->length == 5);
        assert(!memcmp(j->result->string, "hello", 5));
        ar_job_free(j);
        j = ar_job_new(e, OP_FILE_CLOSE);
        j->file = file;
        assert(!ar_submit(e, j));
        j = wait_one(e);
        assert(!j->error[0]);
        ar_job_free(j);
        assert(!e->files);
        for (int i = 0; i < 32; i++) {
            j = ar_job_new(e, OP_READ);
            j->a = ar_strdup(e, path);
            assert(!ar_submit(e, j));
        }
        ar_engine_free(e); // Deliberate shutdown with queued/running work.
    }
    unlink(path);
    curl_global_cleanup();
    puts("native sanitizer lifecycle: PASS");
    return 0;
}
