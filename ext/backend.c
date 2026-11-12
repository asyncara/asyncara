#include "native.h"
#include <errno.h>
#include <limits.h>
#include <poll.h>
#include <stdbool.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>
#include <unistd.h>
#ifdef __linux__
#include <sys/epoll.h>
#endif
static int socket_changed(CURL *easy, curl_socket_t fd, int action, void *ctx, void *socketp) {
    (void)socketp;
    ar_engine *e = ctx;
    ar_socket **p = &e->sockets;
    while (*p && (*p)->fd != fd)
        p = &(*p)->next;
    if (action == CURL_POLL_REMOVE) {
        if (*p) {
            ar_socket *s = *p;
            *p = s->next;
            ar_free(s);
        }
        return 0;
    }
    if (!*p) {
        *p = ar_calloc(e, 1, sizeof(**p));
        if (!*p)
            return -1;
        (*p)->fd = fd;
    }
    (*p)->events = (action == CURL_POLL_IN || action == CURL_POLL_INOUT ? POLLIN : 0) |
                   (action == CURL_POLL_OUT || action == CURL_POLL_INOUT ? POLLOUT : 0);
    (*p)->owner = easy;
    return 0;
}
/* Snapshot curl's interests on the interpreter thread. Only paused transfers
 * need owner matching. The common buffered path is O(sockets + jobs), rather
 * than scanning every job for every socket on each unrelated kernel wakeup.
 * Keep curl's original interests intact: unpause must restore them next wait.
 * No job/handle pointer is retained beyond this calculation. */
static size_t http_wait_interests(ar_engine *e) {
    size_t count = 0;
    for (ar_socket *s = e->sockets; s; s = s->next) {
        s->wait_events = s->events;
        count++;
    }
    if (!count) return 0;
    for (ar_job *j = e->jobs; j; j = j->next) {
        if (j->op != OP_HTTP || j->done || (!j->receive_paused && !j->send_paused)) continue;
        for (ar_socket *s = e->sockets; s; s = s->next) {
            if (j->curl != s->owner) continue;
            if (j->receive_paused) s->wait_events &= ~POLLIN;
            if (j->send_paused) s->wait_events &= ~POLLOUT;
        }
    }
    return count;
}
static size_t receive_buffer(char *data, size_t size, size_t count, void *ctx, int header) {
    ar_job *j = ctx;
    if (size && count > SIZE_MAX / size)
        return 0;
    size_t n = size * count, *len = header ? &j->headers_len : &j->body_len;
    size_t *capacity = header ? &j->headers_capacity : &j->body_capacity;
    char **buf = header ? &j->response_headers : &j->body;
    size_t limit = header ? 65536 : (j->stream_response ? AR_HTTP_STREAM_LIMIT : AR_LIMIT);
    if (!header && j->stream_response && n <= limit && n > limit - *len) {
        /* Consume nothing: curl redelivers this entire chunk after resume. */
        j->receive_paused = 1;
        ar_trace_emit(j->engine, AR_TRACE_IO, 57, j->id, 0, *len, n);
        return CURL_WRITEFUNC_PAUSE;
    }
    if (n > limit - *len) {
        snprintf(j->error, sizeof(j->error), "HTTP %s limit exceeded", header ? "header" : "body");
        return 0;
    }
    if (atomic_load(&j->cancelled))
        return 0;
    size_t required = *len + n + 1;
    if (required > *capacity) {
        /* Grow payload space geometrically, reserving its trailing NUL
         * separately. Counting that byte as payload doubled storage for an
         * exact 4KiB/16KiB/64KiB/... body and could exhaust the aggregate ledger
         * on its final byte. Keep the same hard payload and pause bounds. */
        size_t next_capacity = *capacity > 1 ? *capacity : 4097;
        while (next_capacity < required)
            next_capacity = next_capacity - 1 > limit / 2 ? limit + 1 : (next_capacity - 1) * 2 + 1;
        char *next = ar_realloc(j->engine, *buf, next_capacity);
        if (!next) {
            snprintf(j->error, sizeof(j->error), "Native memory limit or allocation failure");
            return 0;
        }
        *buf = next;
        *capacity = next_capacity;
    }
    memcpy(*buf + *len, data, n);
    *len += n;
    (*buf)[*len] = 0;
    return n;
}
static size_t receive_body(char *p, size_t s, size_t n, void *ctx) {
    return receive_buffer(p, s, n, ctx, 0);
}
static size_t receive_header(char *p, size_t s, size_t n, void *ctx) {
    size_t received = receive_buffer(p, s, n, ctx, 1);
    ar_job *j = ctx;
    if (received && j->stream_response &&
        ((received == 2 && !memcmp(p, "\r\n", 2)) || (received == 1 && *p == '\n'))) {
        long status = 0;
        curl_easy_getinfo(j->curl, CURLINFO_RESPONSE_CODE, &status);
        if (status >= 200 && !(j->follow_redirects && status >= 300 && status < 400)) j->headers_ready = 1;
    }
    return received;
}
static size_t send_body(char *buffer, size_t size, size_t count, void *ctx) {
    ar_job *j = ctx;
    if ((size && count > SIZE_MAX / size) || atomic_load(&j->cancelled)) return CURL_READFUNC_ABORT;
    size_t available = j->upload_len - j->upload_offset;
    if (!available) {
        if (j->upload_eof) return 0;
        j->send_paused = 1;
        ar_trace_emit(j->engine, AR_TRACE_IO, 57, j->id, 1, 0, 0);
        return CURL_READFUNC_PAUSE;
    }
    size_t n = size * count;
    if (n > available) n = available;
    memcpy(buffer, j->upload + j->upload_offset, n);
    j->upload_offset += n;
    j->upload_sent += (int64_t)n;
    if (j->upload_offset == j->upload_len) j->upload_len = j->upload_offset = 0;
    return n;
}
/* All MIME files share one bounded upload slot. Curl advances parts only
 * after consuming EOF; the producer then becomes writable for the next file. */
