/* Optional libpq driver. All calls use nonblocking connections on the PHP thread. */
#include "php.h"
#include "Zend/zend_exceptions.h"
#include <arpa/inet.h>
#include <libpq-fe.h>
#include <stdlib.h>
#include <string.h>
#define PG_BUFFER_LIMIT (16u * 1024u * 1024u)
typedef struct {
    PGconn *conn;
    PGresult *result;
    PGnotify *notification;
    bool pending;
} asyncara_pg;
static int pg_resource_type;
static void pg_close_handle(asyncara_pg *p) {
    if (p->result) PQclear(p->result);
    if (p->notification) PQfreemem(p->notification);
    p->result = NULL;
    p->notification = NULL;
    if (p->conn)
        PQfinish(p->conn);
    p->conn = NULL;
}
static void pg_destroy(zend_resource *resource) {
    asyncara_pg *p = resource->ptr;
    pg_close_handle(p);
    efree(p);
}
static asyncara_pg *pg_fetch(zval *z) {
    asyncara_pg *p = zend_fetch_resource_ex(z, "Asyncara PostgreSQL", pg_resource_type);
    if (p && !p->conn) {
        zend_throw_exception(zend_ce_exception, "PostgreSQL connection closed", 0);
        return NULL;
    }
    return p;
}
static void pg_error(asyncara_pg *p) {
    zend_throw_exception(zend_ce_exception, PQerrorMessage(p->conn), 0);
}
PHP_FUNCTION(asyncara_pg_open) {
    HashTable *opts;
    ZEND_PARSE_PARAMETERS_START(1, 1) Z_PARAM_ARRAY_HT(opts) ZEND_PARSE_PARAMETERS_END();
    const char *allowed[] = {"host",     "hostaddr", "port",        "dbname",           "user",
                             "password", "sslmode",  "sslrootcert", "application_name", NULL};
    const char *keys[12], *values[12];
    size_t count = 0;
    zend_string *key;
    zval *v;
    ZEND_HASH_FOREACH_STR_KEY_VAL(opts, key, v) {
        if (!key || Z_TYPE_P(v) != IS_STRING || Z_STRLEN_P(v) > 65536 ||
            memchr(Z_STRVAL_P(v), 0, Z_STRLEN_P(v))) {
            zend_value_error("PostgreSQL options must be bounded NUL-free strings");
            RETURN_THROWS();
        }
        size_t i;
        for (i = 0; allowed[i]; i++)
            if (zend_string_equals_cstr(key, allowed[i], strlen(allowed[i])))
                break;
        if (!allowed[i]) {
            zend_value_error("Unsupported PostgreSQL option");
            RETURN_THROWS();
        }
        keys[count] = ZSTR_VAL(key);
        values[count++] = Z_STRVAL_P(v);
    }
    ZEND_HASH_FOREACH_END();
    zval *host = zend_hash_str_find(opts, "host", 4), *hostaddr = zend_hash_str_find(opts, "hostaddr", 8);
    char address[16];
    if (hostaddr && inet_pton(AF_INET, Z_STRVAL_P(hostaddr), address) != 1 &&
        inet_pton(AF_INET6, Z_STRVAL_P(hostaddr), address) != 1) {
        zend_value_error("hostaddr must be numeric");
        RETURN_THROWS();
    }
    if (!hostaddr &&
        (!host || (Z_STRVAL_P(host)[0] != '/' && inet_pton(AF_INET, Z_STRVAL_P(host), address) != 1 &&
                   inet_pton(AF_INET6, Z_STRVAL_P(host), address) != 1))) {
        zend_value_error("Resolve DNS before PostgreSQL connect; provide hostaddr or Unix socket host");
        RETURN_THROWS();
    }
    if (!zend_hash_str_exists(opts, "sslmode", 7)) {
        keys[count] = "sslmode";
        values[count++] = "verify-full";
    }
    keys[count] = "connect_timeout";
    values[count++] = "10";
    keys[count] = NULL;
    values[count] = NULL;
    asyncara_pg *p = ecalloc(1, sizeof(*p));
    /* Register before acquiring foreign allocations: Zend may bail out later. */
    zend_resource *resource = zend_register_resource(p, pg_resource_type);
    p->conn = PQconnectStartParams(keys, values, 0);
    if (!p->conn || PQstatus(p->conn) == CONNECTION_BAD) {
        if (p->conn) {
            pg_error(p);
        } else
            zend_throw_exception(zend_ce_exception, "libpq allocation failed", 0);
        zend_list_delete(resource);
        RETURN_THROWS();
    }
    RETURN_RES(resource);
}
PHP_FUNCTION(asyncara_pg_poll) {
    zval *z;
    ZEND_PARSE_PARAMETERS_START(1, 1) Z_PARAM_RESOURCE(z) ZEND_PARSE_PARAMETERS_END();
    asyncara_pg *p = pg_fetch(z);
    if (!p)
        RETURN_THROWS();
    PostgresPollingStatusType status = PQconnectPoll(p->conn);
    if (status == PGRES_POLLING_FAILED) {
        pg_error(p);
        RETURN_THROWS();
    }
    if (status == PGRES_POLLING_OK && PQsetnonblocking(p->conn, 1)) {
        pg_error(p);
        RETURN_THROWS();
    }
    array_init(return_value);
    add_assoc_long(return_value, "status", status);
    add_assoc_long(return_value, "fd", PQsocket(p->conn));
}
/* Preparation and execution use the same pending/result owner as ordinary
 * queries. Callers must drain each command before submitting another one. */
