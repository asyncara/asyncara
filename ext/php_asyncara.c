#ifdef HAVE_CONFIG_H
#include "config.h"
#endif
#include "php_asyncara.h"
#include "Zend/zend_exceptions.h"
#include "Zend/zend_fibers.h"
#include "ext/standard/info.h"
#include "main/fopen_wrappers.h"
#include "main/php_streams.h"
#include "native.h"
#include <errno.h>
#include <poll.h>
#include <stdlib.h>
#include <string.h>
#if PHP_VERSION_ID < 80300 || PHP_VERSION_ID >= 80600
#error Asyncara targets released PHP 8.3 through 8.5
#endif
ZEND_TLS ar_engine *engine;
ZEND_TLS bool hooks_enabled;
static void (*previous_execute_internal)(zend_execute_data *, zval *);
static ar_engine *get_engine(void) {
    if (!engine)
        engine = ar_engine_new();
    if (!engine)
        zend_throw_exception(zend_ce_exception, "Cannot initialize native runtime", 0);
    return engine;
}
static ar_value *copy_value(ar_engine *e, zval *z, unsigned depth, size_t *bytes) {
    ZVAL_DEREF(z);
    if (depth > 4 || *bytes > AR_LIMIT)
        return NULL;
    ar_value *v = NULL;
    switch (Z_TYPE_P(z)) {
    case IS_NULL:
        return ar_value_new(e, V_NULL);
    case IS_TRUE:
    case IS_FALSE:
        v = ar_value_new(e, V_BOOL);
        if (v)
            v->integer = Z_TYPE_P(z) == IS_TRUE;
        return v;
    case IS_LONG:
        v = ar_value_new(e, V_INT);
        if (v)
            v->integer = Z_LVAL_P(z);
        return v;
    case IS_DOUBLE:
        v = ar_value_new(e, V_DOUBLE);
        if (v)
            v->number = Z_DVAL_P(z);
        return v;
    case IS_STRING:
        *bytes += Z_STRLEN_P(z);
        return *bytes <= AR_LIMIT ? ar_string(e, Z_STRVAL_P(z), Z_STRLEN_P(z)) : NULL;
    case IS_ARRAY: {
        v = ar_value_new(e, V_ARRAY);
        if (!v)
            return NULL;
        zend_string *key;
        zval *item;
        ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(z), key, item) {
            *bytes += sizeof(ar_value) + (key ? ZSTR_LEN(key) : 0);
            if ((key && memchr(ZSTR_VAL(key), 0, ZSTR_LEN(key))) ||
                ar_add(v, key ? ZSTR_VAL(key) : NULL, copy_value(e, item, depth + 1, bytes))) {
                ar_value_free(v);
                return NULL;
            }
        }
        ZEND_HASH_FOREACH_END();
        return v;
    }
    default:
        return NULL;
    }
}
static void export_value(ar_value *v, zval *z) {
    if (!v) {
        ZVAL_NULL(z);
        return;
    }
    switch (v->type) {
    case V_NULL:
        ZVAL_NULL(z);
        break;
    case V_BOOL:
        ZVAL_BOOL(z, v->integer);
        break;
    case V_INT:
        ZVAL_LONG(z, v->integer);
        break;
    case V_DOUBLE:
        ZVAL_DOUBLE(z, v->number);
        break;
    case V_STRING:
        ZVAL_STRINGL(z, v->string, v->length);
        break;
    case V_ARRAY:
        array_init_size(z, v->count);
        for (size_t i = 0; i < v->count; i++) {
            zval item;
            export_value(v->items[i], &item);
            if (v->keys[i])
                zend_hash_str_update(Z_ARRVAL_P(z), v->keys[i], strlen(v->keys[i]), &item);
            else
                add_next_index_zval(z, &item);
        }
    }
}
static char *string_arg(ar_engine *e, HashTable *args, zend_ulong index, bool binary, size_t *length) {
    zval *z = zend_hash_index_find(args, index);
    if (z)
        ZVAL_DEREF(z);
    if (!z || Z_TYPE_P(z) != IS_STRING || Z_STRLEN_P(z) > AR_LIMIT ||
        (!binary && memchr(Z_STRVAL_P(z), 0, Z_STRLEN_P(z)))) {
        zend_value_error("Argument array index %lu must contain a bounded %sstring", index,
                         binary ? "" : "NUL-free ");
        return NULL;
    }
    if (length)
        *length = Z_STRLEN_P(z);
    char *s = ar_alloc(e, Z_STRLEN_P(z) + 1);
    if (!s) {
        zend_throw_exception(zend_ce_exception, "Native memory limit or allocation failure", 0);
        return NULL;
    }
    memcpy(s, Z_STRVAL_P(z), Z_STRLEN_P(z) + 1);
    return s;
}
/* Snapshot PHP's working directory before handing a path to another thread.
 * Do not resolve symlinks here: unlink/rename must retain link semantics. */
