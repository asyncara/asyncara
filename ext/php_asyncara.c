#ifdef HAVE_CONFIG_H
#include "config.h"
#endif
#include "php_asyncara.h"
#include "Zend/zend_exceptions.h"
#include "Zend/zend_fibers.h"
#include "ext/standard/info.h"
#include "main/fopen_wrappers.h"
#include "main/php_streams.h"
#include "main/php_network.h"
#include "native.h"
#include <arpa/inet.h>
#include <errno.h>
#include <fcntl.h>
#include <poll.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#if PHP_VERSION_ID < 80300 || PHP_VERSION_ID >= 80600
#error Asyncara targets released PHP 8.3 through 8.5
#endif
ZEND_TLS ar_engine *engine;
ZEND_TLS bool hooks_enabled;
ZEND_TLS uint64_t request_id;
ZEND_TLS uint64_t request_started_ns;
static atomic_uint_fast64_t next_request_id;
static void (*previous_execute_internal)(zend_execute_data *, zval *);
static ar_engine *get_engine(void) {
    if (!engine) {
        engine = ar_engine_new();
        if (engine) { engine->request_id = request_id; engine->request_started_ns = request_started_ns; }
    }
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
static bool export_value(ar_value *v, zval *z) {
    ZVAL_NULL(z);
    if (ar_php_oom())
        return false;
    if (!v) {
        return true;
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
    case V_STRING: {
        zend_string *string = ar_php_string_init(v->string, v->length);
        if (!string)
            return false;
        ZVAL_STR(z, string);
        break;
    }
    case V_ARRAY:
        array_init_size(z, v->count);
        for (size_t i = 0; i < v->count; i++) {
            zval item;
            if (!export_value(v->items[i], &item)) {
                zval_ptr_dtor(z);
                ZVAL_NULL(z);
                return false;
            }
            if (v->keys[i])
                zend_hash_str_update(Z_ARRVAL_P(z), v->keys[i], strlen(v->keys[i]), &item);
            else
                add_next_index_zval(z, &item);
        }
    }
    if (ar_php_oom()) {
        zval_ptr_dtor(z);
        ZVAL_NULL(z);
        return false;
    }
    return true;
}
/* HTTP has a fixed result shape and remains on its native job until export.
 * Avoid constructing/destroying a second native object graph per response. */
static bool export_http_result(ar_job *j, zval *value) {
    ZVAL_NULL(value);
    if (ar_php_oom()) return false;
    array_init_size(value, 3);
    add_assoc_long(value, "status", j->status);
    zend_string *body = ar_php_string_init(j->body ? j->body : "", j->body_len);
    if (!body) goto error;
    add_assoc_str(value, "body", body);
    zend_string *headers = ar_php_string_init(j->response_headers ? j->response_headers : "", j->headers_len);
    if (!headers) goto error;
    add_assoc_str(value, "headers", headers);
    if (!ar_php_oom()) return true;
error:
    zval_ptr_dtor(value); ZVAL_NULL(value); return false;
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
/* Common buffered options need no heap tree: curl consumes this bounded view
 * synchronously and copies option strings. Never attach it to a request-owned
 * job across a Zend call, return, suspension, or worker submission. Complex
 * headers/MIME/streaming configurations retain the existing owned conversion. */
typedef struct {
    ar_value root, values[4];
    ar_value *items[4];
    char *keys[4];
} ar_http_option_view;
static bool http_option_view(zval *options, ar_http_option_view *view) {
    ZVAL_DEREF(options);
    if (Z_TYPE_P(options) != IS_ARRAY || zend_hash_num_elements(Z_ARRVAL_P(options)) != 4) return false;
    memset(view, 0, sizeof(*view));
    view->root.type = V_ARRAY; view->root.count = 4;
    view->root.items = view->items; view->root.keys = view->keys;
    zend_string *key; zval *value; size_t i = 0, bytes = 0; unsigned found = 0;
    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(options), key, value) {
        if (!key) return false;
        bytes += sizeof(ar_value) + ZSTR_LEN(key);
        ZVAL_DEREF(value);
        ar_value *v = &view->values[i];
        view->items[i] = v; view->keys[i++] = ZSTR_VAL(key);
        if (zend_string_equals_literal(key, "method") && Z_TYPE_P(value) == IS_STRING) {
            v->type = V_STRING; v->string = Z_STRVAL_P(value); v->length = Z_STRLEN_P(value); bytes += v->length; found |= 1;
        } else if (zend_string_equals_literal(key, "headers") && Z_TYPE_P(value) == IS_ARRAY && !zend_hash_num_elements(Z_ARRVAL_P(value))) {
            v->type = V_ARRAY; found |= 2;
        } else if (zend_string_equals_literal(key, "followRedirects") && (Z_TYPE_P(value) == IS_TRUE || Z_TYPE_P(value) == IS_FALSE)) {
            v->type = V_BOOL; v->integer = Z_TYPE_P(value) == IS_TRUE; found |= 4;
        } else if (zend_string_equals_literal(key, "connectTimeoutMs") && Z_TYPE_P(value) == IS_LONG) {
            v->type = V_INT; v->integer = Z_LVAL_P(value); found |= 8;
        } else return false;
        if (bytes > AR_LIMIT) return false;
    } ZEND_HASH_FOREACH_END();
    return found == 15;
}
static bool valid_http_options(ar_value *v) {
    if (!v || v->type != V_ARRAY)
        return false;
    bool files = false, multipart = false, legacy_file = false, upload = false;
    int64_t upload_size = -1;
    for (size_t i = 0; i < v->count; i++) {
        char *key = v->keys[i];
        ar_value *x = v->items[i];
        if (!key)
            return false;
        if (!strcmp(key, "followRedirects") || !strcmp(key, "streamResponse")) {
            if (x->type != V_BOOL)
                return false;
        } else if (!strcmp(key, "connectTimeoutMs")) {
            if (x->type != V_INT || x->integer < 1 || x->integer > 3600000) return false;
        } else if (!strcmp(key, "uploadSize")) {
            if (x->type != V_INT || x->integer < -1) return false;
            upload = true; upload_size = x->integer;
        } else if (!strcmp(key, "files")) {
            files = true;
            if (x->type != V_ARRAY || !x->count || x->count > 16) return false;
            for (size_t f = 0; f < x->count; f++) {
                ar_value *file = x->items[f]; unsigned found = 0;
                if (x->keys[f] || file->type != V_ARRAY || file->count != 3) return false;
                for (size_t m = 0; m < file->count; m++) {
                    const char *name = file->keys[m]; ar_value *value = file->items[m];
                    if (!name || value->type != V_STRING || !value->length || value->length > 256 ||
                        memchr(value->string, 0, value->length) || strpbrk(value->string, "\r\n")) return false;
                    if (!strcmp(name, "field")) found |= 1;
                    else if (!strcmp(name, "name")) found |= 2;
                    else if (!strcmp(name, "type")) found |= 4;
                    else return false;
                }
                if (found != 7) return false;
            }
        } else if (!strcmp(key, "multipart")) {
            multipart = true;
            if (x->type != V_ARRAY || x->count > 64) return false;
            size_t total = 0;
            for (size_t h = 0; h < x->count; h++) {
                ar_value *field = x->items[h];
                if (!x->keys[h] || strlen(x->keys[h]) > 256 || strpbrk(x->keys[h], "\r\n") ||
                    field->type != V_STRING || field->length > 65536) return false;
                total += field->length;
            }
            if (total > 1048576) return false;
        } else if (!strcmp(key, "headers")) {
            if (x->type != V_ARRAY || x->count > 256) return false;
            size_t bytes = 0;
            for (size_t h = 0; h < x->count; h++) {
                ar_value *s = x->items[h];
                if (s->type != V_STRING || s->length > 65536 || memchr(s->string, 0, s->length) || strpbrk(s->string, "\r\n")) return false;
                bytes += s->length + 2;
                if (bytes > 65536) return false;
            }
        } else if (!strcmp(key, "method") || !strcmp(key, "proxy") || !strcmp(key, "caFile") ||
                   !strcmp(key, "fileField") || !strcmp(key, "fileName") || !strcmp(key, "fileType")) {
            if (x->type != V_STRING || memchr(x->string, 0, x->length) || strpbrk(x->string, "\r\n"))
                return false;
            if (!strncmp(key, "file", 4) && (!x->length || x->length > 256)) return false;
            if (!strncmp(key, "file", 4)) legacy_file = true;
        } else
            return false;
    }
    /* Multiple files use one sequential unknown-length producer. Do not
     * silently ignore file descriptors without the matching MIME/upload mode. */
    return !files || (multipart && upload && upload_size == -1 && !legacy_file);
}
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_info, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()
/* Resources have no legal PHP return-type declaration (including DEBUG builds). */
ZEND_BEGIN_ARG_INFO_EX(arginfo_udp_bind, 0, 0, 2)
ZEND_ARG_TYPE_INFO(0, address, IS_STRING, 0)
ZEND_ARG_TYPE_INFO(0, port, IS_LONG, 0)
ZEND_END_ARG_INFO()
PHP_FUNCTION(asyncara_udp_bind) {
    char *address;
    size_t length;
    zend_long port;
    ZEND_PARSE_PARAMETERS_START(2, 2)
    Z_PARAM_STRING(address, length)
    Z_PARAM_LONG(port)
    ZEND_PARSE_PARAMETERS_END();
    struct sockaddr_storage storage = {0};
    struct sockaddr_in *v4 = (struct sockaddr_in *)&storage;
    struct sockaddr_in6 *v6 = (struct sockaddr_in6 *)&storage;
    socklen_t size;
    if (port < 0 || port > 65535 || memchr(address, 0, length)) {
        zend_value_error("UDP bind requires a numeric IP and valid port");
        RETURN_THROWS();
    }
    if (inet_pton(AF_INET, address, &v4->sin_addr) == 1) {
        v4->sin_family = AF_INET;
        v4->sin_port = htons((uint16_t)port);
        size = sizeof(*v4);
    } else if (inet_pton(AF_INET6, address, &v6->sin6_addr) == 1) {
        v6->sin6_family = AF_INET6;
        v6->sin6_port = htons((uint16_t)port);
        size = sizeof(*v6);
    } else {
        zend_value_error("UDP bind requires a numeric IP and valid port");
        RETURN_THROWS();
    }
    /* Allocate/register PHP ownership before acquiring an OS descriptor. This
     * exported PHP API supplies generic socket sendto/recvfrom/cast/close ops;
     * it does not bind or enable PHP's implicit SO_REUSEADDR server default. */
    php_stream *stream = php_stream_sock_open_from_socket(-1, NULL);
    if (!stream) {
        zend_throw_exception(zend_ce_exception, "Cannot allocate UDP stream", 0);
        RETURN_THROWS();
    }
    php_netstream_data_t *data = stream->abstract;
    int flags = SOCK_DGRAM;
#ifdef SOCK_CLOEXEC
    flags |= SOCK_CLOEXEC;
#endif
#ifdef SOCK_NONBLOCK
    flags |= SOCK_NONBLOCK;
#endif
    data->socket = socket(storage.ss_family, flags, 0);
    data->is_blocked = false;
    if (data->socket < 0 || fcntl(data->socket, F_SETFD, FD_CLOEXEC) < 0 ||
        php_set_sock_blocking(data->socket, false) != SUCCESS ||
        bind(data->socket, (struct sockaddr *)&storage, size) < 0) {
        int saved = errno;
        php_stream_close(stream);
        zend_throw_exception_ex(zend_ce_exception, saved, "UDP bind: %s", strerror(saved));
        RETURN_THROWS();
    }
    php_stream_to_zval(stream, return_value);
}
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_http_headers, 0, 1, IS_ARRAY, 0)
ZEND_ARG_TYPE_INFO(0, headers, IS_STRING, 0)
ZEND_END_ARG_INFO()
/* Response metadata conversion only. libcurl continues to own HTTP parsing.
 * Match the public Response constructor, including duplicate fields, interim
 * status blocks and PHP's default trim bytes. No native job or worker owns a
 * Zend value here; this runs on the interpreter thread after collection. */
