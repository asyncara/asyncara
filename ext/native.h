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
#define AR_HTTP_STREAM_LIMIT 65536u
#ifndef AR_HTTP_EASY_CACHE
#define AR_HTTP_EASY_CACHE 64u
#endif
#define AR_TRACE_MAX 4096u
#define AR_TRACE_WAIT 1u
#define AR_TRACE_JOB 2u
#define AR_TRACE_SCHED 4u
#define AR_TRACE_TIMER 8u
#define AR_TRACE_IO 16u
#define AR_TRACE_LIFE 32u
#define AR_TRACE_MARK 64u
#define AR_TRACE_ALL 127u
/* Version 1: fixed numeric metadata only, no pointers or application data. */
typedef struct {
    uint64_t sequence, ns, scope;
    int64_t id, a, b, c;
    uint32_t category, code;
} ar_trace_event;
typedef struct {
    atomic_flag writer;
    atomic_uint mask;
    atomic_uint_fast64_t sequence, dropped, clock_failures, scope;
    size_t capacity, count, next;
    uint64_t written;
    ar_trace_event events[];
} ar_trace;
typedef struct {
    size_t capacity, count, bytes;
    uint64_t attempted, overwritten, dropped, clock_failures;
    unsigned mask;
} ar_trace_status;

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
    int staging, directory_fd;
    char stage_name[64];
    char *stage_target;
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
    OP_ATOMIC_WRITE,
    OP_FILE_STAGE,
    OP_FILE_PUBLISH
} ar_op;
typedef struct {
    struct ar_job *job;
    size_t index;
} ar_http_part;
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
    int http_hyper;
    CURL *curl;
    curl_mime *mime;
    struct curl_slist *headers;
    char *body, *response_headers;
    size_t body_len, headers_len, body_capacity, headers_capacity;
    int stream_response, receive_paused, headers_ready, headers_delivered, follow_redirects;
    int stream_upload, send_paused, upload_eof;
    char *upload;
    size_t upload_len, upload_offset;
    int64_t upload_size, upload_sent;
    ar_http_part *upload_parts;
    size_t upload_part_count, upload_index;
    long status;
    int done;
} ar_job;
typedef struct ar_socket {
    curl_socket_t fd;
    CURL *owner;
    int events;
    int wait_events;
    struct ar_socket *next;
} ar_socket;
typedef struct ar_engine {
    _Atomic(ar_trace *) trace;
    uint64_t request_id, request_started_ns, wait_id;
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
    void *hyper;
    int hyper_requested;
    CURLM *multi;
    CURL *http_idle[64];
    size_t http_idle_count;
    int http_max_connections, http_max_host_connections;
    uint64_t http_created, http_reused, hyper_submitted, hyper_completed;
    ar_socket *sockets;
    int running, use_epoll, http_wakeup;
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
#ifdef HAVE_ASYNCARA_HYPER
struct pollfd;
void *ar_hyper_new(size_t total, size_t host);
void ar_hyper_free(void *engine);
int ar_hyper_submit(void *engine, void *job, const unsigned char *url, size_t len, uint64_t timeout, uint64_t connect);
int ar_hyper_drive(void *engine);
int ar_hyper_cancel(void *engine, void *job);
int ar_hyper_timeout(void *engine);
size_t ar_hyper_connections(void *engine);
size_t ar_hyper_fds(void *engine, struct pollfd *fds, size_t capacity);
void ar_hyper_ready(void *engine, int fd, int events);
void ar_hyper_failed(ar_engine *e);
#endif
void ar_done(ar_engine *e, ar_job *j);
int ar_trace_configure(ar_engine *e, size_t capacity, unsigned mask);
void ar_trace_emit(ar_engine *e, unsigned category, unsigned code, int64_t id, int64_t a, int64_t b, int64_t c);
int ar_trace_read(ar_engine *e, ar_trace_event *events, ar_trace_status *status);
uint64_t ar_trace_clock(void);
static inline int ar_trace_enabled(ar_engine *e, unsigned category) {
    ar_trace *t = atomic_load_explicit(&e->trace, memory_order_acquire);
    return t && (atomic_load_explicit(&t->mask, memory_order_relaxed) & category);
}
#endif