static size_t send_part(char *buffer, size_t size, size_t count, void *ctx) {
    ar_http_part *part = ctx; ar_job *j = part->job;
    if (part->index != j->upload_index) {
        if (part->index != j->upload_index + 1 || !j->upload_eof || j->upload_len) return CURL_READFUNC_ABORT;
        j->upload_index = part->index; j->upload_eof = 0; j->upload_sent = 0;
    }
    return send_body(buffer, size, count, j);
}
static ar_value *array_option(ar_value *array, const char *name) {
    if (array) for (size_t i = 0; i < array->count; i++)
        if (array->keys[i] && !strcmp(array->keys[i], name)) return array->items[i];
    return NULL;
}
static ar_value *http_option(ar_job *j, const char *name) {
    if (j->params) for (size_t i = 0; i < j->params->count; i++)
        if (j->params->keys[i] && !strcmp(j->params->keys[i], name)) return j->params->items[i];
    return NULL;
}
/* libcurl multi errors invalidate the whole active set. Fail and detach it
 * before accepting another batch; never keep driving an uncertain multi. */
static void http_action(ar_engine *e, curl_socket_t fd, int events) {
    if (!e->multi) return;
    CURLMcode code = curl_multi_socket_action(e->multi, fd, events, &e->running);
    if (code == CURLM_OK) return;
    for (ar_job *j = e->jobs; j; j = j->next) {
        if (j->op != OP_HTTP || j->done || !j->curl) continue;
        curl_multi_remove_handle(e->multi, j->curl);
        if (!j->error[0]) snprintf(j->error, sizeof(j->error), "libcurl multi failed: %s", curl_multi_strerror(code));
        ar_done(e, j);
    }
    curl_multi_cleanup(e->multi); e->multi = NULL; e->running = 0;
    while (e->sockets) { ar_socket *s = e->sockets; e->sockets = s->next; ar_free(s); }
}
#ifdef HAVE_ASYNCARA_HYPER
/* Experimental bridge callbacks execute exclusively on the interpreter thread.
 * No Zend API or application callback is invoked while Rust owns a borrow. */
int ar_hyper_head(void *job, int status, const unsigned char *p, size_t n) {
    ar_job *j = job; if (status) j->status = status;
    if (receive_buffer((char *)p, 1, n, j, 1) != n) return -1;
    if (j->stream_response && status >= 200) {
        j->headers_ready = 1;
        j->engine->http_wakeup |= 1;
    }
    return 0;
}
int ar_hyper_body_size(void *job, uint64_t length) {
    ar_job *j = job;
    /* This is the existing buffered/encoded-body bound, not a stream-size
     * limit. Reject an impossible advertised body before allocating/reading
     * it; receive_buffer and the decoder still bound unknown/decoded lengths. */
    if (!j->stream_response && length > AR_LIMIT) {
        snprintf(j->error, sizeof(j->error), "HTTP body limit exceeded");
        return -1;
    }
    return 0;
}
int ar_hyper_encoding(void *job, int coding) {
    ar_job *j = job;
    if (!coding) return 0;
    if (j->stream_response || j->http_decoder || (coding < 1 || coding > 4)) return -1;
    j->http_decoder = ar_http_decoder_new(j->engine, coding);
    if (!j->http_decoder) {
        snprintf(j->error, sizeof(j->error), "Native memory limit or decoder allocation failure");
        return -1;
    }
    return 0;
}
static int decoded_body(void *context, const unsigned char *p, size_t n) {
    return receive_buffer((char *)p, 1, n, context, 0) == n ? 0 : -1;
}
int ar_hyper_chunk(void *job, const unsigned char *p, size_t n) {
    ar_job *j = job;
    if (j->http_decoder) {
        int result = ar_http_decoder_feed(j->http_decoder, p, n, decoded_body, j);
        if (result && !j->error[0]) snprintf(j->error, sizeof(j->error), "%s",
            result == -2 ? "HTTP compressed input limit exceeded" :
            result == -3 ? "Native memory limit or decoder allocation failure" : "Invalid compressed HTTP body");
        return result ? -1 : 0;
    }
    size_t accepted = receive_buffer((char *)p, 1, n, j, 0);
    if (accepted == CURL_WRITEFUNC_PAUSE) return 1;
    if (accepted != n) return -1;
    if (j->stream_response) j->engine->http_wakeup |= 1;
    return 0;
}
int ar_hyper_decoded_end(void *job) {
    ar_job *j = job;
    if (ar_http_decoder_finished(j->http_decoder)) return 0;
    if (!j->error[0]) snprintf(j->error, sizeof(j->error), "Truncated compressed HTTP body");
    return -1;
}
/* A borrowed upload view is copied by Rust before this callback's caller
 * returns. No Zend/application code or suspension runs across this boundary. */