static int http_trim_byte(unsigned char c) {
    return c == ' ' || c == '\t' || c == '\r' || c == '\n' || c == '\0' || c == '\v';
}
PHP_FUNCTION(asyncara_http_headers) {
    zend_string *raw;
    ZEND_PARSE_PARAMETERS_START(1, 1)
    Z_PARAM_STR(raw)
    ZEND_PARSE_PARAMETERS_END();
    array_init(return_value);
    const char *p = ZSTR_VAL(raw), *end = p + ZSTR_LEN(raw);
    while (p < end) {
        const char *next = p;
        while (next < end && !(next + 1 < end && next[0] == '\r' && next[1] == '\n')) next++;
        if (next - p >= 5 && !memcmp(p, "HTTP/", 5)) {
            zend_hash_clean(Z_ARRVAL_P(return_value));
        } else {
            const char *colon = memchr(p, ':', (size_t)(next - p));
            if (colon) {
                const char *name = p, *name_end = colon, *value = colon + 1, *value_end = next;
                while (name < name_end && http_trim_byte((unsigned char)*name)) name++;
                while (name_end > name && http_trim_byte((unsigned char)name_end[-1])) name_end--;
                while (value < value_end && http_trim_byte((unsigned char)*value)) value++;
                while (value_end > value && http_trim_byte((unsigned char)value_end[-1])) value_end--;
                zend_string *key = zend_string_alloc((size_t)(name_end - name), 0);
                for (size_t i = 0; i < ZSTR_LEN(key); i++) {
                    unsigned char c = (unsigned char)name[i];
                    ZSTR_VAL(key)[i] = c >= 'A' && c <= 'Z' ? c + ('a' - 'A') : c;
                }
                ZSTR_VAL(key)[ZSTR_LEN(key)] = 0;
                zval *values = zend_symtable_find(Z_ARRVAL_P(return_value), key);
                if (!values) {
                    zval list; array_init(&list);
                    values = zend_symtable_update(Z_ARRVAL_P(return_value), key, &list);
                }
                zend_string_release(key);
                add_next_index_stringl(values, value, (size_t)(value_end - value));
            }
        }
        p = next < end ? next + 2 : end;
    }
}
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_http_read, 0, 1, IS_STRING, 0)
ZEND_ARG_TYPE_INFO(0, id, IS_LONG, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_http_head, 0, 1, IS_ARRAY, 1)
ZEND_ARG_TYPE_INFO(0, id, IS_LONG, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_http_write, 0, 3, _IS_BOOL, 0)
ZEND_ARG_TYPE_INFO(0, id, IS_LONG, 0)
ZEND_ARG_TYPE_INFO(0, data, IS_STRING, 0)
ZEND_ARG_TYPE_INFO(0, eof, _IS_BOOL, 0)
ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, part, IS_LONG, 0, "0")
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
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_http_limits, 0, 2, IS_VOID, 0)
ZEND_ARG_TYPE_INFO(0, total, IS_LONG, 0)
ZEND_ARG_TYPE_INFO(0, perHost, IS_LONG, 0)
ZEND_END_ARG_INFO()
PHP_FUNCTION(asyncara_http_limits) {
    zend_long total, per_host;
    ZEND_PARSE_PARAMETERS_START(2, 2) Z_PARAM_LONG(total) Z_PARAM_LONG(per_host) ZEND_PARSE_PARAMETERS_END();
    if (total < 1 || total > 256 || per_host < 1 || per_host > total) {
        zend_value_error("HTTP limits require 1 <= perHost <= total <= 256"); RETURN_THROWS();
    }
    ar_engine *e = get_engine(); if (!e) RETURN_THROWS();
    if (e->next_id) { zend_throw_exception(zend_ce_exception, "Configure HTTP limits before submitting operations", 0); RETURN_THROWS(); }
    e->http_max_connections = (int)total; e->http_max_host_connections = (int)per_host;
}
PHP_FUNCTION(asyncara_info) {
    ZEND_PARSE_PARAMETERS_NONE();
    ar_engine *e = get_engine();
    if (!e)
        RETURN_THROWS();
    array_init(return_value);
    add_assoc_string(return_value, "version", PHP_ASYNCARA_VERSION);
    add_assoc_string(return_value, "buildPhpVersion", PHP_VERSION);
#ifdef __linux__
    add_assoc_string(return_value, "eventLoopBackend", e->use_epoll ? "epoll" : "poll");
#else
    add_assoc_string(return_value, "eventLoopBackend", "poll");
#endif
    add_assoc_string(return_value, "filesystemBackend", "pthread-workers");
    add_assoc_string(return_value, "networkBackend", "nonblocking-streams+curl-multi");
    add_assoc_string(return_value, "httpBackend", e->hyper_requested ? "hyper-prototype" : "curl-multi");
#ifdef HAVE_ASYNCARA_HYPER
    add_assoc_bool(return_value, "hyperPrototypeBuilt", 1);
    add_assoc_long(return_value, "hyperConnections", e->hyper ? ar_hyper_connections(e->hyper) : 0);
#else
    add_assoc_bool(return_value, "hyperPrototypeBuilt", 0);
#endif
    add_assoc_long(return_value, "hyperSubmitted", e->hyper_submitted);
    add_assoc_long(return_value, "hyperCompleted", e->hyper_completed);
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
    ar_trace *trace = atomic_load_explicit(&e->trace, memory_order_acquire);
    add_assoc_long(return_value, "nativeDiagnosticBytes", trace ? sizeof(*trace) + trace->capacity * sizeof(ar_trace_event) : 0);
    add_assoc_long(return_value, "waitControlBytes", e->wait_control_bytes);
    add_assoc_long(return_value, "epollCreated", e->epoll_created);
    add_assoc_long(return_value, "epollReused", e->epoll_reused);
    size_t file_count = 0;
    for (ar_file *file = e->files; file; file = file->next)
        file_count++;
    add_assoc_long(return_value, "openFileHandles", file_count);
    size_t sqlite_count = 0, http_sockets = 0, queued = 0;
    for (ar_db *db = e->dbs; db; db = db->next) sqlite_count++;
    for (ar_socket *socket = e->sockets; socket; socket = socket->next) http_sockets++;
    pthread_mutex_lock(&e->mutex);
    for (ar_job *job = e->queue_head; job; job = job->queue_next) queued++;
    pthread_mutex_unlock(&e->mutex);
    add_assoc_long(return_value, "sqliteConnections", sqlite_count);
    add_assoc_long(return_value, "httpReadinessSockets", http_sockets);
    add_assoc_long(return_value, "httpMaxConnections", e->http_max_connections);
    add_assoc_long(return_value, "httpMaxHostConnections", e->http_max_host_connections);
    add_assoc_long(return_value, "httpCachedHandles", e->http_idle_count);
    add_assoc_long(return_value, "httpHandlesCreated", e->http_created);
    add_assoc_long(return_value, "httpHandlesReused", e->http_reused);
    add_assoc_long(return_value, "nativeQueueDepth", queued);
    size_t streaming = 0, buffered = 0, paused = 0, uploads = 0, upload_buffered = 0, upload_paused = 0;
    for (ar_job *job = e->jobs; job; job = job->next) {
        if (job->op == OP_HTTP && job->stream_response) {
            streaming++; buffered += job->body_len; paused += job->receive_paused != 0;
        }
        if (job->op == OP_HTTP && job->stream_upload) {
            uploads++; upload_buffered += job->upload_len - job->upload_offset;
            upload_paused += job->send_paused != 0;
        }
    }
    add_assoc_long(return_value, "httpStreamingTransfers", streaming);
    add_assoc_long(return_value, "httpStreamingBufferedBytes", buffered);
    add_assoc_long(return_value, "httpStreamingPaused", paused);
    add_assoc_long(return_value, "httpStreamBufferLimit", AR_HTTP_STREAM_LIMIT);
    add_assoc_long(return_value, "httpStreamingUploads", uploads);
    add_assoc_long(return_value, "httpUploadBufferedBytes", upload_buffered);
    add_assoc_long(return_value, "httpUploadPaused", upload_paused);
}
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_trace_configure, 0, 2, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, capacity, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, mask, IS_LONG, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_trace_event, 0, 6, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, category, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, code, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, id, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, a, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, b, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, c, IS_LONG, 0)
ZEND_END_ARG_INFO()
PHP_FUNCTION(asyncara_trace_configure) {
    zend_long capacity, mask;
    ZEND_PARSE_PARAMETERS_START(2, 2) Z_PARAM_LONG(capacity) Z_PARAM_LONG(mask) ZEND_PARSE_PARAMETERS_END();
    if (capacity < 16 || capacity > AR_TRACE_MAX || mask < 0 || mask > AR_TRACE_ALL) {
        zend_value_error("Trace capacity must be 16..4096 and mask 0..127"); RETURN_THROWS();
    }
    ar_engine *e = get_engine(); if (!e) RETURN_THROWS();
    if (ar_trace_configure(e, capacity, mask)) {
        zend_throw_exception_ex(zend_ce_exception, errno, "Trace configuration failed (capacity is fixed per request): %s", strerror(errno)); RETURN_THROWS();
    }
}
PHP_FUNCTION(asyncara_trace_event) {
    zend_long category, code, id, a, b, c;
    ZEND_PARSE_PARAMETERS_START(6, 6)
    Z_PARAM_LONG(category) Z_PARAM_LONG(code) Z_PARAM_LONG(id) Z_PARAM_LONG(a) Z_PARAM_LONG(b) Z_PARAM_LONG(c)
    ZEND_PARSE_PARAMETERS_END();
    if (!engine) return;
    if (category < 1 || category > AR_TRACE_ALL || code < 1 || code > 255) {
        zend_value_error("Invalid diagnostic event"); RETURN_THROWS();
    }
    ar_trace *t = atomic_load_explicit(&engine->trace, memory_order_acquire);
    if (t && code == 30) atomic_store_explicit(&t->scope, id, memory_order_relaxed);
    ar_trace_emit(engine, category, code, id, a, b, c);
}
PHP_FUNCTION(asyncara_trace_snapshot) {
    ZEND_PARSE_PARAMETERS_NONE();
    ar_engine *e = get_engine(); if (!e) RETURN_THROWS();
    ar_trace_emit(e, AR_TRACE_LIFE, 3, e->request_id, e->pending, 0, 0);
    ar_trace_event *events = safe_emalloc(AR_TRACE_MAX, sizeof(*events), 0);
    ar_trace_status status;
    if (ar_trace_read(e, events, &status)) {
        efree(events); zend_throw_exception(zend_ce_exception, "Diagnostic snapshot busy; retry after operations finish", EBUSY); RETURN_THROWS();
    }
    /* No native lock remains held while Zend allocates/converts. emalloc is
     * request-owned even if conversion ends in an unrecoverable PHP bailout. */
    array_init(return_value);
    add_assoc_long(return_value, "schema", 1);
    add_assoc_long(return_value, "pid", getpid());
    add_assoc_long(return_value, "request", e->request_id);
    add_assoc_long(return_value, "requestStartedNs", e->request_started_ns);
    add_assoc_string(return_value, "backend", e->use_epoll ? "epoll" : "poll");
    add_assoc_long(return_value, "capacity", status.capacity);
    add_assoc_long(return_value, "nativeBytes", status.bytes);
    add_assoc_long(return_value, "mask", status.mask);
    add_assoc_long(return_value, "attempted", status.attempted);
    add_assoc_long(return_value, "overwritten", status.overwritten);
    add_assoc_long(return_value, "contentionDropped", status.dropped);
    add_assoc_long(return_value, "clockFailures", status.clock_failures);
    add_assoc_bool(return_value, "complete", !status.overwritten && !status.dropped && !status.clock_failures);
    zval rows; array_init(&rows);
    for (size_t i = 0; i < status.count; i++) {
        ar_trace_event *v = &events[i]; zval row; array_init(&row);
        add_next_index_long(&row, v->sequence); add_next_index_long(&row, v->ns);
        add_next_index_long(&row, v->scope); add_next_index_long(&row, v->category); add_next_index_long(&row, v->code);
        add_next_index_long(&row, v->id); add_next_index_long(&row, v->a); add_next_index_long(&row, v->b); add_next_index_long(&row, v->c);
        add_next_index_zval(&rows, &row);
    }
    efree(events);
    add_assoc_zval(return_value, "events", &rows);
}
PHP_FUNCTION(asyncara_submit) {
    ar_http_option_view view;
    ar_value *borrowed_options = NULL;
    char *name;
    size_t len;
    HashTable *args;
    ZEND_PARSE_PARAMETERS_START(2, 2)
    Z_PARAM_STRING(name, len) Z_PARAM_ARRAY_HT(args) ZEND_PARSE_PARAMETERS_END();
    static const char *names[] = {"read",       "write",       "append",       "delete",       "rename",
                                  "mkdir",      "rmdir",       "stat",         "list",         "copy",
                                  "dns",        "sqlite_open", "sqlite_query", "sqlite_close", "http",
                                  "read_range", "write_range", "file_open",    "file_read",    "file_write",
                                  "file_seek",  "file_stat",   "file_sync",    "file_close", "atomic_write", "file_stage", "file_publish"};
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
    if ((op >= OP_FILE_OPEN && op <= OP_FILE_CLOSE) || op == OP_FILE_STAGE || op == OP_FILE_PUBLISH) {
        if (op == OP_FILE_OPEN || op == OP_FILE_STAGE) {
            j->a = string_arg(e, args, 0, false, NULL);
            if (!j->a)
                goto error;
            if (!capture_path(e, &j->a)) {
                goto error;
            }
            if (op == OP_FILE_OPEN) {
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
            if (op == OP_FILE_PUBLISH) {
                zval *durable = zend_hash_index_find(args, 1);
                if (!durable || (Z_TYPE_P(durable) != IS_TRUE && Z_TYPE_P(durable) != IS_FALSE)) {
                    zend_value_error("Durability must be boolean"); goto error;
                }
                j->offset = Z_TYPE_P(durable) == IS_TRUE;
            } else if (op == OP_FILE_READ) {
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
        if (op <= OP_COPY || op == OP_READ_RANGE || op == OP_WRITE_RANGE || op == OP_ATOMIC_WRITE ||
            (op == OP_DB_OPEN && strcmp(j->a, ":memory:")))
            if (!capture_path(e, &j->a)) {
                goto error;
            }
        if (op == OP_WRITE || op == OP_APPEND || op == OP_RENAME || op == OP_COPY || op == OP_WRITE_RANGE || op == OP_ATOMIC_WRITE) {
            j->b =
                string_arg(e, args, 1, op == OP_WRITE || op == OP_APPEND || op == OP_WRITE_RANGE || op == OP_ATOMIC_WRITE, &j->b_len);
            if (!j->b)
                goto error;
            if ((op == OP_RENAME || op == OP_COPY) && !capture_path(e, &j->b)) {
                goto error;
            }
        }
        if (op == OP_ATOMIC_WRITE) {
            zval *durable = zend_hash_index_find(args, 2), *mode = zend_hash_index_find(args, 3);
            if (!durable || (Z_TYPE_P(durable) != IS_TRUE && Z_TYPE_P(durable) != IS_FALSE) ||
                !mode || Z_TYPE_P(mode) != IS_LONG || Z_LVAL_P(mode) < 0 || Z_LVAL_P(mode) > 0777) {
                zend_value_error("Atomic write requires a boolean durability flag and permissions 0000..0777");
                goto error;
            }
            j->offset = Z_TYPE_P(durable) == IS_TRUE;
            j->length = (size_t)Z_LVAL_P(mode);
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
            ar_value *options = NULL;
            if (opts && http_option_view(opts, &view)) options = borrowed_options = &view.root;
            else if (opts) options = j->params = copy_value(e, opts, 0, &bytes);
            if (!options || !valid_http_options(options)) {
                zend_value_error("Invalid HTTP options");
                goto error;
            }
            if (!timeout || Z_TYPE_P(timeout) != IS_LONG || Z_LVAL_P(timeout) < 1 ||
                Z_LVAL_P(timeout) > 3600000) {
                zend_value_error("HTTP timeout must be 1..3600000 milliseconds");
                goto error;
            }
            j->length = Z_LVAL_P(timeout);
            bool head_request = false;
            for (size_t i = 0; i < options->count; i++)
                if (!strcmp(options->keys[i], "streamResponse"))
                    j->stream_response = (int)options->items[i]->integer;
                else if (!strcmp(options->keys[i], "uploadSize")) {
                    j->stream_upload = 1;
                    j->upload_size = options->items[i]->integer;
                } else if (!strcmp(options->keys[i], "method"))
                    head_request = !strcmp(options->items[i]->string, "HEAD");
            /* HEAD describes a bodyless response. CURLOPT_UPLOAD changes curl's
             * response framing even when CUSTOMREQUEST still says HEAD. Reject
             * unsupported content before submission or starting its producer. */
            if (head_request && (j->stream_upload || j->b_len)) {
                zend_value_error("HEAD does not support buffered request content or streaming uploads");
                goto error;
            }
            if (j->stream_upload && j->b) {
                zend_value_error("Streaming upload cannot also have a buffered body");
                goto error;
            }
        }
    }
    /* No Zend API is called while a borrowed stack view is attached. HTTP
     * ar_submit -> ar_http_start is synchronous; workers never receive it. */
    if (borrowed_options) j->params = borrowed_options;
    int submitted = ar_submit(e, j);
    if (borrowed_options) j->params = NULL;
    if (submitted) {
        zend_throw_exception(zend_ce_exception, "Cannot submit native operation", 0);
        goto error;
    }
    RETURN_LONG(j->id);
error:
    if ((j->op == OP_FILE_OPEN || j->op == OP_FILE_STAGE) && j->file && !j->file->busy) {
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
            /* Casting a TLS stream may move SSL_pending bytes into PHP's
             * buffer. Kernel readiness alone then loses the wakeup. */
            w[i++] = (ar_watch){.fd = fd, .events = mode ? POLLOUT : POLLIN, .key = key,
                               .buffered = !mode && (stream->writepos > stream->readpos || stream->eof)};
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
        add_assoc_long(&item, "errno", j->system_errno);
        add_assoc_bool(&item, "committed", j->committed);
        if (j->error[0])
            add_assoc_string(&item, "error", j->error);
        else
            add_assoc_null(&item, "error");
        if (!(j->op == OP_HTTP && !j->error[0] ? export_http_result(j, &value) : export_value(j->result, &value))) {
            zval_ptr_dtor(&item);
            zval_ptr_dtor(&r);
            zval_ptr_dtor(&wr);
            zval_ptr_dtor(&results);
            /* The native allocation list retains this and remaining jobs for
             * RSHUTDOWN. Return to Zend to deliver the pending fatal error. */
            return;
        }
        add_assoc_zval(&item, "value", &value);
        add_next_index_zval(&results, &item);
        ar_job_free(j);
        j = next;
    }
    add_assoc_zval(return_value, "read", &r);
    add_assoc_zval(return_value, "write", &wr);
    add_assoc_zval(return_value, "results", &results);
    zval http_readable, http_writable;
    array_init(&http_readable);
    array_init(&http_writable);
    for (ar_job *job = e->jobs; job; job = job->next) {
        if (job->op == OP_HTTP && job->stream_response &&
            (job->body_len || (job->headers_ready && !job->headers_delivered)))
            add_next_index_long(&http_readable, job->id);
        if (job->op == OP_HTTP && job->stream_upload && !job->upload_len && !job->upload_eof)
            add_next_index_long(&http_writable, job->id);
    }
    add_assoc_zval(return_value, "httpReadable", &http_readable);
    add_assoc_zval(return_value, "httpWritable", &http_writable);
}
PHP_FUNCTION(asyncara_http_head) {
    zend_long id;
    ZEND_PARSE_PARAMETERS_START(1, 1) Z_PARAM_LONG(id) ZEND_PARSE_PARAMETERS_END();
    ar_job *j = engine ? engine->jobs : NULL;
    while (j && j->id != id) j = j->next;
    if (!j || j->op != OP_HTTP || !j->stream_response) {
        zend_value_error("HTTP streaming transfer is missing or completed");
        RETURN_THROWS();
    }
    if (!j->headers_ready && !j->body_len) RETURN_NULL();
    long status = 0;
    curl_easy_getinfo(j->curl, CURLINFO_RESPONSE_CODE, &status);
    array_init(return_value);
    add_assoc_long(return_value, "status", status);
    zend_string *headers = ar_php_string_init(j->response_headers ? j->response_headers : "", j->headers_len);
    if (!headers) return;
    add_assoc_str(return_value, "headers", headers);
    j->headers_delivered = 1;
}
PHP_FUNCTION(asyncara_http_read) {
    zend_long id;
    ZEND_PARSE_PARAMETERS_START(1, 1) Z_PARAM_LONG(id) ZEND_PARSE_PARAMETERS_END();
    ar_job *j = engine ? engine->jobs : NULL;
    while (j && j->id != id) j = j->next;
    if (!j || j->op != OP_HTTP || !j->stream_response) {
        zend_value_error("HTTP streaming transfer is missing or completed");
        RETURN_THROWS();
    }
    /* Copy before clearing: a Zend allocation bailout leaves native ownership
     * intact for RSHUTDOWN. Unpausing may synchronously refill this buffer. */
    zend_string *body = ar_php_string_init(j->body ? j->body : "", j->body_len);
    if (!body) return;
    RETVAL_STR(body);
    j->body_len = 0;
    if (j->receive_paused && !j->done) {
        engine->http_wakeup = 1;
        j->receive_paused = 0;
        CURLcode result = curl_easy_pause(j->curl, j->send_paused ? CURLPAUSE_SEND : CURLPAUSE_CONT);
        if (result != CURLE_OK) {
            curl_multi_remove_handle(engine->multi, j->curl);
            snprintf(j->error, sizeof(j->error), "HTTP resume failed: %s", curl_easy_strerror(result));
            ar_done(engine, j);
        }
    }
}
PHP_FUNCTION(asyncara_http_write) {
    zend_long id, part = 0;
    char *data;
    size_t length;
    bool eof;
    ZEND_PARSE_PARAMETERS_START(3, 4)
    Z_PARAM_LONG(id) Z_PARAM_STRING(data, length) Z_PARAM_BOOL(eof) Z_PARAM_OPTIONAL Z_PARAM_LONG(part) ZEND_PARSE_PARAMETERS_END();
    ar_job *j = engine ? engine->jobs : NULL;
    while (j && j->id != id) j = j->next;
    if (!j || j->op != OP_HTTP || !j->stream_upload || part < 0 || (size_t)part >= (j->upload_part_count ? j->upload_part_count : 1) || length > AR_HTTP_STREAM_LIMIT) {
        zend_value_error("Invalid streaming upload state or chunk exceeds 65536 bytes");
        RETURN_THROWS();
    }
    if ((size_t)part < j->upload_index || ((size_t)part == j->upload_index && j->upload_eof)) {
        zend_value_error("Upload part has already ended"); RETURN_THROWS();
    }
    if ((size_t)part > j->upload_index || j->upload_len) RETURN_FALSE;
    if (j->done) RETURN_FALSE;
    if (length > (uint64_t)(INT64_MAX - j->upload_sent) ||
        (j->upload_size >= 0 && ((uint64_t)length > (uint64_t)(j->upload_size - j->upload_sent) ||
                                (eof && j->upload_sent + (int64_t)length != j->upload_size)))) {
        zend_value_error("Upload length does not match the declared size");
        RETURN_THROWS();
    }
    if (length && !j->upload) {
        j->upload = ar_alloc(engine, AR_HTTP_STREAM_LIMIT);
        if (!j->upload) {
            zend_throw_exception(zend_ce_exception, "Native memory limit or allocation failure", 0);
            RETURN_THROWS();
        }
    }
    if (length) memcpy(j->upload, data, length);
    j->upload_len = length;
    j->upload_eof = eof;
    engine->http_wakeup = 1;
    if (j->send_paused) {
        j->send_paused = 0;
        int receive = j->receive_paused ? CURLPAUSE_RECV : 0;
        /* MIME may return already-written boundary bytes before passing its
         * nested callback's PAUSE up to the transfer. A direct CONT at that
         * instant is a no-op and leaves the MIME part paused forever. Set SEND
         * through the public API first, then clear it to unpause the MIME tree.
         * No curl-internal state is accessed. */
        CURLcode result = curl_easy_pause(j->curl, receive | CURLPAUSE_SEND);
        if (result == CURLE_OK) result = curl_easy_pause(j->curl, receive);
        if (result != CURLE_OK) {
            curl_multi_remove_handle(engine->multi, j->curl);
            snprintf(j->error, sizeof(j->error), "HTTP upload resume failed: %s", curl_easy_strerror(result));
            ar_done(engine, j);
        }
    }
    RETURN_TRUE;
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
    PHP_FE(asyncara_udp_bind, arginfo_udp_bind)
    PHP_FE(asyncara_http_headers, arginfo_http_headers)
    PHP_FE(asyncara_http_read, arginfo_http_read)
    PHP_FE(asyncara_http_head, arginfo_http_head)
    PHP_FE(asyncara_http_write, arginfo_http_write)
    PHP_FE(asyncara_memory_limit, arginfo_memory_limit)
    PHP_FE(asyncara_http_limits, arginfo_http_limits)
    PHP_FE(asyncara_trace_configure, arginfo_trace_configure)
    PHP_FE(asyncara_trace_event, arginfo_trace_event)
    PHP_FE(asyncara_trace_snapshot, arginfo_info)
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
    /* PHP's October allocation changes preserved the module API number while
     * changing executor-global layout. A matching major/minor API is insufficient:
     * reject foreign patch SDKs before any library, hook or request initialization. */
    const char *runtime_version = zend_get_module_version("standard");
    if (!runtime_version || strcmp(runtime_version, PHP_VERSION)) {
        php_error_docref(NULL, E_WARNING, "Asyncara was built for PHP %s; runtime is %s. Rebuild with the exact matching PHP SDK", PHP_VERSION, runtime_version ? runtime_version : "unknown");
        return FAILURE;
    }
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
    request_id = atomic_fetch_add_explicit(&next_request_id, 1, memory_order_relaxed) + 1;
    request_started_ns = ar_trace_clock();
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
