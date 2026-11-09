#include "native.h"
#include <errno.h>
#include <limits.h>
#include <poll.h>
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
        size_t next_capacity = *capacity ? *capacity : 4096;
        while (next_capacity < required)
            next_capacity = next_capacity > (limit + 1) / 2 ? limit + 1 : next_capacity * 2;
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
/* Transfer ownership of completed buffers instead of copying and charging a
 * second full response. Failed value construction leaves the job as owner. */
static ar_value *take_http_buffer(ar_engine *e, char **buffer, size_t *length, size_t *capacity) {
    if (!*buffer) return ar_string(e, "", 0);
    ar_value *v = ar_value_new(e, V_STRING);
    if (!v) return NULL;
    v->string = *buffer; v->length = *length;
    *buffer = NULL; *length = *capacity = 0;
    return v;
}
void ar_http_start(ar_engine *e, ar_job *j) {
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
        if (curl_easy_setopt(j->curl, opt, val) != CURLE_OK)                                                 \
            goto error;                                                                                      \
    } while (0)
    SET(CURLOPT_URL, j->a);
    SET(CURLOPT_PROTOCOLS_STR, "http,https");
    SET(CURLOPT_REDIR_PROTOCOLS_STR, "http,https");
    SET(CURLOPT_PRIVATE, j);
    SET(CURLOPT_NOSIGNAL, 1L);
    SET(CURLOPT_SSL_VERIFYPEER, 1L);
    SET(CURLOPT_SSL_VERIFYHOST, 2L);
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
    }
    if (j->params)
        for (size_t i = 0; i < j->params->count; i++) {
            const char *key = j->params->keys[i];
            ar_value *v = j->params->items[i];
            if (!key)
                continue;
            if (!strcmp(key, "method")) {
                if (!strcmp(v->string, "HEAD")) SET(CURLOPT_NOBODY, 1L);
                else if (!strcmp(v->string, "POST")) SET(CURLOPT_POST, 1L);
                else if (strcmp(v->string, "GET") || j->b) SET(CURLOPT_CUSTOMREQUEST, v->string);
            } else if (!strcmp(key, "connectTimeoutMs")) {
                SET(CURLOPT_CONNECTTIMEOUT_MS, (long)v->integer);
            } else if (!strcmp(key, "proxy")) {
                SET(CURLOPT_PROXY, v->string);
                SET(CURLOPT_NOPROXY, "");
            } else if (!strcmp(key, "caFile")) {
                SET(CURLOPT_CAINFO, v->string);
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
    if (j->b) {
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
    snprintf(j->error, sizeof(j->error), "libcurl initialization failed");
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
        else if (msg->data.result == CURLE_OK) {
            j->result = ar_value_new(e, V_ARRAY);
            ar_value *status = ar_value_new(e, V_INT);
            if (status)
                status->integer = j->status;
            if (!j->result) {
                ar_value_free(status);
                snprintf(j->error, sizeof(j->error), "Native memory limit or allocation failure");
            } else if (ar_add(j->result, "status", status) ||
                       ar_add(j->result, "body", take_http_buffer(e, &j->body, &j->body_len, &j->body_capacity)) ||
                       ar_add(j->result, "headers",
                              take_http_buffer(e, &j->response_headers, &j->headers_len, &j->headers_capacity)))
                snprintf(j->error, sizeof(j->error), "Native memory limit or allocation failure");
        }
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
static int wait_fds(ar_engine *e, struct pollfd *fds, size_t n, int64_t deadline) {
#ifdef __linux__
    if (e->use_epoll) {
        int ep = epoll_create1(EPOLL_CLOEXEC);
        if (ep < 0)
            return -1;
        struct epoll_event *events = calloc(n, sizeof(*events));
        if (!events) {
            close(ep);
            errno = ENOMEM;
            return -1;
        }
        for (size_t i = 0; i < n; i++) {
            struct epoll_event ev = {.events = (fds[i].events & POLLIN ? EPOLLIN : 0) |
                                               (fds[i].events & POLLOUT ? EPOLLOUT : 0),
                                     .data.u64 = i};
            if (epoll_ctl(ep, EPOLL_CTL_ADD, fds[i].fd, &ev)) {
                int saved = errno;
                free(events);
                close(ep);
                errno = saved;
                return -1;
            }
        }
        int timeout = remaining_ms(deadline);
        ar_trace_emit(e, AR_TRACE_WAIT, 11, e->wait_id, timeout, n, 1);
        uint64_t began = ar_trace_enabled(e, AR_TRACE_WAIT) ? ar_trace_clock() : 0;
        int rc = timeout == -2 ? -1 : epoll_wait(ep, events, (int)n, timeout);
        ar_trace_emit(e, AR_TRACE_WAIT, 12, e->wait_id, rc, rc < 0 ? errno : 0, trace_elapsed(began));
        if (rc >= 0)
            for (int i = 0; i < rc; i++) {
                uint32_t ev = events[i].events;
                fds[events[i].data.u64].revents =
                    (ev & EPOLLIN ? POLLIN : 0) | (ev & EPOLLOUT ? POLLOUT : 0) |
                    (ev & EPOLLHUP ? POLLHUP : 0) | (ev & EPOLLERR ? POLLERR : 0);
            }
        int saved = errno;
        free(events);
        close(ep);
        errno = saved;
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
    size_t ns = 0;
    for (ar_socket *s = e->sockets; s; s = s->next)
        ns++;
    if (n + ns + 1 > AR_MAX_WATCHERS) {
        errno = EOVERFLOW;
        return -1;
    }
    struct pollfd *fds = calloc(n + ns + 1, sizeof(*fds));
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
        int events = s->events;
        /* curl 8.5 can retain its read interest while a write callback is
         * paused. A readable peer would then wake epoll forever. Streaming
         * responses force HTTP/1.1, so their active socket is not multiplexed.
         * Do not watch a fully paused socket: HUP also wakes a zero-event poll.
         * Completion/cancellation deadlines and worker wakeups still apply. */
        for (ar_job *job = e->jobs; job; job = job->next) {
            if (job->op != OP_HTTP || job->done) continue;
            if (job->curl == s->owner) {
                if (job->receive_paused) events &= ~POLLIN;
                if (job->send_paused) events &= ~POLLOUT;
            }
        }
        if (!events) continue;
        fds[count].fd = s->fd;
        fds[count++].events = events;
    }
    int rc = wait_fds(e, fds, count, deadline);
    if (rc >= 0) {
        for (size_t i = 0; i < n; i++)
            w[i].ready = w[i].buffered ||
                         (fds[indices[i]].revents & (w[i].events | POLLERR | POLLHUP | POLLNVAL));
        for (size_t i = 0; i < n; i++) if (w[i].ready)
            ar_trace_emit(e, AR_TRACE_IO, 52, w[i].key, w[i].fd, fds[indices[i]].revents, w[i].buffered);
        if (e->multi) {
            for (size_t i = curl_start; i < count; i++)
                if (fds[i].revents) {
                    int action = (fds[i].revents & POLLIN ? CURL_CSELECT_IN : 0) |
                                 (fds[i].revents & POLLOUT ? CURL_CSELECT_OUT : 0) |
                                 (fds[i].revents & (POLLERR | POLLHUP) ? CURL_CSELECT_ERR : 0);
                    http_action(e, fds[i].fd, action);
                }
            http_action(e, CURL_SOCKET_TIMEOUT, 0);
            ar_http_progress(e);
        }
    }
    int saved = errno;
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