int ar_hyper_upload(void *job, const unsigned char **data, size_t *length) {
    ar_job *j = job;
    if (!j->stream_upload || atomic_load(&j->cancelled)) return -1;
    size_t available = j->upload_len - j->upload_offset;
    if (!available) {
        if (j->upload_eof) return 2;
        j->send_paused = 1;
        ar_trace_emit(j->engine, AR_TRACE_IO, 57, j->id, 1, 0, 0);
        return 1;
    }
    *data = (const unsigned char *)j->upload + j->upload_offset;
    *length = available;
    j->upload_sent += (int64_t)available;
    j->upload_len = j->upload_offset = 0;
    j->engine->http_wakeup |= 1;
    return 0;
}
void ar_hyper_complete(void *job, const unsigned char *error, size_t n) {
    ar_job *j = job;
    if (n && !j->error[0]) snprintf(j->error, sizeof(j->error), "%.*s", (int)(n > 511 ? 511 : n), error);
    j->engine->hyper_completed++;
    ar_done(j->engine, j);
}
void ar_hyper_failed(ar_engine *e) {
    /* An unexpected Rust unwind invalidates the complete prototype engine. */
    ar_hyper_free(e->hyper); e->hyper = NULL;
    for (ar_job *j = e->jobs; j; j = j->next) {
        if (!j->http_hyper || j->done) continue;
        snprintf(j->error, sizeof(j->error), "Hyper engine failed");
        e->hyper_completed++;
        ar_done(e, j);
    }
}
static int hyper_progress(ar_engine *e) {
    int result = e->hyper ? ar_hyper_drive(e->hyper) : 0;
    if (result < 0) ar_hyper_failed(e);
    return result;
}
static void hyper_start(ar_engine *e, ar_job *j) {
    /* Deliberately narrow evaluation scope. Never silently bypass a proxy,
     * discard options, or mix independently bounded connection pools. */
    const char *proxy[] = {"http_proxy", "HTTP_PROXY", "https_proxy", "HTTPS_PROXY", "all_proxy", "ALL_PROXY"};
    for (size_t i = 0; i < sizeof(proxy)/sizeof(*proxy); i++) {
        const char *value = getenv(proxy[i]); if (value && *value) goto unsupported;
    }
    ar_hyper_proxy proxy_data = {0};
    uint64_t connect = 10000;
    const unsigned char *ca = NULL; size_t ca_len = 0;
    const unsigned char *cert = NULL, *key_bytes = NULL; size_t cert_len = 0, key_len = 0;
    ar_hyper_address addresses[16], targets[16]; size_t count = 0, target_count = 0;
    ar_hyper_address headers[256];
    ar_hyper_request request = {(const unsigned char *)"GET", 3,
        (const unsigned char *)j->b, j->b_len, headers, 0};
    if (j->params) for (size_t i = 0; i < j->params->count; i++) {
        const char *key = j->params->keys[i]; ar_value *v = j->params->items[i];
        if (!key) goto unsupported;
        if (!strcmp(key, "method")) {
            if (v->type != V_STRING) goto unsupported;
            request.method = (const unsigned char *)v->string; request.method_len = v->length;
        }
        else if (!strcmp(key, "headers")) {
            if (v->type != V_ARRAY || v->count > 256) goto unsupported;
            request.header_count = v->count;
            for (size_t k = 0; k < v->count; k++) {
                ar_value *header = v->items[k];
                if (header->type != V_STRING || header->length > 65536) goto unsupported;
                headers[k] = (ar_hyper_address){(const unsigned char *)header->string, header->length};
            }
        }
        else if (!strcmp(key, "followRedirects")) { if (v->integer) goto unsupported; }
        else if (!strcmp(key, "streamResponse") || !strcmp(key, "uploadSize")) { /* validated at submission */ }
        else if (!strcmp(key, "connectTimeoutMs")) connect = v->integer;
        else if (!strcmp(key, "proxy") || !strcmp(key, "proxyCaPem") || !strcmp(key, "proxyUsername") || !strcmp(key, "proxyPassword")) {
            if (v->type != V_STRING) goto unsupported;
            ar_hyper_address data = {(const unsigned char *)v->string, v->length};
            if (!strcmp(key, "proxy")) proxy_data.url = data;
            else if (!strcmp(key, "proxyCaPem")) proxy_data.roots = data;
            else if (!strcmp(key, "proxyUsername")) proxy_data.user = data;
            else proxy_data.password = data;
        }
        else if (!strcmp(key, "caPem")) {
            if (v->type != V_STRING || !v->length || v->length > 1048576) goto unsupported;
            ca = (const unsigned char *)v->string; ca_len = v->length;
        }
        else if (!strcmp(key, "clientCertPem")) {
            if (v->type != V_STRING || !v->length || v->length > 1048576) goto unsupported;
            cert = (const unsigned char *)v->string; cert_len = v->length;
        }
        else if (!strcmp(key, "clientKeyPem")) {
            if (v->type != V_STRING || !v->length || v->length > 65536) goto unsupported;
            key_bytes = (const unsigned char *)v->string; key_len = v->length;
        }
        else if (!strcmp(key, "resolvedAddresses") || !strcmp(key, "proxyResolvedAddresses")) {
            if (v->type != V_ARRAY || !v->count || v->count > 16) goto unsupported;
            bool local = !strcmp(key, "proxyResolvedAddresses");
            if (local) target_count = v->count; else count = v->count;
            for (size_t k = 0; k < v->count; k++) {
                ar_value *ip = v->items[k];
                if (ip->type != V_STRING || !ip->length || ip->length > 45) goto unsupported;
                (local ? targets : addresses)[k] = (ar_hyper_address){(const unsigned char *)ip->string, ip->length};
            }
        }
        else goto unsupported;
    }
    if (!e->hyper) e->hyper = ar_hyper_new(e->http_max_connections, e->http_max_host_connections);
    if (!e->hyper) goto unsupported;
    /* Rust copies validated addresses synchronously; no borrowed PHP options
     * or resolver buffers survive this call. */
    unsigned streams = (j->stream_response ? 1u : 0u) | (j->stream_upload ? 2u : 0u);
    int submitted = ar_hyper_submit_http_route(e->hyper, j, (const unsigned char *)j->a, strlen(j->a), j->length ? j->length : 30000, connect, addresses, count, ca, ca_len, &request, streams, j->upload_size, cert, cert_len, key_bytes, key_len, proxy_data.url.bytes ? &proxy_data : NULL, targets, target_count);
    if (submitted == -2) {
        /* Submission may unwind after retaining the new job pointer. Drop all
         * Rust owners before this unadmitted C job can be collected/freed. */
        ar_hyper_failed(e);
        snprintf(j->error, sizeof(j->error), "Hyper engine failed"); ar_done(e, j);
        return;
    }
    if (submitted) goto unsupported;
    j->http_hyper = 1; e->hyper_submitted++;
    if (streams) e->http_wakeup |= 1;
    return;
unsupported:
    snprintf(j->error, sizeof(j->error), "Invalid or unsupported Hyper HTTP(S) transport options, identity, framing or capacity");
    ar_done(e, j);
}
#endif
void ar_http_start(ar_engine *e, ar_job *j) {
    bool head_request = false;
    if (e->hyper_requested) {
#ifdef HAVE_ASYNCARA_HYPER
        hyper_start(e, j);
#else
        snprintf(j->error, sizeof(j->error), "Hyper prototype not built"); ar_done(e, j);
#endif
        return;
    }
    if (!e->multi) {
        e->multi = curl_multi_init();
        if (!e->multi)
            goto error;
        if (curl_multi_setopt(e->multi, CURLMOPT_SOCKETFUNCTION, socket_changed) != CURLM_OK ||
            curl_multi_setopt(e->multi, CURLMOPT_SOCKETDATA, e) != CURLM_OK ||
            curl_multi_setopt(e->multi, CURLMOPT_MAXCONNECTS, (long)e->http_max_connections) != CURLM_OK ||
            curl_multi_setopt(e->multi, CURLMOPT_MAX_TOTAL_CONNECTIONS, (long)e->http_max_connections) != CURLM_OK ||
            curl_multi_setopt(e->multi, CURLMOPT_MAX_HOST_CONNECTIONS, (long)e->http_max_host_connections) != CURLM_OK) {
            curl_multi_cleanup(e->multi); e->multi = NULL; goto error;
        }
    }
    if (!(curl_version_info(CURLVERSION_NOW)->features & CURL_VERSION_ASYNCHDNS)) {
        snprintf(j->error, sizeof(j->error), "libcurl asynchronous DNS required");
        ar_done(e, j);
        return;
    }
    if (e->http_idle_count) {
        j->curl = e->http_idle[--e->http_idle_count]; e->http_reused++;
    } else {
        j->curl = curl_easy_init();
        if (j->curl) e->http_created++;
    }
    if (!j->curl) goto error;
#define SET(opt, val)                                                                                        \
    do {                                                                                                     \
        CURLcode set_result = curl_easy_setopt(j->curl, opt, val);                                             \
        if (set_result != CURLE_OK) {                                                                         \
            snprintf(j->error, sizeof(j->error), "libcurl option %s failed: %s", #opt, curl_easy_strerror(set_result)); \
            goto error;                                                                                      \
        }                                                                                      \
    } while (0)
    SET(CURLOPT_URL, j->a);
    SET(CURLOPT_PROTOCOLS_STR, "http,https");
    SET(CURLOPT_REDIR_PROTOCOLS_STR, "http,https");
    SET(CURLOPT_PRIVATE, j);
    SET(CURLOPT_NOSIGNAL, 1L);
    SET(CURLOPT_SSL_VERIFYPEER, 1L);
    SET(CURLOPT_SSL_VERIFYHOST, 2L);
    /* Origin headers must never be copied to CONNECT. Authentication uses
     * curl's proxy credential options, not an origin Authorization header. */
    SET(CURLOPT_HEADEROPT, (long)CURLHEADER_SEPARATE);
    SET(CURLOPT_FOLLOWLOCATION, 0L);
    SET(CURLOPT_MAXREDIRS, 5L);
    SET(CURLOPT_CONNECTTIMEOUT_MS, 10000L);
    SET(CURLOPT_TIMEOUT_MS, (long)(j->length ? j->length : 30000));
    SET(CURLOPT_WRITEFUNCTION, receive_body);
    SET(CURLOPT_WRITEDATA, j);
    SET(CURLOPT_HEADERFUNCTION, receive_header);
    SET(CURLOPT_HEADERDATA, j);
    if (j->stream_response) {
        /* Avoid unbounded decompression/multiplexed-stream buffers inside curl
         * while paused. Deliver encoded bytes if a peer ignores identity. */
        SET(CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        SET(CURLOPT_ACCEPT_ENCODING, "identity");
        SET(CURLOPT_HTTP_CONTENT_DECODING, 0L);
        SET(CURLOPT_SUPPRESS_CONNECT_HEADERS, 1L);
    } else {
        SET(CURLOPT_ACCEPT_ENCODING, "");
        /* Buffered transfers can read a medium response in fewer socket calls.
         * This is curl's receive workspace, not the owned body limit. Keep
         * streaming on curl's default geometry so pause/redelivery bounds do
         * not change. Set before multi admission, never on an active handle. */
        SET(CURLOPT_BUFFERSIZE, 128L * 1024L);
    }
    if (j->params)
        for (size_t i = 0; i < j->params->count; i++) {
            const char *key = j->params->keys[i];
            ar_value *v = j->params->items[i];
            if (!key)
                continue;
            if (!strcmp(key, "method")) {
                if (!strcmp(v->string, "HEAD")) {
                    /* Enforce this boundary for non-Zend native callers too.
                     * No handle has been added to curl multi at this point. */
                    if (j->stream_upload || j->b_len) {
                        snprintf(j->error, sizeof(j->error), "HEAD does not support request content or uploads");
                        ar_done(e, j);
                        return;
                    }
                    head_request = true;
                    SET(CURLOPT_NOBODY, 1L);
                }
                else if (!strcmp(v->string, "POST")) SET(CURLOPT_POST, 1L);
                else if (strcmp(v->string, "GET") || j->b) SET(CURLOPT_CUSTOMREQUEST, v->string);
            } else if (!strcmp(key, "connectTimeoutMs")) {
                SET(CURLOPT_CONNECTTIMEOUT_MS, (long)v->integer);
            } else if (!strcmp(key, "proxy")) {
                SET(CURLOPT_PROXY, v->string);
                SET(CURLOPT_NOPROXY, "");
                SET(CURLOPT_PROXY_SSL_VERIFYPEER, 1L);
                SET(CURLOPT_PROXY_SSL_VERIFYHOST, 2L);
            } else if (!strcmp(key, "proxyUsername")) {
                SET(CURLOPT_PROXYAUTH, (long)CURLAUTH_BASIC);
                SET(CURLOPT_SOCKS5_AUTH, (long)CURLAUTH_BASIC);
                SET(CURLOPT_PROXYUSERNAME, v->string);
            } else if (!strcmp(key, "proxyPassword")) {
                SET(CURLOPT_PROXYPASSWORD, v->string);
            } else if (!strcmp(key, "caPem")) {
                snprintf(j->error, sizeof(j->error), "caPem is internal to the Hyper backend"); ar_done(e, j); return;
            } else if (!strcmp(key, "clientCertPem") || !strcmp(key, "clientKeyPem")) {
                /* Owned, worker-loaded material; libcurl copies it before the
                 * job option tree is released. No synchronous key-file read. */
                struct curl_blob blob = {v->string, v->length, CURL_BLOB_COPY};
                if (!strcmp(key, "clientCertPem")) {
                    SET(CURLOPT_SSLCERTTYPE, "PEM"); SET(CURLOPT_SSLCERT_BLOB, &blob);
                } else {
                    SET(CURLOPT_SSLKEYTYPE, "PEM"); SET(CURLOPT_SSLKEY_BLOB, &blob);
                }
            } else if (!strcmp(key, "caFile")) {
                SET(CURLOPT_CAINFO, v->string);
            } else if (!strcmp(key, "proxyCaFile")) {
                /* Proxy and origin trust are independent TLS layers. Never
                 * reuse origin roots implicitly or disable either verifier. */
                SET(CURLOPT_PROXY_CAINFO, v->string);
            } else if (!strcmp(key, "followRedirects")) {
                j->follow_redirects = (int)v->integer;
                SET(CURLOPT_FOLLOWLOCATION, (long)v->integer);
            } else if (!strcmp(key, "headers")) {
                for (size_t h = 0; h < v->count; h++) {
                    struct curl_slist *list = curl_slist_append(j->headers, v->items[h]->string);
                    if (!list)
                        goto error;
                    j->headers = list;
                }
            }
        }
    if (j->b && !head_request) {
        SET(CURLOPT_POSTFIELDS, j->b);
        SET(CURLOPT_POSTFIELDSIZE_LARGE, (curl_off_t)j->b_len);
    }
    if (j->stream_upload) {
        ar_value *fields = http_option(j, "multipart");
        if (fields) {
            j->mime = curl_mime_init(j->curl);
            if (!j->mime) goto error;
            for (size_t i = 0; i < fields->count; i++) {
                curl_mimepart *part = curl_mime_addpart(j->mime);
                if (!part || curl_mime_name(part, fields->keys[i]) != CURLE_OK ||
                    curl_mime_data(part, fields->items[i]->string, fields->items[i]->length) != CURLE_OK) goto error;
            }
            ar_value *files = http_option(j, "files");
            j->upload_part_count = files ? files->count : 1;
            j->upload_parts = ar_calloc(e, j->upload_part_count, sizeof(*j->upload_parts));
            if (!j->upload_parts) goto error;
            for (size_t i = 0; i < j->upload_part_count; i++) {
                curl_mimepart *part = curl_mime_addpart(j->mime);
                ar_value *field = files ? array_option(files->items[i], "field") : http_option(j, "fileField");
                ar_value *name = files ? array_option(files->items[i], "name") : http_option(j, "fileName");
                ar_value *type = files ? array_option(files->items[i], "type") : http_option(j, "fileType");
                j->upload_parts[i] = (ar_http_part){j, i};
                if (!part || !field || !name || !type ||
                    curl_mime_name(part, field->string) != CURLE_OK ||
                    curl_mime_filename(part, name->string) != CURLE_OK ||
                    curl_mime_type(part, type->string) != CURLE_OK ||
                    curl_mime_data_cb(part, files ? (curl_off_t)-1 : (curl_off_t)j->upload_size,
                                      send_part, NULL, NULL, &j->upload_parts[i]) != CURLE_OK) goto error;
            }
            SET(CURLOPT_MIMEPOST, j->mime);
        } else {
            SET(CURLOPT_UPLOAD, 1L);
            SET(CURLOPT_READFUNCTION, send_body);
            SET(CURLOPT_READDATA, j);
            SET(CURLOPT_INFILESIZE_LARGE, (curl_off_t)j->upload_size);
        }
        /* CURLOPT_UPLOAD selects PUT internally. Preserve the caller's method
         * for streamed POST/PATCH/etc; these streams never enable replay. */
        ar_value *method = http_option(j, "method");
        if (method) SET(CURLOPT_CUSTOMREQUEST, method->string);
        SET(CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    }
    if (j->headers) {
        SET(CURLOPT_HTTPHEADER, j->headers);
    }
    if (curl_multi_add_handle(e->multi, j->curl) != CURLM_OK)
        goto error;
    /* One kick starts all admitted handles together. Only streamed data needs
     * an immediate producer/consumer dispatch; buffered admission may wait for
     * actual readiness. Preserve an already requested streaming dispatch. */
    e->http_wakeup |= (j->stream_response || j->stream_upload) ? 1 : 2;
#undef SET
    return;
error:
    if (!j->error[0]) snprintf(j->error, sizeof(j->error), "libcurl initialization failed");
    ar_done(e, j);
}
void ar_http_progress(ar_engine *e) {
    if (!e->multi)
        return;
    int remaining;
    CURLMsg *msg;
    while ((msg = curl_multi_info_read(e->multi, &remaining))) {
        if (msg->msg != CURLMSG_DONE)
            continue;
        ar_job *j = NULL;
        curl_easy_getinfo(msg->easy_handle, CURLINFO_PRIVATE, &j);
        if (!j || j->done)
            continue;
        curl_easy_getinfo(j->curl, CURLINFO_RESPONSE_CODE, &j->status);
        if (msg->data.result != CURLE_OK && !j->error[0])
            snprintf(j->error, sizeof(j->error), "%s", curl_easy_strerror(msg->data.result));
        /* Keep completed bytes on their existing job owner until PHP collects
         * them. No generic ar_value tree, duplicated keys or interim arrays are
         * needed for this fixed response shape. Fatal conversion still leaves
         * the job on the request allocation list for RSHUTDOWN. */
        curl_multi_remove_handle(e->multi, j->curl);
        ar_done(e, j);
    }
}
/* Bounded transient control buffers deliberately bypass the payload budget so
 * an exhausted budget cannot prevent completion draining or cancellation. */
/* The caller supplies a relative timer budget. Preserve its absolute monotonic
 * deadline through curl progress, allocations and epoll registration instead of
 * restarting that budget at the kernel boundary. -1 is infinite, 0 is immediate. */
static int monotonic_ns(int64_t *value) {
    struct timespec now;
    if (clock_gettime(CLOCK_MONOTONIC, &now)) return -1;
    if (now.tv_sec < 0 || (uint64_t)now.tv_sec > (uint64_t)(INT64_MAX - 999999999) / 1000000000) {
        errno = EOVERFLOW;
        return -1;
    }
    *value = (int64_t)now.tv_sec * 1000000000 + now.tv_nsec;
    return 0;
}
static int deadline_for(int timeout, int64_t *deadline) {
    if (timeout <= 0) { *deadline = timeout < 0 ? -1 : 0; return 0; }
    int64_t now, duration = (int64_t)timeout * 1000000;
    if (monotonic_ns(&now)) return -1;
    if (now > INT64_MAX - duration) { errno = EOVERFLOW; return -1; }
    *deadline = now + duration;
    return 0;
}
/* -2 reports a clock failure, distinct from an infinite wait. */
static int remaining_ms(int64_t deadline) {
    if (deadline <= 0) return (int)deadline;
    int64_t now;
    if (monotonic_ns(&now)) return -2;
    if (now >= deadline) return 0;
    int64_t difference = deadline - now;
    int64_t millis = difference / 1000000 + (difference % 1000000 != 0);
    return millis > INT_MAX ? INT_MAX : (int)millis;
}
static int64_t trace_elapsed(uint64_t began) {
    if (!began) return -1;
    uint64_t end = ar_trace_clock();
    return end >= began ? (int64_t)(end - began) : -1;
}
#ifdef __linux__
struct ar_wait_cache {
    int ep, *fds;
    size_t count, capacity;
    struct epoll_event *events;
};
static void wait_invalidate(struct ar_wait_cache *c) {
    if (c->ep >= 0) close(c->ep);
    c->ep = -1; c->count = 0;
}
#endif
void ar_wait_cleanup(ar_engine *e) {
#ifdef __linux__
    struct ar_wait_cache *c = e->wait_cache;
    if (c) {
        wait_invalidate(c);
        free(c->fds); free(c->events); free(c);
        e->wait_cache = NULL;
    }
#endif
    e->wait_control_bytes = 0;
}
int ar_wait_initialize(ar_engine *e) {
#ifdef __linux__
    /* The epoll descriptor belongs to the engine from initialization through
     * shutdown, just like its wake pipe. Lazy first-wait ownership would add
     * a descriptor after a caller's initialized-runtime lifecycle baseline. */
    if (e->use_epoll && !e->wait_cache) {
        struct ar_wait_cache *c = calloc(1, sizeof(*c));
        if (!c) { errno = ENOMEM; return -1; }
        c->ep = epoll_create1(EPOLL_CLOEXEC);
        if (c->ep < 0) { int saved = errno; free(c); errno = saved; return -1; }
        e->wait_cache = c; e->wait_control_bytes = sizeof(*c); e->epoll_created++;
    }
#else
    (void)e;
#endif
    return 0;
}
#ifdef __linux__
static struct ar_wait_cache *wait_prepare(ar_engine *e, struct pollfd *fds, size_t n) {
    if (!n || n > AR_MAX_WATCHERS) { errno = EOVERFLOW; return NULL; }
    struct ar_wait_cache *c = e->wait_cache;
    if (!c) {
        c = calloc(1, sizeof(*c));
        if (!c) { errno = ENOMEM; return NULL; }
        c->ep = -1; e->wait_cache = c;
        e->wait_control_bytes = sizeof(*c);
    }
    if (n > c->capacity) {
        /* Free before growing: even transient retained storage is bounded by
         * sizeof(cache)+AR_MAX_WATCHERS*(sizeof(int)+sizeof(epoll_event)).
         * Like existing wait scratch, control storage is outside the payload
         * ledger so draining a full payload budget remains possible. */
        if (c->count) wait_invalidate(c);
        free(c->fds); free(c->events); c->fds = NULL; c->events = NULL;
        size_t capacity = c->capacity ? c->capacity : 8;
        while (capacity < n) capacity *= 2;
        if (capacity > AR_MAX_WATCHERS) capacity = AR_MAX_WATCHERS;
        c->capacity = 0;
        c->fds = calloc(capacity, sizeof(*c->fds));
        c->events = calloc(capacity, sizeof(*c->events));
        if (!c->fds || !c->events) {
            ar_wait_cleanup(e); errno = ENOMEM; return NULL;
        }
        c->capacity = capacity;
        e->wait_control_bytes = sizeof(*c) + capacity * (sizeof(*c->fds) + sizeof(*c->events));
    }
    int reuse = c->ep >= 0 && c->count == n;
    for (size_t i = 0; reuse && i < n; i++) reuse = c->fds[i] == fds[i].fd;
    if (reuse) {
        /* Always MOD: numeric fd equality does not establish open-file-
         * description identity. Closing/reusing fd while a dup retains its
         * old OFD leaves an old registration alive. ENOENT must rebuild the
         * entire instance, never ADD alongside that orphan registration. */
        for (size_t i = 0; i < n; i++) {
            struct epoll_event ev = {.events = (fds[i].events & POLLIN ? EPOLLIN : 0) |
                                               (fds[i].events & POLLOUT ? EPOLLOUT : 0),
                                     .data.u64 = i};
            if (epoll_ctl(c->ep, EPOLL_CTL_MOD, fds[i].fd, &ev)) {
                if (errno == ENOENT) { reuse = 0; break; }
                goto error;
            }
        }
        if (reuse) { e->epoll_reused++; return c; }
    }
    if (c->count) wait_invalidate(c);
    if (c->ep < 0) {
        c->ep = epoll_create1(EPOLL_CLOEXEC);
        if (c->ep < 0) return NULL;
        e->epoll_created++;
    }
    for (size_t i = 0; i < n; i++) {
        struct epoll_event ev = {.events = (fds[i].events & POLLIN ? EPOLLIN : 0) |
                                           (fds[i].events & POLLOUT ? EPOLLOUT : 0), .data.u64 = i};
        if (epoll_ctl(c->ep, EPOLL_CTL_ADD, fds[i].fd, &ev)) goto error;
        c->fds[i] = fds[i].fd;
    }
    c->count = n;
    return c;
error:;
    int saved = errno;
    wait_invalidate(c); errno = saved;
    return NULL;
}
#endif
static int wait_fds(ar_engine *e, struct pollfd *fds, size_t n, int64_t deadline) {
#ifdef __linux__
    if (e->use_epoll) {
        struct ar_wait_cache *c = wait_prepare(e, fds, n);
        if (!c) return -1;
        int timeout = remaining_ms(deadline);
        ar_trace_emit(e, AR_TRACE_WAIT, 11, e->wait_id, timeout, n, 1);
        uint64_t began = ar_trace_enabled(e, AR_TRACE_WAIT) ? ar_trace_clock() : 0;
        int rc = timeout == -2 ? -1 : epoll_wait(c->ep, c->events, (int)n, timeout);
        ar_trace_emit(e, AR_TRACE_WAIT, 12, e->wait_id, rc, rc < 0 ? errno : 0, trace_elapsed(began));
        if (rc >= 0)
            for (int i = 0; i < rc; i++) {
                uint32_t ev = c->events[i].events;
                fds[c->events[i].data.u64].revents =
                    (ev & EPOLLIN ? POLLIN : 0) | (ev & EPOLLOUT ? POLLOUT : 0) |
                    (ev & EPOLLHUP ? POLLHUP : 0) | (ev & EPOLLERR ? POLLERR : 0);
            }
        return rc;
    }
#endif
    int timeout = remaining_ms(deadline);
    ar_trace_emit(e, AR_TRACE_WAIT, 11, e->wait_id, timeout, n, 0);
    uint64_t began = ar_trace_enabled(e, AR_TRACE_WAIT) ? ar_trace_clock() : 0;
    int rc = timeout == -2 ? -1 : poll(fds, n, timeout);
    ar_trace_emit(e, AR_TRACE_WAIT, 12, e->wait_id, rc, rc < 0 ? errno : 0, trace_elapsed(began));
    return rc;
}
static int wait_impl(ar_engine *e, ar_watch *w, size_t n, int timeout) {
    /* Validate before watcher dereferences or sizing arithmetic. The PHP entry
     * point has the same bound; native callers must obey it too. */
    if (n >= AR_MAX_WATCHERS) { errno = EOVERFLOW; return -1; }
    int64_t deadline;
    if (deadline_for(timeout, &deadline)) return -1;
    ar_trace_emit(e, AR_TRACE_WAIT, 10, e->wait_id, timeout, n, deadline);
    if (e->http_wakeup) {
        int dispatch = e->http_wakeup & 1;
        e->http_wakeup = 0;
        if (e->multi) http_action(e, CURL_SOCKET_TIMEOUT, 0);
        /* New application data can consume an upload buffer immediately, or
         * resume can redeliver response bytes. Dispatch those state changes to
         * Fibers before blocking on another network event. */
        if (dispatch) deadline = 0;
    }
    ar_http_progress(e);
#ifdef HAVE_ASYNCARA_HYPER
    if (hyper_progress(e)) deadline = 0;
    /* Hyper can publish stream headers/data or consume an upload while its
     * drive reaches quiescence. Return those transitions to PHP before waiting. */
    if (e->http_wakeup & 1) deadline = 0;
    int hyper_timeout = e->hyper ? ar_hyper_timeout(e->hyper) : -1;
    if (hyper_timeout == -2) { ar_hyper_failed(e); deadline = 0; }
    if (hyper_timeout >= 0 && deadline != 0) {
        int64_t hyper_deadline;
        if (deadline_for(hyper_timeout, &hyper_deadline)) return -1;
        if (deadline < 0 || hyper_deadline < deadline) deadline = hyper_deadline;
    }
    if (e->server) {
        if (ar_server_drive(e->server) < 0) { errno = EIO; return -1; }
        int server_timeout = ar_server_timeout(e->server);
        if (server_timeout == -2) { errno = EIO; return -1; }
        if (server_timeout >= 0 && deadline != 0) {
            int64_t server_deadline;
            if (deadline_for(server_timeout, &server_deadline)) return -1;
            if (deadline < 0 || server_deadline < deadline) deadline = server_deadline;
        }
    }
#endif
    pthread_mutex_lock(&e->mutex);
    int done = e->done_head != NULL;
    pthread_mutex_unlock(&e->mutex);
    if (done)
        deadline = 0;
    for (size_t i = 0; i < n; i++)
        if (w[i].buffered)
            deadline = 0;
    long curl_timeout = -1;
    if (e->multi) {
        curl_multi_timeout(e->multi, &curl_timeout);
        if (curl_timeout >= 0 && deadline != 0) {
            int64_t curl_deadline;
            if (deadline_for(curl_timeout > INT_MAX ? INT_MAX : (int)curl_timeout, &curl_deadline)) return -1;
            if (deadline < 0 || curl_deadline < deadline) deadline = curl_deadline;
        }
    }
    size_t ns = http_wait_interests(e);
    size_t nh = 0, nv = 0;
#ifdef HAVE_ASYNCARA_HYPER
    if (e->hyper) nh = ar_hyper_fds(e->hyper, NULL, 0);
    if (nh == SIZE_MAX) { ar_hyper_failed(e); nh = 0; deadline = 0; }
    if (e->server) nv = ar_server_fds(e->server, NULL, 0);
    if (nv == SIZE_MAX) { errno = EIO; return -1; }
#endif
    if (nh > 256 || nv > AR_MAX_WATCHERS || n + ns + nh + nv + 1 > AR_MAX_WATCHERS) {
        errno = EOVERFLOW;
        return -1;
    }
    struct pollfd *fds = calloc(n + ns + nh + nv + 1, sizeof(*fds));
    /* Preserve the small-set path. Larger sets previously compared every new
     * descriptor with every existing descriptor (quadratic setup each wait).
     * A <= half-full index merges interests without changing input/dispatch
     * order. Its strict extra bound is 2*AR_MAX_WATCHERS uint32_t slots. */
    size_t slots = 0;
    if (n >= 32) { slots = 1; while (slots < n * 2) slots *= 2; }
    size_t index_bytes = (n ? n : 1) * sizeof(size_t);
    size_t *indices = calloc(1, index_bytes + slots * sizeof(uint32_t));
    if (!fds || !indices) {
        free(fds);
        free(indices);
        errno = ENOMEM;
        return -1;
    }
    size_t count = 1;
    fds[0].fd = e->wake[0];
    fds[0].events = POLLIN;
    uint32_t *lookup = (uint32_t *)((char *)indices + index_bytes);
    for (size_t i = 0; i < n; i++) {
        size_t k = 1;
        if (slots) {
            uint32_t hash = (uint32_t)w[i].fd;
            hash ^= hash >> 16; hash *= 0x7feb352du; hash ^= hash >> 15;
            size_t at = hash & (slots - 1);
            while (lookup[at] && fds[lookup[at]].fd != w[i].fd) at = (at + 1) & (slots - 1);
            k = lookup[at];
            if (!k) { k = count; lookup[at] = (uint32_t)count; }
        } else {
            while (k < count && fds[k].fd != w[i].fd) k++;
        }
        if (k == count) {
            fds[k].fd = w[i].fd;
            count++;
        }
        fds[k].events |= w[i].events;
        ar_trace_emit(e, AR_TRACE_IO, 58, w[i].key, w[i].fd, w[i].events, w[i].buffered);
        indices[i] = k;
    }
    size_t curl_start = count;
    for (ar_socket *s = e->sockets; s; s = s->next) {
        int events = s->wait_events;
        /* curl 8.5 can retain its read interest while a write callback is
         * paused. A readable peer would then wake epoll forever. Streaming
         * responses force HTTP/1.1, so their active socket is not multiplexed.
         * Do not watch a fully paused socket: HUP also wakes a zero-event poll.
         * Completion/cancellation deadlines and worker wakeups still apply. */
        if (!events) continue;
        fds[count].fd = s->fd;
        fds[count++].events = events;
    }
    size_t curl_end = count;
#ifdef HAVE_ASYNCARA_HYPER
    if (e->hyper) {
        size_t actual = ar_hyper_fds(e->hyper, fds + count, nh);
        if (actual > nh) { ar_hyper_failed(e); deadline = 0; }
        else count += actual;
    }
    size_t server_start = count;
    if (e->server) {
        size_t actual = ar_server_fds(e->server, fds + count, nv);
        if (actual > nv) { free(fds); free(indices); errno = EIO; return -1; }
        count += actual;
    }
#endif
    int rc = wait_fds(e, fds, count, deadline);
    /* Progress below may call recv/accept and replace errno with EAGAIN. Keep
     * the actual wait result, especially EINTR from graceful stop signals. */
    int wait_errno = rc < 0 ? errno : 0;
    if (rc >= 0) {
        for (size_t i = 0; i < n; i++)
            w[i].ready = w[i].buffered ||
                         (fds[indices[i]].revents & (w[i].events | POLLERR | POLLHUP | POLLNVAL));
        for (size_t i = 0; i < n; i++) if (w[i].ready)
            ar_trace_emit(e, AR_TRACE_IO, 52, w[i].key, w[i].fd, fds[indices[i]].revents, w[i].buffered);
        if (e->multi) {
            for (size_t i = curl_start; i < curl_end; i++)
                if (fds[i].revents) {
                    int action = (fds[i].revents & POLLIN ? CURL_CSELECT_IN : 0) |
                                 (fds[i].revents & POLLOUT ? CURL_CSELECT_OUT : 0) |
                                 (fds[i].revents & (POLLERR | POLLHUP) ? CURL_CSELECT_ERR : 0);
                    http_action(e, fds[i].fd, action);
                }
            /* Socket actions may replace/remove curl's timer. Drive the timer
             * only when it is due; an unrelated ready descriptor is not a curl
             * timeout and must not rescan every active transfer. */
            long due = -1;
            if (e->multi && curl_multi_timeout(e->multi, &due) == CURLM_OK && due == 0)
                http_action(e, CURL_SOCKET_TIMEOUT, 0);
            ar_http_progress(e);
        }
    }
#ifdef HAVE_ASYNCARA_HYPER
    if (e->hyper) {
        for (size_t i = curl_end; i < server_start; i++) if (fds[i].revents) {
            if (ar_hyper_ready(e->hyper, fds[i].fd, fds[i].revents)) {
                ar_hyper_failed(e); break;
            }
        }
        hyper_progress(e);
    }
    if (e->server) {
        for (size_t i = server_start; i < count; i++) if (fds[i].revents) {
            if (ar_server_ready(e->server, fds[i].fd, fds[i].revents)) { rc = -1; wait_errno = EIO; break; }
        }
        if (ar_server_drive(e->server)) { rc = -1; wait_errno = EIO; }
    }
#endif
    int saved = rc < 0 ? wait_errno : errno;
    free(fds);
    free(indices);
    errno = saved;
    return rc < 0 && errno == EINTR ? 0 : rc;
}

int ar_wait(ar_engine *e, ar_watch *w, size_t n, int timeout) {
    e->wait_id++;
    int result = wait_impl(e, w, n, timeout);
    ar_trace_emit(e, AR_TRACE_WAIT, 13, e->wait_id, result, result < 0 ? errno : 0, e->pending);
    return result;
}
