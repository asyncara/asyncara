/* MariaDB Connector/C start/cont API. PHP values remain on the interpreter thread. */
#include <arpa/inet.h>
#include <mysql.h>
#include <sys/socket.h>
/* Distribution 3.3.x packages do not necessarily backport the callback/status
 * API. Test its public feature macro rather than inferring it from a version. */
#if defined(MARIADB_TLS_VERIFY_ERROR)
#define AR_MYSQL_STRICT_TLS 1
#include <openssl/ssl.h>
#include <openssl/x509v3.h>
typedef unsigned char uchar;
#include <ma_tls.h>
#ifndef HAVE_TLS
#define HAVE_TLS 1
#define AR_UNDEFINE_HAVE_TLS 1
#endif
#include <ma_pvio.h>
#ifdef AR_UNDEFINE_HAVE_TLS
#undef HAVE_TLS
#undef AR_UNDEFINE_HAVE_TLS
#endif
#include <errmsg.h>

/* Connector/C 3.4 skips identity verification for private/local addresses.
 * This documented per-connection callback runs before authentication data
 * is sent. Only the OpenSSL 3 backend is admitted below. No Zend calls here.
 */
static int my_verify_tls(MARIADB_TLS *tls, unsigned int flags) {
    (void)flags;
    if (!tls || !tls->pvio || !tls->pvio->mysql)
        return 1;
    MYSQL *mysql = tls->pvio->mysql;
    SSL *ssl = tls->ssl;
    const char *error = NULL;
    X509 *cert = ssl ? SSL_get1_peer_certificate(ssl) : NULL;
    /* Connector's OpenSSL verification callback records all chain errors
     * while allowing the handshake to finish. Never discard that status.
     */
    if (!cert || mysql->net.tls_verify_status || SSL_get_verify_result(ssl) != X509_V_OK)
        error = "Asyncara MariaDB TLS certificate chain verification failed";
    else if (!mysql->host || X509_check_ip_asc(cert, mysql->host, 0) != 1)
        error = "Asyncara MariaDB TLS certificate IP identity mismatch";
    X509_free(cert);
    if (!error)
        return 0;
    /* ERROR prevents Connector's password-based TLS fallback, including
     * when no explicit CA was supplied. Always fail before authentication.
     */
    mysql->net.tls_verify_status |= MARIADB_TLS_VERIFY_ERROR;
    if (tls->pvio->set_error)
        tls->pvio->set_error(mysql, CR_SSL_CONNECTION_ERROR, "HY000", "%s", error);
    return 1;
}
#endif
typedef struct {
    MYSQL *conn, *connected;
    MYSQL_STMT *stmt;
    MYSQL_RES *metadata;
    MYSQL_BIND *input, *output;
    char **values, *sql, *options[6];
    unsigned long *lengths, *out_lengths;
    my_bool *nulls, *out_nulls, *out_errors, close_result;
    unsigned int columns;
    size_t nparams;
    int stage, waiting, result;
    bool tls_required;
} ar_mysql;
static int mysql_resource_type;
enum { MY_CONNECT, MY_IDLE, MY_PREPARE, MY_EXECUTE, MY_FETCH, MY_CLOSE, MY_CONTROL };
static void my_release_query(ar_mysql *p) {
    if (p->metadata)
        mysql_free_result(p->metadata);
    p->metadata = NULL;
    for (size_t i = 0; i < p->nparams; i++)
        if (p->values && p->values[i])
            efree(p->values[i]);
    if (p->values)
        efree(p->values);
    if (p->lengths)
        efree(p->lengths);
    if (p->nulls)
        efree(p->nulls);
    if (p->input)
        efree(p->input);
    if (p->output)
        efree(p->output);
    if (p->out_lengths)
        efree(p->out_lengths);
    if (p->out_nulls)
        efree(p->out_nulls);
    if (p->out_errors)
        efree(p->out_errors);
    if (p->sql)
        efree(p->sql);
    p->values = NULL;
    p->lengths = NULL;
    p->nulls = NULL;
    p->input = NULL;
    p->output = NULL;
    p->out_lengths = NULL;
    p->out_nulls = NULL;
    p->out_errors = NULL;
    p->sql = NULL;
    p->nparams = 0;
    p->columns = 0;
}
static void my_close(ar_mysql *p) {
    if (p->conn) {
        int fd = (int)mysql_get_socket(p->conn);
        if (fd >= 0)
            shutdown(fd, SHUT_RDWR);
        mysql_close(p->conn);
        p->conn = NULL;
        if (p->stmt) {
            mysql_stmt_close(p->stmt);
            p->stmt = NULL;
        }
    }
    my_release_query(p);
}
static void my_destroy(zend_resource *resource) {
    ar_mysql *p = resource->ptr;
    my_close(p);
    for (size_t i = 0; i < 6; i++)
        if (p->options[i])
            efree(p->options[i]);
    efree(p);
}
static ar_mysql *my_fetch(zval *z) {
    ar_mysql *p = zend_fetch_resource_ex(z, "Asyncara MariaDB", mysql_resource_type);
    if (p && !p->conn) {
        zend_throw_exception(zend_ce_exception, "MariaDB connection closed", 0);
        return NULL;
    }
    return p;
}
static void my_error(ar_mysql *p) {
    zend_throw_exception(zend_ce_exception, p->stmt ? mysql_stmt_error(p->stmt) : mysql_error(p->conn), 0);
}
ZEND_BEGIN_ARG_INFO_EX(arginfo_mysql_step, 0, 0, 2)
ZEND_ARG_INFO(0, connection)
ZEND_ARG_TYPE_INFO(0, events, IS_LONG, 0)
ZEND_END_ARG_INFO()
PHP_FUNCTION(asyncara_mysql_open) {
    HashTable *options;
    ZEND_PARSE_PARAMETERS_START(1, 1) Z_PARAM_ARRAY_HT(options) ZEND_PARSE_PARAMETERS_END();
    const char *names[] = {"host", "user", "password", "database", "socket", "caFile"};
    ar_mysql *p = ecalloc(1, sizeof(*p));
    zend_resource *resource = zend_register_resource(p, mysql_resource_type);
    p->conn = mysql_init(NULL);
    if (!p->conn) {
        zend_list_delete(resource);
        zend_throw_exception(zend_ce_exception, "MariaDB allocation failed", 0);
        RETURN_THROWS();
    }
    for (size_t i = 0; i < 6; i++) {
        zval *v = zend_hash_str_find(options, names[i], strlen(names[i]));
        if (v) {
            ZVAL_DEREF(v);
            if (Z_TYPE_P(v) != IS_STRING || Z_STRLEN_P(v) > 65536 ||
                memchr(Z_STRVAL_P(v), 0, Z_STRLEN_P(v))) {
                zend_value_error("MariaDB string option is invalid");
                goto error;
            }
            p->options[i] = estrndup(Z_STRVAL_P(v), Z_STRLEN_P(v));
        }
    }
    char address[16];
    if (!p->options[4] && (!p->options[0] || (inet_pton(AF_INET, p->options[0], address) != 1 &&
                                              inet_pton(AF_INET6, p->options[0], address) != 1))) {
        zend_value_error("Resolve MariaDB DNS before connecting or use a Unix socket");
        goto error;
    }
    zval *port = zend_hash_str_find(options, "port", 4), *tls = zend_hash_str_find(options, "tls", 3);
    if (port && (Z_TYPE_P(port) != IS_LONG || Z_LVAL_P(port) < 1 || Z_LVAL_P(port) > 65535)) {
        zend_value_error("Invalid MariaDB port");
        goto error;
    }
    if (tls && Z_TYPE_P(tls) != IS_TRUE && Z_TYPE_P(tls) != IS_FALSE) {
        zend_value_error("MariaDB tls must be boolean");
        goto error;
    }
    my_bool verify = 1, enforce = tls ? Z_TYPE_P(tls) == IS_TRUE : !p->options[4];
    p->tls_required = enforce;
    if (enforce) {
#ifdef AR_MYSQL_STRICT_TLS
        const char *library = NULL;
        if (mariadb_get_infov(p->conn, MARIADB_TLS_LIBRARY, &library) || !library ||
            strncmp(library, "OpenSSL 3.", 10) != 0 || p->options[4]) {
            zend_value_error("Verified MariaDB TLS requires Connector/C OpenSSL 3 and a numeric host");
            goto error;
        }
        if (mysql_optionsv(p->conn, MARIADB_OPT_TLS_VERIFICATION_CALLBACK, my_verify_tls)) {
            my_error(p);
            goto error;
        }
#else
        zend_value_error("Verified MariaDB TLS requires Connector/C TLS callback/status APIs (tested with 3.4.10)");
        goto error;
#endif
    }
    if (mysql_options(p->conn, MYSQL_OPT_NONBLOCK, NULL) ||
        mysql_options(p->conn, MYSQL_OPT_SSL_VERIFY_SERVER_CERT, &verify) ||
        mysql_options(p->conn, MYSQL_OPT_SSL_ENFORCE, &enforce)) {
        my_error(p);
        goto error;
    }
    if (p->options[5] && mysql_options(p->conn, MYSQL_OPT_SSL_CA, p->options[5])) {
        my_error(p);
        goto error;
    }
    unsigned int timeout = 10;
    mysql_options(p->conn, MYSQL_OPT_CONNECT_TIMEOUT, &timeout);
    p->stage = MY_CONNECT;
    p->waiting = mysql_real_connect_start(&p->connected, p->conn, p->options[4] ? "localhost" : p->options[0],
                                          p->options[1], p->options[2], p->options[3],
                                          port ? (unsigned int)Z_LVAL_P(port) : 3306, p->options[4], 0);
    RETURN_RES(resource);
error:
    zend_list_delete(resource);
    RETURN_THROWS();
}
PHP_FUNCTION(asyncara_mysql_query) {
    zval *z;
    char *sql;
    size_t len;
    HashTable *params;
    ZEND_PARSE_PARAMETERS_START(3, 3)
    Z_PARAM_RESOURCE(z) Z_PARAM_STRING(sql, len) Z_PARAM_ARRAY_HT(params) ZEND_PARSE_PARAMETERS_END();
    ar_mysql *p = my_fetch(z);
    if (!p)
        RETURN_THROWS();
    if (p->stage != MY_IDLE) {
        zend_value_error("MariaDB connection not ready or busy");
        RETURN_THROWS();
    }
    p->nparams = zend_hash_num_elements(params);
    if (p->nparams > 10000 || len > AR_LIMIT || memchr(sql, 0, len)) {
        p->nparams = 0;
        zend_value_error("MariaDB query limit exceeded");
        RETURN_THROWS();
    }
    p->sql = estrndup(sql, len);
    /* Oracle MySQL rejects START TRANSACTION in COM_STMT_PREPARE. These
     * parameterless control commands have no result set; send them through
     * Connector/C's nonblocking text path. All data queries retain binding.
     */
    if (!p->nparams && (!strcasecmp(sql, "START TRANSACTION") || !strcasecmp(sql, "BEGIN") ||
                       !strcasecmp(sql, "COMMIT") || !strcasecmp(sql, "ROLLBACK"))) {
        p->stage = MY_CONTROL;
        p->waiting = mysql_real_query_start(&p->result, p->conn, p->sql, (unsigned long)len);
        RETURN_NULL();
    }
    size_t n = p->nparams ? p->nparams : 1;
    p->input = ecalloc(n, sizeof(MYSQL_BIND));
    p->values = ecalloc(n, sizeof(char *));
    p->lengths = ecalloc(n, sizeof(unsigned long));
    p->nulls = ecalloc(n, sizeof(my_bool));
    zval *v;
    size_t i = 0, bytes = 0;
    ZEND_HASH_FOREACH_VAL(params, v) {
        ZVAL_DEREF(v);
        p->input[i].is_null = &p->nulls[i];
        p->input[i].length = &p->lengths[i];
        p->input[i].buffer_type = MYSQL_TYPE_STRING;
        if (Z_TYPE_P(v) == IS_NULL)
            p->nulls[i] = 1;
        else {
            if (Z_TYPE_P(v) != IS_STRING && Z_TYPE_P(v) != IS_LONG && Z_TYPE_P(v) != IS_DOUBLE &&
                Z_TYPE_P(v) != IS_TRUE && Z_TYPE_P(v) != IS_FALSE) {
                my_release_query(p);
                zend_value_error("MariaDB parameters must be scalar or null");
                RETURN_THROWS();
            }
            zend_string *s = Z_TYPE_P(v) == IS_TRUE    ? zend_string_init("1", 1, 0)
                             : Z_TYPE_P(v) == IS_FALSE ? zend_string_init("0", 1, 0)
                                                       : zval_get_string(v);
            bytes += ZSTR_LEN(s);
            if (bytes > AR_LIMIT) {
                zend_string_release(s);
                my_release_query(p);
                zend_value_error("MariaDB parameter byte limit exceeded");
                RETURN_THROWS();
            }
            p->values[i] = estrndup(ZSTR_VAL(s), ZSTR_LEN(s));
            p->lengths[i] = ZSTR_LEN(s);
            p->input[i].buffer = p->values[i];
            p->input[i].buffer_length = p->lengths[i];
            zend_string_release(s);
        }
        i++;
    }
    ZEND_HASH_FOREACH_END();
    p->stmt = mysql_stmt_init(p->conn);
    if (!p->stmt) {
        my_release_query(p);
        my_error(p);
        RETURN_THROWS();
    }
    p->stage = MY_PREPARE;
    p->waiting = mysql_stmt_prepare_start(&p->result, p->stmt, p->sql, (unsigned long)len);
    RETURN_NULL();
}
static void my_start_close(ar_mysql *p) {
    p->stage = MY_CLOSE;
    p->waiting = mysql_stmt_close_start(&p->close_result, p->stmt);
    if (!p->waiting)
        p->stmt = NULL;
}
PHP_FUNCTION(asyncara_mysql_step) {
    zval *z;
    zend_long events;
    ZEND_PARSE_PARAMETERS_START(2, 2) Z_PARAM_RESOURCE(z) Z_PARAM_LONG(events) ZEND_PARSE_PARAMETERS_END();
    ar_mysql *p = my_fetch(z);
    if (!p)
        RETURN_THROWS();
    if (events < 0 || events > 15) {
        zend_value_error("Invalid MariaDB readiness mask");
        RETURN_THROWS();
    }
    zval rows;
    array_init(&rows);
    size_t bytes = 0, records = 0;
    for (;;) {
        if (p->waiting) {
            if (!events)
                break;
            switch (p->stage) {
            case MY_CONNECT:
                p->waiting = mysql_real_connect_cont(&p->connected, p->conn, (int)events);
                break;
            case MY_CONTROL:
                p->waiting = mysql_real_query_cont(&p->result, p->conn, (int)events);
                break;
            case MY_PREPARE:
                p->waiting = mysql_stmt_prepare_cont(&p->result, p->stmt, (int)events);
                break;
            case MY_EXECUTE:
                p->waiting = mysql_stmt_execute_cont(&p->result, p->stmt, (int)events);
                break;
            case MY_FETCH:
                p->waiting = mysql_stmt_fetch_cont(&p->result, p->stmt, (int)events);
                break;
            case MY_CLOSE:
                p->waiting = mysql_stmt_close_cont(&p->close_result, p->stmt, (int)events);
                if (!p->waiting)
                    p->stmt = NULL;
                break;
            }
            events = 0;
            if (p->waiting)
                break;
        }
        if (p->stage == MY_CONTROL) {
            if (p->result)
                goto error;
            my_release_query(p);
            p->stage = MY_IDLE;
        } else if (p->stage == MY_CONNECT) {
            if (!p->connected)
                goto error;
            if (p->tls_required && !mysql_get_ssl_cipher(p->conn)) {
                zend_throw_exception(zend_ce_exception, "MariaDB required TLS was not negotiated", 0);
                goto failed;
            }
            p->stage = MY_IDLE;
        } else if (p->stage == MY_PREPARE) {
            if (p->result)
                goto error;
            if (mysql_stmt_param_count(p->stmt) != p->nparams) {
                zend_value_error("MariaDB parameter count mismatch");
                goto failed;
            }
            if (p->nparams && mysql_stmt_bind_param(p->stmt, p->input))
                goto error;
            p->stage = MY_EXECUTE;
            p->waiting = mysql_stmt_execute_start(&p->result, p->stmt);
        } else if (p->stage == MY_EXECUTE) {
            if (p->result)
                goto error;
            p->columns = mysql_stmt_field_count(p->stmt);
            if (!p->columns) {
                my_start_close(p);
                continue;
            }
            p->metadata = mysql_stmt_result_metadata(p->stmt);
            if (!p->metadata)
                goto error;
            p->output = ecalloc(p->columns, sizeof(MYSQL_BIND));
            p->out_lengths = ecalloc(p->columns, sizeof(unsigned long));
            p->out_nulls = ecalloc(p->columns, sizeof(my_bool));
            p->out_errors = ecalloc(p->columns, sizeof(my_bool));
            for (unsigned int i = 0; i < p->columns; i++) {
                p->output[i].buffer_type = MYSQL_TYPE_STRING;
                p->output[i].length = &p->out_lengths[i];
                p->output[i].is_null = &p->out_nulls[i];
                p->output[i].error = &p->out_errors[i];
            }
            if (mysql_stmt_bind_result(p->stmt, p->output))
                goto error;
            p->stage = MY_FETCH;
            p->waiting = mysql_stmt_fetch_start(&p->result, p->stmt);
        } else if (p->stage == MY_FETCH) {
            if (p->result == MYSQL_NO_DATA) {
                my_start_close(p);
                continue;
            }
            if (p->result != 0 && p->result != MYSQL_DATA_TRUNCATED)
                goto error;
            zval row;
            array_init(&row);
            MYSQL_FIELD *fields = mysql_fetch_fields(p->metadata);
            for (unsigned int i = 0; i < p->columns; i++) {
                if (p->out_nulls[i]) {
                    add_assoc_null(&row, fields[i].name);
                    continue;
                }
                bytes += p->out_lengths[i];
                if (bytes > AR_LIMIT) {
                    zval_ptr_dtor(&row);
                    zend_throw_exception(zend_ce_exception, "MariaDB result limit exceeded", 0);
                    goto failed;
                }
                zend_string *s = zend_string_alloc(p->out_lengths[i], 0);
                MYSQL_BIND bind = {0};
                bind.buffer_type = MYSQL_TYPE_STRING;
                bind.buffer = ZSTR_VAL(s);
                bind.buffer_length = p->out_lengths[i];
                if (mysql_stmt_fetch_column(p->stmt, &bind, i, 0)) {
                    zend_string_release(s);
                    zval_ptr_dtor(&row);
                    goto error;
                }
                ZSTR_VAL(s)[p->out_lengths[i]] = 0;
                add_assoc_str(&row, fields[i].name, s);
            }
            add_next_index_zval(&rows, &row);
            records++;
            p->waiting = mysql_stmt_fetch_start(&p->result, p->stmt);
            if (records >= 128)
                break;
        } else if (p->stage == MY_CLOSE) {
            if (p->close_result)
                goto error;
            my_release_query(p);
            p->stage = MY_IDLE;
        }
        if (p->stage == MY_IDLE)
            break;
    }
    array_init(return_value);
    add_assoc_zval(return_value, "rows", &rows);
    add_assoc_bool(return_value, "pending", p->stage != MY_IDLE);
    add_assoc_long(return_value, "events", p->waiting);
    add_assoc_long(return_value, "fd", (zend_long)mysql_get_socket(p->conn));
    add_assoc_long(return_value, "timeoutMs", mysql_get_timeout_value_ms(p->conn));
    return;
error:
    my_error(p);
failed:
    zval_ptr_dtor(&rows);
    RETURN_THROWS();
}
PHP_FUNCTION(asyncara_mysql_close) {
    zval *z;
    ZEND_PARSE_PARAMETERS_START(1, 1) Z_PARAM_RESOURCE(z) ZEND_PARSE_PARAMETERS_END();
    ar_mysql *p = my_fetch(z);
    if (!p)
        RETURN_THROWS();
    my_close(p);
    RETURN_NULL();
}
static void asyncara_mysql_init(int module_number) {
    mysql_resource_type =
        zend_register_list_destructors_ex(my_destroy, NULL, "Asyncara MariaDB", module_number);
}
