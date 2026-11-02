#ifndef ASYNCARA_NATIVE_H
#define ASYNCARA_NATIVE_H
#include <curl/curl.h>
#include <pthread.h>
#include <sqlite3.h>
#include <stdatomic.h>
#include <stddef.h>
#include <stdint.h>
#define AR_LIMIT (16u * 1024u * 1024u)
#define AR_MAX_PENDING 1024
#define AR_MAX_WATCHERS 16384
#define AR_MEMORY_LIMIT (256u * 1024u * 1024u)

typedef enum { V_NULL, V_BOOL, V_INT, V_DOUBLE, V_STRING, V_ARRAY } ar_type;
typedef struct ar_value {
    struct ar_engine *engine;
    ar_type type;
    int64_t integer;
    double number;
    char *string;
    size_t length, count;
    struct ar_value **items;
    char **keys;
} ar_value;
typedef struct ar_db {
    long id;
    sqlite3 *handle;
    int busy;
    struct ar_db *next;
} ar_db;
typedef struct ar_file {
    long id;
    int fd, busy;
    struct ar_file *next;
} ar_file;
typedef enum {
    OP_READ,
    OP_WRITE,
    OP_APPEND,
    OP_UNLINK,
    OP_RENAME,
    OP_MKDIR,
    OP_RMDIR,
    OP_STAT,
    OP_LIST,
    OP_COPY,
    OP_DNS,
    OP_DB_OPEN,
    OP_DB_QUERY,
    OP_DB_CLOSE,
    OP_HTTP,
    OP_READ_RANGE,
    OP_WRITE_RANGE,
    OP_FILE_OPEN,
    OP_FILE_READ,
    OP_FILE_WRITE,
    OP_FILE_SEEK,
    OP_FILE_STAT,
    OP_FILE_SYNC,
    OP_FILE_CLOSE,
    OP_ATOMIC_WRITE
} ar_op;
typedef struct ar_job {
    long id;
    ar_op op;
    char *a, *b;
    size_t b_len;
    int64_t offset;
    size_t length;
    ar_value *params, *result;
    char error[512];
    int system_errno, committed;
    atomic_int cancelled;
    ar_db *db;
    ar_file *file;
    struct ar_engine *engine;
    struct ar_job *next, *queue_next, *done_next, *lifetime_next;
    CURL *curl;
    struct curl_slist *headers;
    char *body, *response_headers;
    size_t body_len, headers_len, body_capacity, headers_capacity;
    long status;
    int done;
} ar_job;
typedef struct ar_socket {
    curl_socket_t fd;
    int events;
    struct ar_socket *next;
} ar_socket;
typedef struct ar_engine {
    atomic_size_t memory_bytes, memory_peak, memory_rejections;
    size_t memory_limit;
    pthread_t threads[4];
    int thread_count, stopping, wake[2];
    pthread_mutex_t mutex;
    pthread_cond_t condition;
    ar_job *queue_head, *queue_tail, *done_head, *done_tail, *jobs, *allocations;
    size_t pending;
    long next_id, next_db, next_file;
    ar_db *dbs;
    ar_file *files;
    CURLM *multi;
    ar_socket *sockets;
    int running, use_epoll;
} ar_engine;
typedef struct {
    int fd, events, ready, buffered;
    long key;
} ar_watch;
void *ar_alloc(ar_engine *e, size_t bytes);
void *ar_calloc(ar_engine *e, size_t count, size_t bytes);
void *ar_realloc(ar_engine *e, void *ptr, size_t bytes);
char *ar_strdup(ar_engine *e, const char *string);
void ar_free(void *ptr);
ar_value *ar_value_new(ar_engine *e, ar_type type);
ar_value *ar_string(ar_engine *e, const char *p, size_t n);
int ar_add(ar_value *array, const char *key, ar_value *value);
void ar_value_free(ar_value *v);
ar_engine *ar_engine_new(void);
void ar_engine_free(ar_engine *e);
ar_job *ar_job_new(ar_engine *e, ar_op op);
void ar_job_free(ar_job *j);
int ar_submit(ar_engine *e, ar_job *j);
void ar_cancel(ar_engine *e, long id);
int ar_wait(ar_engine *e, ar_watch *w, size_t n, int timeout);
ar_job *ar_collect(ar_engine *e);
ar_db *ar_db_find(ar_engine *e, long id);
ar_file *ar_file_find(ar_engine *e, long id);
void ar_http_start(ar_engine *e, ar_job *j);
void ar_http_progress(ar_engine *e);
void ar_done(ar_engine *e, ar_job *j);
#endif
