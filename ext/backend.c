#include "native.h"
#include <errno.h>
#include <limits.h>
#include <poll.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#ifdef __linux__
#include <sys/epoll.h>
#endif
static int socket_changed(CURL *easy, curl_socket_t fd, int action, void *ctx, void *socketp) {
    (void)easy;
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
    return 0;
}
static size_t receive_buffer(char *data, size_t size, size_t count, void *ctx, int header) {
    ar_job *j = ctx;
    if (size && count > SIZE_MAX / size)
        return 0;
    size_t n = size * count, *len = header ? &j->headers_len : &j->body_len;
    size_t *capacity = header ? &j->headers_capacity : &j->body_capacity;
    char **buf = header ? &j->response_headers : &j->body;
    size_t limit = header ? 65536 : AR_LIMIT;
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
    return receive_buffer(p, s, n, ctx, 1);
}
void ar_http_start(ar_engine *e, ar_job *j) {
    if (!e->multi) {
        e->multi = curl_multi_init();
        if (!e->multi)
            goto error;
        curl_multi_setopt(e->multi, CURLMOPT_SOCKETFUNCTION, socket_changed);
        curl_multi_setopt(e->multi, CURLMOPT_SOCKETDATA, e);
        curl_multi_setopt(e->multi, CURLMOPT_MAX_TOTAL_CONNECTIONS, 64L);
        curl_multi_setopt(e->multi, CURLMOPT_MAX_HOST_CONNECTIONS, 8L);
    }
    if (!(curl_version_info(CURLVERSION_NOW)->features & CURL_VERSION_ASYNCHDNS)) {
        snprintf(j->error, sizeof(j->error), "libcurl asynchronous DNS required");
        ar_done(e, j);
        return;
    }
    j->curl = curl_easy_init();
    if (!j->curl)
        goto error;
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
    SET(CURLOPT_ACCEPT_ENCODING, "");
    if (j->params)
        for (size_t i = 0; i < j->params->count; i++) {
            const char *key = j->params->keys[i];
            ar_value *v = j->params->items[i];
            if (!key)
                continue;
            if (!strcmp(key, "method")) {
                SET(CURLOPT_CUSTOMREQUEST, v->string);
                if (!strcmp(v->string, "HEAD")) {
                    SET(CURLOPT_NOBODY, 1L);
                }
            } else if (!strcmp(key, "proxy")) {
                SET(CURLOPT_PROXY, v->string);
            } else if (!strcmp(key, "caFile")) {
                SET(CURLOPT_CAINFO, v->string);
            } else if (!strcmp(key, "followRedirects")) {
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
    if (j->headers) {
        SET(CURLOPT_HTTPHEADER, j->headers);
    }
    if (curl_multi_add_handle(e->multi, j->curl) != CURLM_OK)
        goto error;
    curl_multi_socket_action(e->multi, CURL_SOCKET_TIMEOUT, 0, &e->running);
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
                       ar_add(j->result, "body", ar_string(e, j->body ? j->body : "", j->body_len)) ||
                       ar_add(j->result, "headers",
                              ar_string(e, j->response_headers ? j->response_headers : "", j->headers_len)))
                snprintf(j->error, sizeof(j->error), "Native memory limit or allocation failure");
        }
        curl_multi_remove_handle(e->multi, j->curl);
        ar_done(e, j);
    }
}
/* Bounded transient control buffers deliberately bypass the payload budget so
 * an exhausted budget cannot prevent completion draining or cancellation. */
static int wait_fds(ar_engine *e, struct pollfd *fds, size_t n, int timeout) {
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
        int rc = epoll_wait(ep, events, (int)n, timeout);
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
    return poll(fds, n, timeout);
}
int ar_wait(ar_engine *e, ar_watch *w, size_t n, int timeout) {
    ar_http_progress(e);
    pthread_mutex_lock(&e->mutex);
    int done = e->done_head != NULL;
    pthread_mutex_unlock(&e->mutex);
    if (done)
        timeout = 0;
    for (size_t i = 0; i < n; i++)
        if (w[i].buffered)
            timeout = 0;
    long curl_timeout = -1;
    if (e->multi) {
        curl_multi_timeout(e->multi, &curl_timeout);
        if (curl_timeout >= 0 && (timeout < 0 || curl_timeout < timeout))
            timeout = (int)curl_timeout;
    }
    size_t ns = 0;
    for (ar_socket *s = e->sockets; s; s = s->next)
        ns++;
    if (n + ns + 1 > AR_MAX_WATCHERS) {
        errno = EOVERFLOW;
        return -1;
    }
    struct pollfd *fds = calloc(n + ns + 1, sizeof(*fds));
    size_t *indices = calloc(n ? n : 1, sizeof(*indices));
    if (!fds || !indices) {
        free(fds);
        free(indices);
        errno = ENOMEM;
        return -1;
    }
    size_t count = 1;
    fds[0].fd = e->wake[0];
    fds[0].events = POLLIN;
    for (size_t i = 0; i < n; i++) {
        size_t k = 1;
        while (k < count && fds[k].fd != w[i].fd)
            k++;
        if (k == count) {
            fds[k].fd = w[i].fd;
            count++;
        }
        fds[k].events |= w[i].events;
        indices[i] = k;
    }
    size_t curl_start = count;
    for (ar_socket *s = e->sockets; s; s = s->next) {
        fds[count].fd = s->fd;
        fds[count++].events = s->events;
    }
    int rc = wait_fds(e, fds, count, timeout);
    if (rc >= 0) {
        for (size_t i = 0; i < n; i++)
            w[i].ready = w[i].buffered ||
                         (fds[indices[i]].revents & (w[i].events | POLLERR | POLLHUP | POLLNVAL));
        if (e->multi) {
            for (size_t i = curl_start; i < count; i++)
                if (fds[i].revents) {
                    int action = (fds[i].revents & POLLIN ? CURL_CSELECT_IN : 0) |
                                 (fds[i].revents & POLLOUT ? CURL_CSELECT_OUT : 0) |
                                 (fds[i].revents & (POLLERR | POLLHUP) ? CURL_CSELECT_ERR : 0);
                    curl_multi_socket_action(e->multi, fds[i].fd, action, &e->running);
                }
            curl_multi_socket_action(e->multi, CURL_SOCKET_TIMEOUT, 0, &e->running);
            ar_http_progress(e);
        }
    }
    int saved = errno;
    free(fds);
    free(indices);
    errno = saved;
    return rc < 0 && errno == EINTR ? 0 : rc;
}