static bool capture_path(ar_engine *e, char **path) {
    if (!(*path)[0]) {
        zend_value_error("Filesystem path must not be empty");
        return false;
    }
    if ((*path)[0] != '/') {
        char cwd[MAXPATHLEN];
        if (!VCWD_GETCWD(cwd, sizeof(cwd))) {
            zend_throw_exception(zend_ce_exception, "Cannot capture working directory", 0);
            return false;
        }
        size_t a = strlen(cwd), b = strlen(*path);
        if (a + b + 2 > MAXPATHLEN) {
            zend_value_error("Filesystem path is too long");
            return false;
        }
        char *absolute = ar_alloc(e, a + b + 2);
        if (!absolute) {
            zend_throw_exception(zend_ce_exception, "Native memory limit or allocation failure", 0);
            return false;
        }
        memcpy(absolute, cwd, a);
        absolute[a] = '/';
        memcpy(absolute + a + 1, *path, b + 1);
        ar_free(*path);
        *path = absolute;
    }
    if (php_check_open_basedir(*path)) {
        zend_value_error("Path violates open_basedir");
        return false;
    }
    return true;
}
static bool valid_http_options(ar_value *v) {
    if (!v || v->type != V_ARRAY)
        return false;
    for (size_t i = 0; i < v->count; i++) {
        char *key = v->keys[i];
        ar_value *x = v->items[i];
        if (!key)
            return false;
        if (!strcmp(key, "followRedirects")) {
            if (x->type != V_BOOL)
                return false;
        } else if (!strcmp(key, "headers")) {
            if (x->type != V_ARRAY)
                return false;
            for (size_t h = 0; h < x->count; h++) {
                ar_value *s = x->items[h];
                if (s->type != V_STRING || memchr(s->string, 0, s->length) || strpbrk(s->string, "\r\n"))
                    return false;
            }
        } else if (!strcmp(key, "method") || !strcmp(key, "proxy") || !strcmp(key, "caFile")) {
            if (x->type != V_STRING || memchr(x->string, 0, x->length) || strpbrk(x->string, "\r\n"))
                return false;
        } else
            return false;
    }
    return true;
}
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_info, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_submit, 0, 2, IS_LONG, 0)
ZEND_ARG_TYPE_INFO(0, operation, IS_STRING, 0)
ZEND_ARG_TYPE_INFO(0, arguments, IS_ARRAY, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_poll, 0, 3, IS_ARRAY, 0)
ZEND_ARG_TYPE_INFO(0, readable, IS_ARRAY, 0)
ZEND_ARG_TYPE_INFO(0, writable, IS_ARRAY, 0)
ZEND_ARG_TYPE_INFO(0, timeoutMs, IS_LONG, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_cancel, 0, 1, IS_VOID, 0)
ZEND_ARG_TYPE_INFO(0, id, IS_LONG, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_hooks, 0, 1, _IS_BOOL, 0)
ZEND_ARG_TYPE_INFO(0, enabled, _IS_BOOL, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_memory_limit, 0, 1, IS_VOID, 0)
ZEND_ARG_TYPE_INFO(0, bytes, IS_LONG, 0)
ZEND_END_ARG_INFO()
PHP_FUNCTION(asyncara_memory_limit) {
    zend_long bytes;
    ZEND_PARSE_PARAMETERS_START(1, 1) Z_PARAM_LONG(bytes) ZEND_PARSE_PARAMETERS_END();
    if (bytes < 1048576 || bytes > 1073741824) {
        zend_value_error("Native buffer memory limit must be 1 MiB..1 GiB");
        RETURN_THROWS();
    }
    ar_engine *e = get_engine();
    if (!e)
        RETURN_THROWS();
    /* Limits are immutable after the first submission: workers may read them. */
    if (e->next_id) {
        zend_throw_exception(zend_ce_exception, "Configure native memory before submitting operations", 0);
        RETURN_THROWS();
    }
    e->memory_limit = (size_t)bytes;
}
PHP_FUNCTION(asyncara_info) {
    ZEND_PARSE_PARAMETERS_NONE();
    ar_engine *e = get_engine();
    if (!e)
        RETURN_THROWS();
    array_init(return_value);
    add_assoc_string(return_value, "version", PHP_ASYNCARA_VERSION);
#ifdef __linux__
    add_assoc_string(return_value, "eventLoopBackend", e->use_epoll ? "epoll" : "poll");
#else
    add_assoc_string(return_value, "eventLoopBackend", "poll");
#endif
    add_assoc_string(return_value, "filesystemBackend", "pthread-workers");
    add_assoc_string(return_value, "networkBackend", "nonblocking-streams+curl-multi");
    add_assoc_string(return_value, "dnsBackend", "worker-getaddrinfo");
    add_assoc_string(return_value, "sqliteVersion", sqlite3_libversion());
    add_assoc_string(return_value, "curlVersion", curl_version_info(CURLVERSION_NOW)->version);
    add_assoc_bool(return_value, "userlandThreads", 0);
    add_assoc_bool(return_value, "ioUring", 0);
#ifdef HAVE_ASYNCARA_PQ
    add_assoc_bool(return_value, "postgresql", 1);
#else
    add_assoc_bool(return_value, "postgresql", 0);
#endif
#ifdef HAVE_ASYNCARA_MYSQL
    add_assoc_bool(return_value, "mysql", 1);
#else
    add_assoc_bool(return_value, "mysql", 0);
#endif
    add_assoc_long(return_value, "nativeWorkers", e->thread_count);
    add_assoc_long(return_value, "pendingOperations", e->pending);
    add_assoc_long(return_value, "nativeBufferLimit", AR_LIMIT);
    add_assoc_long(return_value, "nativeQueueLimit", AR_MAX_PENDING);
    add_assoc_long(return_value, "nativeMemoryBytes", atomic_load(&e->memory_bytes));
    add_assoc_long(return_value, "nativeMemoryPeak", atomic_load(&e->memory_peak));
    add_assoc_long(return_value, "nativeMemoryRejections", atomic_load(&e->memory_rejections));
    add_assoc_long(return_value, "nativeMemoryLimit", e->memory_limit);
    size_t file_count = 0;
    for (ar_file *file = e->files; file; file = file->next)
        file_count++;
    add_assoc_long(return_value, "openFileHandles", file_count);
}
PHP_FUNCTION(asyncara_submit) {
    char *name;
    size_t len;
    HashTable *args;
    ZEND_PARSE_PARAMETERS_START(2, 2)
    Z_PARAM_STRING(name, len) Z_PARAM_ARRAY_HT(args) ZEND_PARSE_PARAMETERS_END();
    static const char *names[] = {"read",       "write",       "append",       "delete",       "rename",
                                  "mkdir",      "rmdir",       "stat",         "list",         "copy",
                                  "dns",        "sqlite_open", "sqlite_query", "sqlite_close", "http",
                                  "read_range", "write_range", "file_open",    "file_read",    "file_write",
                                  "file_seek",  "file_stat",   "file_sync",    "file_close"};
    size_t op;
    for (op = 0; op < sizeof(names) / sizeof(*names); op++)
        if (strlen(names[op]) == len && !memcmp(names[op], name, len))
            break;
    if (op == sizeof(names) / sizeof(*names)) {
        zend_value_error("Unknown native operation");
        RETURN_THROWS();
    }
    ar_engine *e = get_engine();
    if (!e)
        RETURN_THROWS();
    if (e->pending >= AR_MAX_PENDING) {
        zend_throw_exception(zend_ce_exception, "Native operation queue limit reached", 0);
        RETURN_THROWS();
    }
    ar_job *j = ar_job_new(e, (ar_op)op);
    if (!j) {
        zend_throw_exception(zend_ce_exception, "Native memory limit or allocation failure", 0);
        RETURN_THROWS();
    }
    if (op >= OP_FILE_OPEN) {
        if (op == OP_FILE_OPEN) {
            j->a = string_arg(e, args, 0, false, NULL);
            if (!j->a)
                goto error;
            if (!capture_path(e, &j->a)) {
                goto error;
            }
            j->b = string_arg(e, args, 1, false, NULL);
            if (!j->b)
                goto error;
            const char *modes[] = {"r", "r+", "rb", "r+b", "rb+", "w", "w+", "wb", "w+b", "wb+",
                                   "a", "a+", "ab", "a+b", "ab+", "x", "x+", "xb", "x+b", "xb+"};
            bool valid = false;
            for (size_t m = 0; m < sizeof(modes) / sizeof(*modes); m++)
                if (!strcmp(j->b, modes[m]))
                    valid = true;
            if (!valid) {
                zend_value_error("Unsupported file mode");
                goto error;
            }
            size_t count = 0;
            for (ar_file *file = e->files; file; file = file->next)
                count++;
            if (count >= 128) {
                zend_value_error("Native file handle limit reached");
                goto error;
            }
            j->file = ar_calloc(e, 1, sizeof(ar_file));
            if (!j->file) {
                zend_throw_exception(zend_ce_exception, "Native memory limit or allocation failure", 0);
                goto error;
            }
            j->file->id = ++e->next_file;
            j->file->fd = -1;
            j->file->next = e->files;
            e->files = j->file;
        } else {
            zval *id = zend_hash_index_find(args, 0);
            if (!id || Z_TYPE_P(id) != IS_LONG || !(j->file = ar_file_find(e, Z_LVAL_P(id))) ||
                j->file->busy || j->file->fd < 0) {
                zend_value_error("Native file handle missing, closed or busy");
                goto error;
            }
            if (op == OP_FILE_READ) {
                zval *length = zend_hash_index_find(args, 1);
                if (!length || Z_TYPE_P(length) != IS_LONG || Z_LVAL_P(length) < 1 ||
                    Z_LVAL_P(length) > AR_LIMIT) {
                    zend_value_error("Read length must be 1..16777216 bytes");
                    goto error;
                }
                j->length = Z_LVAL_P(length);
            } else if (op == OP_FILE_WRITE) {
                j->b = string_arg(e, args, 1, true, &j->b_len);
                if (!j->b)
                    goto error;
            } else if (op == OP_FILE_SEEK) {
                zval *offset = zend_hash_index_find(args, 1), *origin = zend_hash_index_find(args, 2);
                if (!offset || Z_TYPE_P(offset) != IS_LONG || !origin || Z_TYPE_P(origin) != IS_LONG ||
                    (Z_LVAL_P(origin) != SEEK_SET && Z_LVAL_P(origin) != SEEK_CUR &&
                     Z_LVAL_P(origin) != SEEK_END)) {
                    zend_value_error("Invalid seek offset or origin");
                    goto error;
                }
                j->offset = Z_LVAL_P(offset);
                j->length = Z_LVAL_P(origin);
            }
        }
    } else if (op == OP_DB_QUERY || op == OP_DB_CLOSE) {
        zval *id = zend_hash_index_find(args, 0);
        if (!id || Z_TYPE_P(id) != IS_LONG || !(j->db = ar_db_find(e, Z_LVAL_P(id))) || j->db->busy ||
            !j->db->handle) {
            zend_value_error("SQLite connection missing, closed or busy");
            goto error;
        }
        if (op == OP_DB_QUERY) {
            j->a = string_arg(e, args, 1, false, NULL);
            if (!j->a)
                goto error;
            zval *params = zend_hash_index_find(args, 2);
            size_t bytes = 0;
            if (!params || Z_TYPE_P(params) != IS_ARRAY || !(j->params = copy_value(e, params, 0, &bytes))) {
                zend_value_error("Invalid SQLite parameters");
                goto error;
            }
            for (size_t i = 0; i < j->params->count; i++)
                if (j->params->items[i]->type == V_ARRAY) {
                    zend_value_error("SQLite parameters must be scalar or null");
                    goto error;
                }
        }
    } else {
        j->a = string_arg(e, args, 0, false, NULL);
        if (!j->a)
            goto error;
        if (op <= OP_COPY || op == OP_READ_RANGE || op == OP_WRITE_RANGE ||
            (op == OP_DB_OPEN && strcmp(j->a, ":memory:")))
            if (!capture_path(e, &j->a)) {
                goto error;
            }
        if (op == OP_WRITE || op == OP_APPEND || op == OP_RENAME || op == OP_COPY || op == OP_WRITE_RANGE) {
            j->b =
                string_arg(e, args, 1, op == OP_WRITE || op == OP_APPEND || op == OP_WRITE_RANGE, &j->b_len);
            if (!j->b)
                goto error;
            if ((op == OP_RENAME || op == OP_COPY) && !capture_path(e, &j->b)) {
                goto error;
            }
        }
        if (op == OP_READ_RANGE || op == OP_WRITE_RANGE) {
            zval *off = zend_hash_index_find(args, 2), *length = zend_hash_index_find(args, 3);
            if (!off || Z_TYPE_P(off) != IS_LONG || Z_LVAL_P(off) < 0) {
                zend_value_error("Invalid file offset");
                goto error;
            }
            j->offset = Z_LVAL_P(off);
            if (op == OP_READ_RANGE) {
                if (!length || Z_TYPE_P(length) != IS_LONG || Z_LVAL_P(length) < 1 ||
                    Z_LVAL_P(length) > AR_LIMIT) {
                    zend_value_error("Invalid read length");
                    goto error;
                }
                j->length = (size_t)Z_LVAL_P(length);
            }
        }
        if (op == OP_DB_OPEN) {
            size_t db_count = 0;
            for (ar_db *d = e->dbs; d; d = d->next)
                if (d->busy || d->handle)
                    db_count++;
            if (db_count >= 128) {
                zend_value_error("SQLite connection limit reached");
                goto error;
            }
            j->db = ar_calloc(e, 1, sizeof(ar_db));
            if (!j->db) {
                zend_throw_exception(zend_ce_exception, "Native memory limit or allocation failure", 0);
                goto error;
            }
            j->db->id = ++e->next_db;
            j->db->next = e->dbs;
            e->dbs = j->db;
        }
        if (op == OP_HTTP) {
            zval *body = zend_hash_index_find(args, 1), *opts = zend_hash_index_find(args, 2),
                 *timeout = zend_hash_index_find(args, 3);
            size_t bytes = 0;
            if (body && Z_TYPE_P(body) != IS_NULL) {
                j->b = string_arg(e, args, 1, true, &j->b_len);
                if (!j->b)
                    goto error;
            }
            if (!opts || !(j->params = copy_value(e, opts, 0, &bytes)) || !valid_http_options(j->params)) {
                zend_value_error("Invalid HTTP options");
                goto error;
            }
            if (!timeout || Z_TYPE_P(timeout) != IS_LONG || Z_LVAL_P(timeout) < 1 ||
                Z_LVAL_P(timeout) > 3600000) {
                zend_value_error("HTTP timeout must be 1..3600000 milliseconds");
                goto error;
            }
            j->length = Z_LVAL_P(timeout);
        }
    }
    if (ar_submit(e, j)) {
        zend_throw_exception(zend_ce_exception, "Cannot submit native operation", 0);
        goto error;
    }
    RETURN_LONG(j->id);
error:
    if (j->op == OP_FILE_OPEN && j->file && !j->file->busy) {
        ar_file **entry = &e->files;
        while (*entry && *entry != j->file)
            entry = &(*entry)->next;
        if (*entry)
            *entry = j->file->next;
        ar_free(j->file);
        j->file = NULL;
    }
    if (j->op == OP_DB_OPEN && j->db && !j->db->busy) {
        ar_db **entry = &e->dbs;
        while (*entry && *entry != j->db)
            entry = &(*entry)->next;
        if (*entry)
            *entry = j->db->next;
        ar_free(j->db);
        j->db = NULL;
    }
    ar_job_free(j);
    RETURN_THROWS();
}
PHP_FUNCTION(asyncara_poll) {
    HashTable *readers, *writers;
    zend_long timeout;
    ZEND_PARSE_PARAMETERS_START(3, 3)
    Z_PARAM_ARRAY_HT(readers) Z_PARAM_ARRAY_HT(writers) Z_PARAM_LONG(timeout) ZEND_PARSE_PARAMETERS_END();
    if (timeout < -1 || timeout > 3600000) {
        zend_value_error("Invalid poll timeout");
        RETURN_THROWS();
    }
    size_t n = zend_hash_num_elements(readers) + zend_hash_num_elements(writers);
    if (n > AR_MAX_WATCHERS - 1) {
        zend_value_error("Readiness watcher limit exceeded");
        RETURN_THROWS();
    }
    ar_watch *w = ecalloc(n ? n : 1, sizeof(*w));
    size_t i = 0;
    for (int mode = 0; mode < 2; mode++) {
        HashTable *table = mode ? writers : readers;
        zend_ulong key;
        zend_string *string_key;
        zval *z;
        ZEND_HASH_FOREACH_KEY_VAL(table, key, string_key, z) {
            php_stream *stream = NULL;
            if (string_key || (Z_TYPE_P(z) != IS_RESOURCE && Z_TYPE_P(z) != IS_LONG)) {
                efree(w);
                zend_value_error("Watchers require integer keys and stream resources");
                RETURN_THROWS();
            }
            if (Z_TYPE_P(z) == IS_LONG) {
                if (Z_LVAL_P(z) < 0 || Z_LVAL_P(z) > INT_MAX) {
                    efree(w);
                    zend_value_error("Invalid descriptor");
                    RETURN_THROWS();
                }
                w[i++] = (ar_watch){.fd = (int)Z_LVAL_P(z), .events = mode ? POLLOUT : POLLIN, .key = key};
                continue;
            }
            php_stream_from_zval_no_verify(stream, z);
            int fd;
            if (!stream ||
                php_stream_cast(stream, PHP_STREAM_AS_FD_FOR_SELECT | PHP_STREAM_CAST_INTERNAL, (void *)&fd,
                                0) != SUCCESS ||
                fd < 0) {
                efree(w);
                zend_value_error("Stream has no pollable descriptor");
                RETURN_THROWS();
            }
            w[i++] = (ar_watch){.fd = fd, .events = mode ? POLLOUT : POLLIN, .key = key};
        }
        ZEND_HASH_FOREACH_END();
    }
    ar_engine *e = get_engine();
    if (!e) {
        efree(w);
        RETURN_THROWS();
    }
    if (ar_wait(e, w, n, (int)timeout) < 0) {
        efree(w);
        zend_throw_exception_ex(zend_ce_exception, errno, "Native wait failed: %s", strerror(errno));
        RETURN_THROWS();
    }
    array_init(return_value);
    zval r, wr, results;
    array_init(&r);
    array_init(&wr);
    array_init(&results);
    for (i = 0; i < n; i++)
        if (w[i].ready)
            add_next_index_long(w[i].events == POLLIN ? &r : &wr, w[i].key);
    efree(w);
    ar_job *j = ar_collect(e);
    while (j) {
        ar_job *next = j->done_next;
        zval item, value;
        array_init(&item);
        add_assoc_long(&item, "id", j->id);
        if (j->error[0])
            add_assoc_string(&item, "error", j->error);
        else
            add_assoc_null(&item, "error");
        export_value(j->result, &value);
        add_assoc_zval(&item, "value", &value);
        add_next_index_zval(&results, &item);
        ar_job_free(j);
        j = next;
    }
    add_assoc_zval(return_value, "read", &r);
    add_assoc_zval(return_value, "write", &wr);
    add_assoc_zval(return_value, "results", &results);
}
PHP_FUNCTION(asyncara_cancel) {
    zend_long id;
    ZEND_PARSE_PARAMETERS_START(1, 1) Z_PARAM_LONG(id) ZEND_PARSE_PARAMETERS_END();
    if (engine)
        ar_cancel(engine, id);
}
PHP_FUNCTION(asyncara_hooks) {
    bool enabled;
    ZEND_PARSE_PARAMETERS_START(1, 1) Z_PARAM_BOOL(enabled) ZEND_PARSE_PARAMETERS_END();
    bool old = hooks_enabled;
    hooks_enabled = enabled;
    RETURN_BOOL(old);
}
static PHP_FUNCTION(hooked_sleep) {
    zend_long duration;
    ZEND_PARSE_PARAMETERS_START(1, 1) Z_PARAM_LONG(duration) ZEND_PARSE_PARAMETERS_END();
    if (duration < 0) {
        zend_argument_value_error(1, "must be greater than or equal to 0");
        RETURN_THROWS();
    }
    bool micro = zend_string_equals_literal(execute_data->func->common.function_name, "usleep");
    zend_string *name = zend_string_init("Asyncara\\Async", sizeof("Asyncara\\Async") - 1, 0);
    zend_class_entry *ce = zend_lookup_class(name);
    zend_string_release(name);
    if (!ce) {
        zend_throw_error(NULL, "Asyncara userland library is required for hooks");
        RETURN_THROWS();
    }
    zend_function *fn = zend_hash_str_find_ptr(&ce->function_table, "sleep", 5);
    if (!fn) {
        zend_throw_error(NULL, "Asyncara sleep method missing");
        RETURN_THROWS();
    }
    zval arg, result;
    ZVAL_DOUBLE(&arg, micro ? (double)duration / 1000000.0 : (double)duration);
    ZVAL_UNDEF(&result);
    zend_call_known_function(fn, NULL, ce, &result, 1, &arg, NULL);
    zval_ptr_dtor(&result);
    if (EG(exception))
        RETURN_THROWS();
    if (micro)
        RETURN_NULL();
    RETURN_LONG(0);
}
static void execute_hook(zend_execute_data *execute_data, zval *return_value) {
    zend_function *fn = execute_data->func;
    if (hooks_enabled && !fn->common.scope && EG(current_fiber_context) != EG(main_fiber_context) &&
        fn->common.function_name &&
        (zend_string_equals_literal(fn->common.function_name, "sleep") ||
         zend_string_equals_literal(fn->common.function_name, "usleep"))) {
        zif_hooked_sleep(execute_data, return_value);
        return;
    }
    if (previous_execute_internal)
        previous_execute_internal(execute_data, return_value);
    else
        execute_internal(execute_data, return_value);
}
#if defined(HAVE_ASYNCARA_PQ) || defined(HAVE_ASYNCARA_MYSQL)
#include "driver_arginfo.h"
#endif
#ifdef HAVE_ASYNCARA_PQ
#include "postgresql.c"
#endif
#ifdef HAVE_ASYNCARA_MYSQL
#include "mysql.c"
#endif
static const zend_function_entry functions[] = {
    PHP_FE(asyncara_memory_limit, arginfo_memory_limit)
#ifdef HAVE_ASYNCARA_MYSQL
        PHP_FE(asyncara_mysql_open, arginfo_pg_open) PHP_FE(asyncara_mysql_query, arginfo_pg_query)
            PHP_FE(asyncara_mysql_step, arginfo_mysql_step) PHP_FE(asyncara_mysql_close, arginfo_pg_conn)
#endif
#ifdef HAVE_ASYNCARA_PQ
                PHP_FE(asyncara_pg_open, arginfo_pg_open) PHP_FE(asyncara_pg_poll, arginfo_pg_conn)
                    PHP_FE(asyncara_pg_query, arginfo_pg_query) PHP_FE(asyncara_pg_read, arginfo_pg_conn)
                        PHP_FE(asyncara_pg_close, arginfo_pg_conn)
#endif
                            PHP_FE(asyncara_info, arginfo_info) PHP_FE(asyncara_submit, arginfo_submit)
                                PHP_FE(asyncara_poll, arginfo_poll) PHP_FE(asyncara_cancel, arginfo_cancel)
                                    PHP_FE(asyncara_hooks, arginfo_hooks) PHP_FE_END};