PHP_FUNCTION(asyncara_pg_prepare) {
    zval *z;
    char *name, *sql;
    size_t name_len, sql_len;
    zend_long count;
    ZEND_PARSE_PARAMETERS_START(4, 4)
    Z_PARAM_RESOURCE(z) Z_PARAM_STRING(name, name_len) Z_PARAM_STRING(sql, sql_len)
    Z_PARAM_LONG(count) ZEND_PARSE_PARAMETERS_END();
    asyncara_pg *p = pg_fetch(z);
    if (!p) RETURN_THROWS();
    if (p->pending) {
        zend_value_error("PostgreSQL connection already has a pending query");
        RETURN_THROWS();
    }
    if (!name_len || name_len > 63 || memchr(name, 0, name_len) ||
        sql_len > PG_BUFFER_LIMIT || memchr(sql, 0, sql_len) || count < 0 || count > 10000) {
        zend_value_error("PostgreSQL prepared statement limits exceeded");
        RETURN_THROWS();
    }
    if (!PQsendPrepare(p->conn, name, sql, (int)count, NULL)) {
        pg_error(p);
        RETURN_THROWS();
    }
    p->pending = true;
    RETURN_NULL();
}
static void pg_send(INTERNAL_FUNCTION_PARAMETERS, bool prepared) {
    zval *z;
    char *sql;
    size_t len;
    HashTable *params;
    ZEND_PARSE_PARAMETERS_START(3, 3)
    Z_PARAM_RESOURCE(z) Z_PARAM_STRING(sql, len) Z_PARAM_ARRAY_HT(params) ZEND_PARSE_PARAMETERS_END();
    asyncara_pg *p = pg_fetch(z);
    if (!p)
        RETURN_THROWS();
    if (p->pending) {
        zend_value_error("PostgreSQL connection already has a pending query");
        RETURN_THROWS();
    }
    size_t n = zend_hash_num_elements(params);
    if (n > 10000 || len > (prepared ? 63u : PG_BUFFER_LIMIT) ||
        (prepared && !len) || memchr(sql, 0, len)) {
        zend_value_error("PostgreSQL query limits exceeded");
        RETURN_THROWS();
    }
    const char **values = ecalloc(n ? n : 1, sizeof(char *));
    zend_string **strings = ecalloc(n ? n : 1, sizeof(zend_string *));
    zval *v;
    size_t i = 0, bytes = 0;
    bool invalid = false;
    ZEND_HASH_FOREACH_VAL(params, v) {
        if (Z_TYPE_P(v) == IS_NULL) {
            i++;
            continue;
        }
        if (Z_TYPE_P(v) != IS_STRING && Z_TYPE_P(v) != IS_LONG && Z_TYPE_P(v) != IS_DOUBLE &&
            Z_TYPE_P(v) != IS_TRUE && Z_TYPE_P(v) != IS_FALSE) {
            invalid = true;
            break;
        }
        strings[i] = Z_TYPE_P(v) == IS_TRUE    ? zend_string_init("true", 4, 0)
                     : Z_TYPE_P(v) == IS_FALSE ? zend_string_init("false", 5, 0)
                                               : zval_get_string(v);
        bytes += ZSTR_LEN(strings[i]);
        values[i] = ZSTR_VAL(strings[i]);
        if (bytes > PG_BUFFER_LIMIT || memchr(values[i], 0, ZSTR_LEN(strings[i]))) {
            invalid = true;
            break;
        }
        i++;
    }
    ZEND_HASH_FOREACH_END();
    int sent = 0;
    if (!invalid) {
        sent = prepared ? PQsendQueryPrepared(p->conn, sql, (int)n, values, NULL, NULL, 0)
                        : PQsendQueryParams(p->conn, sql, (int)n, NULL, values, NULL, NULL, 0);
    }
    for (i = 0; i < n; i++) {
        if (strings[i])
            zend_string_release(strings[i]);
    }
    efree(strings);
    efree(values);
    if (invalid) {
        zend_value_error("PostgreSQL parameters must be bounded scalar text or null");
        RETURN_THROWS();
    }
    if (!sent) {
        pg_error(p);
        RETURN_THROWS();
    }
    p->pending = true;
    if (!PQsetSingleRowMode(p->conn)) {
        zend_throw_exception(zend_ce_exception, "Cannot enable PostgreSQL single-row mode", 0);
        RETURN_THROWS();
    }
    RETURN_NULL();
}
PHP_FUNCTION(asyncara_pg_query) {
    pg_send(INTERNAL_FUNCTION_PARAM_PASSTHRU, false);
}
PHP_FUNCTION(asyncara_pg_execute) {
    pg_send(INTERNAL_FUNCTION_PARAM_PASSTHRU, true);
}
PHP_FUNCTION(asyncara_pg_read) {
    zval *z;
    ZEND_PARSE_PARAMETERS_START(1, 1) Z_PARAM_RESOURCE(z) ZEND_PARSE_PARAMETERS_END();
    asyncara_pg *p = pg_fetch(z);
    if (!p)
        RETURN_THROWS();
    int flush = PQflush(p->conn);
    if (flush < 0 || !PQconsumeInput(p->conn)) {
        pg_error(p);
        RETURN_THROWS();
    }
    array_init(return_value);
    zval rows, notifications;
    array_init(&rows);
    array_init(&notifications);
    size_t bytes = 0, records = 0;
    const char *error = NULL;
    while (p->pending && !PQisBusy(p->conn) && records < 256) {
        PGresult *r = p->result = PQgetResult(p->conn);
        if (!r) {
            p->pending = false;
            break;
        }
        ExecStatusType status = PQresultStatus(r);
        if (status != PGRES_TUPLES_OK && status != PGRES_COMMAND_OK && status != PGRES_SINGLE_TUPLE) {
            error = PQresultErrorMessage(r);
            add_assoc_string(return_value, "error",
                             error && *error ? error : "Unsupported PostgreSQL result mode");
        }
        for (int row = 0; row < PQntuples(r); row++) {
            zval item;
            array_init(&item);
            for (int col = 0; col < PQnfields(r); col++) {
                size_t size = (size_t)PQgetlength(r, row, col);
                /* Payload plus a conservative accounting charge for each cell.
                 * This is a conversion budget, not libpq allocation tracking. */
                bytes += size + strlen(PQfname(r, col)) + 64;
                if (bytes > PG_BUFFER_LIMIT) {
                    zval_ptr_dtor(&item);
                    PQclear(r);
                    p->result = NULL;
                    zval_ptr_dtor(&rows);
                    zval_ptr_dtor(&notifications);
                    zval_ptr_dtor(return_value);
                    ZVAL_UNDEF(return_value);
                    zend_throw_exception(zend_ce_exception, "PostgreSQL result byte limit exceeded", 0);
                    RETURN_THROWS();
                }
                if (PQgetisnull(r, row, col))
                    add_assoc_null(&item, PQfname(r, col));
                else {
                    zend_string *value = ar_php_string_init(PQgetvalue(r, row, col), size);
                    if (!value) {
                        zval_ptr_dtor(&item);
                        goto oom;
                    }
                    add_assoc_str(&item, PQfname(r, col), value);
                }
                if (ar_php_oom()) {
                    zval_ptr_dtor(&item);
                    goto oom;
                }
            }
            add_next_index_zval(&rows, &item);
            records++;
            if (ar_php_oom()) goto oom;
        }
        PQclear(r);
        p->result = NULL;
    }
    PGnotify *note;
    size_t notification_bytes = 0, notification_count = 0;
    while ((note = p->notification = PQnotifies(p->conn))) {
        notification_bytes += strlen(note->relname) + strlen(note->extra);
        if (++notification_count > 1024 || notification_bytes > 1048576) {
            /* Keep ownership in p until close: also safe on a Zend bailout. */
            pg_close_handle(p);
            zval_ptr_dtor(&rows);
            zval_ptr_dtor(&notifications);
            zval_ptr_dtor(return_value);
            ZVAL_UNDEF(return_value);
            zend_throw_exception(zend_ce_exception, "PostgreSQL notification batch limit exceeded; connection closed", 0);
            RETURN_THROWS();
        }
        zval item;
        array_init(&item);
        add_assoc_string(&item, "channel", note->relname);
        add_assoc_string(&item, "payload", note->extra);
        add_assoc_long(&item, "pid", note->be_pid);
        add_next_index_zval(&notifications, &item);
        PQfreemem(note);
        p->notification = NULL;
        if (ar_php_oom()) goto oom;
    }
    add_assoc_zval(return_value, "rows", &rows);
    add_assoc_zval(return_value, "notifications", &notifications);
    add_assoc_bool(return_value, "pending", p->pending);
    add_assoc_bool(return_value, "read", p->pending && PQisBusy(p->conn));
    add_assoc_bool(return_value, "write", flush > 0);
    add_assoc_long(return_value, "fd", PQsocket(p->conn));
    return;
oom:
    /* p retains native result/notification ownership until resource teardown. */
    zval_ptr_dtor(&rows);
    zval_ptr_dtor(&notifications);
    return;
}
PHP_FUNCTION(asyncara_pg_close) {
    zval *z;
    ZEND_PARSE_PARAMETERS_START(1, 1) Z_PARAM_RESOURCE(z) ZEND_PARSE_PARAMETERS_END();
    asyncara_pg *p = pg_fetch(z);
    if (!p)
        RETURN_THROWS();
    pg_close_handle(p);
    RETURN_NULL();
}
static void asyncara_pg_init(int module_number) {
    pg_resource_type =
        zend_register_list_destructors_ex(pg_destroy, NULL, "Asyncara PostgreSQL", module_number);
}
