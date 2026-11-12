/* Included after get_engine(); this API is available only in Hyper builds. */
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_server_listen, 0, 8, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO(0, address, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, connections, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, bodyBytes, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, responseBytes, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, bufferBytes, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, timeoutMs, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, requestsPerConnection, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, reusePort, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, certificatePem, IS_STRING, 0, "\"\"")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, keyPem, IS_STRING, 0, "\"\"")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, clientCaPem, IS_STRING, 0, "\"\"")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, streamRequests, _IS_BOOL, 0, "false")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, halfClose, _IS_BOOL, 0, "false")
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_server_next, 0, 0, IS_ARRAY, 1)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_server_respond, 0, 4, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO(0, id, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, status, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, headers, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO(0, body, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, streaming, _IS_BOOL, 0, "false")
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_server_void, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()
static void server_error(void) { zend_throw_exception(zend_ce_exception, "Native HTTP server failed or is not running", 0); }
static void server_status(zval *value, ar_server_status *s) {
    array_init(value);
    add_assoc_long(value, "fd", s->notification_fd);
    add_assoc_stringl(value, "address", (const char *)s->address, s->address_len);
    add_assoc_long(value, "connections", s->active);
    add_assoc_long(value, "queued", s->queued);
    add_assoc_long(value, "pending", s->pending);
    add_assoc_long(value, "bufferBytes", s->bytes);
    add_assoc_long(value, "accepted", s->accepted);
    add_assoc_long(value, "closed", s->closed);
    add_assoc_long(value, "timedOut", s->timed_out);
    add_assoc_long(value, "protocolErrors", s->errors);
}
PHP_FUNCTION(asyncara_server_listen) {
    char *address; size_t len; zend_long connections, body, response, budget, timeout, requests; bool reuse_port, stream_requests = false, half_close = false;
    char *cert = "", *key = "", *ca = ""; size_t cert_len = 0, key_len = 0, ca_len = 0;
    ZEND_PARSE_PARAMETERS_START(8, 13)
        Z_PARAM_STRING(address, len) Z_PARAM_LONG(connections) Z_PARAM_LONG(body)
        Z_PARAM_LONG(response) Z_PARAM_LONG(budget) Z_PARAM_LONG(timeout) Z_PARAM_LONG(requests)
        Z_PARAM_BOOL(reuse_port)
        Z_PARAM_OPTIONAL
        Z_PARAM_STRING(cert, cert_len) Z_PARAM_STRING(key, key_len) Z_PARAM_STRING(ca, ca_len) Z_PARAM_BOOL(stream_requests)
        Z_PARAM_BOOL(half_close)
    ZEND_PARSE_PARAMETERS_END();
    /* Reserve the engine wake pipe, PHP notification watcher and listener.
     * Other I/O shares AR_MAX_WATCHERS; applications must budget that too. */
    if (connections < 1 || connections > AR_MAX_WATCHERS - 3 || body < 0 || body > AR_LIMIT || response < 0 || response > AR_LIMIT || budget < 65536 || budget > 1073741824 || timeout < 1 || timeout > 3600000 || requests < 1 || requests > 1000000) {
        zend_value_error("Invalid HTTP server bounds"); RETURN_THROWS();
    }
    ar_engine *e = get_engine(); if (!e) RETURN_THROWS();
    if (e->server) { zend_throw_exception(zend_ce_exception, "Only one HTTP listener per interpreter is supported", 0); RETURN_THROWS(); }
    e->server = ar_server_new((unsigned char *)address, len, connections, body, response, budget, timeout, requests, reuse_port);
    if (!e->server) { zend_throw_exception(zend_ce_exception, "Cannot bind HTTP server; use an available numeric IP:port", 0); RETURN_THROWS(); }
    if ((cert_len || key_len || ca_len) && ar_server_tls(e->server, (unsigned char *)cert, cert_len, (unsigned char *)key, key_len, (unsigned char *)ca, ca_len)) {
        ar_server_free(e->server); e->server = NULL;
        zend_value_error("Invalid TLS certificate, key or client CA configuration"); RETURN_THROWS();
    }
    if (stream_requests && ar_server_stream_requests(e->server)) {
        ar_server_free(e->server); e->server = NULL; server_error(); RETURN_THROWS();
    }
    if (half_close && ar_server_half_close(e->server)) {
        ar_server_free(e->server); e->server = NULL; server_error(); RETURN_THROWS();
    }
    ar_server_status s;
    if (ar_server_info(e->server, &s)) { server_error(); RETURN_THROWS(); }
    server_status(return_value, &s);
}
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_server_can_fork, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()
PHP_FUNCTION(asyncara_server_can_fork) {
    ZEND_PARSE_PARAMETERS_NONE();
    RETURN_BOOL(engine == NULL);
}
PHP_FUNCTION(asyncara_server_info) {
    ZEND_PARSE_PARAMETERS_NONE();
    if (!engine || !engine->server) RETURN_NULL();
    ar_server_status s;
    if (ar_server_info(engine->server, &s)) { server_error(); RETURN_THROWS(); }
    server_status(return_value, &s);
}
PHP_FUNCTION(asyncara_server_next) {
    ZEND_PARSE_PARAMETERS_NONE();
    if (!engine || !engine->server) { server_error(); RETURN_THROWS(); }
    ar_server_view view;
    int rc = ar_server_peek(engine->server, &view);
    if (rc < 0) { server_error(); RETURN_THROWS(); }
    if (!rc) RETURN_NULL();
    array_init(return_value);
    if (rc == 2) { add_assoc_long(return_value, "kind", 3); return; }
    add_assoc_long(return_value, "kind", view.kind);
    add_assoc_long(return_value, "id", view.id);
    add_assoc_long(return_value, "connection", view.connection);
    if (view.kind == 1 || view.kind == 11) {
        /* A PHP OOM bailout leaves the original event owned by Rust until
         * RSHUTDOWN. No C buffer or Rust borrow spans Zend execution. */
        add_assoc_stringl(return_value, "method", (const char *)view.method, view.method_len);
        add_assoc_stringl(return_value, "target", (const char *)view.target, view.target_len);
        add_assoc_stringl(return_value, "headers", (const char *)view.headers, view.headers_len);
        add_assoc_stringl(return_value, "body", (const char *)view.body, view.body_len);
    }
    if (view.kind == 6) add_assoc_stringl(return_value, "body", (const char *)view.body, view.body_len);
    if (ar_server_pop(engine->server)) { zval_ptr_dtor(return_value); ZVAL_NULL(return_value); server_error(); RETURN_THROWS(); }
}
PHP_FUNCTION(asyncara_server_respond) {
    zend_long id, status; HashTable *headers; char *body; size_t len; bool streaming = false;
    ZEND_PARSE_PARAMETERS_START(4, 5)
        Z_PARAM_LONG(id) Z_PARAM_LONG(status) Z_PARAM_ARRAY_HT(headers) Z_PARAM_STRING(body, len)
        Z_PARAM_OPTIONAL Z_PARAM_BOOL(streaming)
    ZEND_PARSE_PARAMETERS_END();
    if (!engine || !engine->server) { server_error(); RETURN_THROWS(); }
    if (id < 1 || status < 200 || status > 599 || zend_hash_num_elements(headers) > 100) { zend_value_error("Invalid server response"); RETURN_THROWS(); }
    ar_server_header values[100]; size_t count = 0; zend_string *key; zval *value;
    ZEND_HASH_FOREACH_STR_KEY_VAL(headers, key, value) {
        if (!key || Z_TYPE_P(value) != IS_STRING) { zend_type_error("Server headers must map names to strings"); RETURN_THROWS(); }
        values[count++] = (ar_server_header){(unsigned char *)ZSTR_VAL(key), ZSTR_LEN(key), (unsigned char *)Z_STRVAL_P(value), Z_STRLEN_P(value)};
    } ZEND_HASH_FOREACH_END();
    int rc = ar_server_respond(engine->server, id, status, values, count, (unsigned char *)body, len, streaming);
    if (rc == -2) { zend_value_error("Invalid or oversized response; framing headers are managed by the server"); RETURN_THROWS(); }
    if (rc == -3) { zend_throw_exception(zend_ce_exception, "HTTP server response buffer capacity exceeded", 0); RETURN_THROWS(); }
    if (rc < 0) { server_error(); RETURN_THROWS(); }
    RETURN_BOOL(rc);
}
PHP_FUNCTION(asyncara_server_shutdown) {
    ZEND_PARSE_PARAMETERS_NONE();
    if (engine && engine->server && ar_server_shutdown(engine->server)) { server_error(); RETURN_THROWS(); }
}
PHP_FUNCTION(asyncara_server_close) {
    ZEND_PARSE_PARAMETERS_NONE();
    if (engine && engine->server) { ar_server_free(engine->server); engine->server = NULL; }
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_server_chunk, 0, 3, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO(0, id, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, body, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, finish, IS_LONG, 0)
ZEND_END_ARG_INFO()
PHP_FUNCTION(asyncara_server_chunk) {
    zend_long id, finish; char *body; size_t len;
    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_LONG(id) Z_PARAM_STRING(body, len) Z_PARAM_LONG(finish)
    ZEND_PARSE_PARAMETERS_END();
    if (!engine || !engine->server) { server_error(); RETURN_THROWS(); }
    if (id < 1 || finish < -1 || finish > 1) { zend_value_error("Invalid stream operation"); RETURN_THROWS(); }
    int rc = ar_server_chunk(engine->server, id, (unsigned char *)body, len, finish);
    if (rc == -2) { zend_value_error("Stream chunk must be demanded, 1..65536 bytes and within responseBytes"); RETURN_THROWS(); }
    if (rc == -3) { zend_throw_exception(zend_ce_exception, "Stream payload capacity exceeded", 0); RETURN_THROWS(); }
    if (rc < 0) { server_error(); RETURN_THROWS(); }
    RETURN_BOOL(rc);
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_server_input, 0, 1, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, id, IS_LONG, 0)
ZEND_END_ARG_INFO()
PHP_FUNCTION(asyncara_server_input) {
    zend_long id;
    ZEND_PARSE_PARAMETERS_START(1, 1) Z_PARAM_LONG(id) ZEND_PARSE_PARAMETERS_END();
    if (!engine || !engine->server) { server_error(); RETURN_THROWS(); }
    if (id < 1) { zend_value_error("Invalid request ID"); RETURN_THROWS(); }
    int rc = ar_server_input(engine->server, id);
    if (rc == -2) { zend_throw_exception(zend_ce_exception, "Request body already has a pending reader", 0); RETURN_THROWS(); }
    if (rc < 0) { server_error(); RETURN_THROWS(); }
    RETURN_LONG(rc);
}