PHP_MINIT_FUNCTION(asyncara) {
    if (curl_global_init(CURL_GLOBAL_DEFAULT) != CURLE_OK)
        return FAILURE;
#ifdef HAVE_ASYNCARA_PQ
    asyncara_pg_init(module_number);
#endif
#ifdef HAVE_ASYNCARA_MYSQL
    if (mysql_library_init(0, NULL, NULL))
        return FAILURE;
    asyncara_mysql_init(module_number);
#endif
    previous_execute_internal = zend_execute_internal;
    zend_execute_internal = execute_hook;
    return SUCCESS;
}
PHP_MSHUTDOWN_FUNCTION(asyncara) {
    if (zend_execute_internal == execute_hook)
        zend_execute_internal = previous_execute_internal;
#ifdef HAVE_ASYNCARA_MYSQL
    mysql_library_end();
#endif
    curl_global_cleanup();
    return SUCCESS;
}
PHP_RINIT_FUNCTION(asyncara) {
    engine = NULL;
    hooks_enabled = false;
    return SUCCESS;
}
PHP_RSHUTDOWN_FUNCTION(asyncara) {
    hooks_enabled = false;
    ar_engine_free(engine);
    engine = NULL;
    return SUCCESS;
}
PHP_MINFO_FUNCTION(asyncara) {
    php_info_print_table_start();
    php_info_print_table_row(2, "Asyncara support", "enabled");
    php_info_print_table_row(2, "Version", PHP_ASYNCARA_VERSION);
    php_info_print_table_row(2, "Event loop", "epoll (Linux), poll fallback");
    php_info_print_table_row(2, "Filesystem / SQLite / DNS", "bounded native pthread workers");
    php_info_print_table_row(2, "HTTP", "libcurl multi socket");
    php_info_print_table_row(2, "PHP userland threads / io_uring", "unavailable / not implemented");
    php_info_print_table_end();
}
zend_module_entry asyncara_module_entry = {STANDARD_MODULE_HEADER,
                                           "asyncara",
                                           functions,
                                           PHP_MINIT(asyncara),
                                           PHP_MSHUTDOWN(asyncara),
                                           PHP_RINIT(asyncara),
                                           PHP_RSHUTDOWN(asyncara),
                                           PHP_MINFO(asyncara),
                                           PHP_ASYNCARA_VERSION,
                                           STANDARD_MODULE_PROPERTIES};
#ifdef COMPILE_DL_ASYNCARA
ZEND_GET_MODULE(asyncara)
#endif
