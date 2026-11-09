#include "native.h"
#include <arpa/inet.h>
#include <assert.h>
#include <dirent.h>
#include <errno.h>
#include <fcntl.h>
#include <netdb.h>
#include <signal.h>
#include <stdbool.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>
#include <sys/random.h>
#include <unistd.h>

ar_value *ar_value_new(ar_engine *e, ar_type type) {
    ar_value *v = ar_calloc(e, 1, sizeof(*v));
    if (v) {
        v->type = type;
        v->engine = e;
    }
    return v;
}
ar_value *ar_string(ar_engine *e, const char *p, size_t n) {
    if (n > AR_LIMIT)
        return NULL;
    ar_value *v = ar_value_new(e, V_STRING);
    if (!v)
        return NULL;
    v->string = ar_alloc(e, n + 1);
    if (!v->string) {
        ar_free(v);
        return NULL;
    }
    if (n)
        memcpy(v->string, p, n);
    v->string[n] = 0;
    v->length = n;
    return v;
}
int ar_add(ar_value *v, const char *key, ar_value *value) {
    if (!value || v->count >= 100000) {
        ar_value_free(value);
        return -1;
    }
    size_t n = v->count + 1;
    ar_value **items = ar_realloc(v->engine, v->items, n * sizeof(*items));
    if (!items) {
        ar_value_free(value);
        return -1;
    }
    v->items = items;
    char **keys = ar_realloc(v->engine, v->keys, n * sizeof(*keys));
    if (!keys) {
        ar_value_free(value);
        return -1;
    }
    v->keys = keys;
    keys[n - 1] = key ? ar_strdup(v->engine, key) : NULL;
    if (key && !keys[n - 1]) {
        ar_value_free(value);
        return -1;
    }
    items[n - 1] = value;
    v->count = n;
    return 0;
}
void ar_value_free(ar_value *v) {
    if (!v)
        return;
    for (size_t i = 0; i < v->count; i++) {
        ar_value_free(v->items[i]);
        ar_free(v->keys[i]);
    }
    ar_free(v->items);
    ar_free(v->keys);
    ar_free(v->string);
    ar_free(v);
}
static ar_value *integer(ar_engine *e, int64_t n) {
    ar_value *v = ar_value_new(e, V_INT);
    if (v)
        v->integer = n;
    return v;
}
static void failure(ar_job *j, const char *s) {
    snprintf(j->error, sizeof(j->error), "%s", s);
}
static void system_failure(ar_job *j) {
    int code = errno;
    j->system_errno = code;
    failure(j, strerror(code));
}
static int stopped(ar_job *j) {
    if (atomic_load(&j->cancelled)) {
        failure(j, "Operation cancelled");
        return 1;
    }
    return 0;
}
static int sqlite_progress(void *ctx) {
    return atomic_load(&((ar_job *)ctx)->cancelled);
}
static int write_all(ar_job *j, int fd, const char *p, size_t n) {
    size_t off = 0;
    while (off < n) {
        if (stopped(j))
            return -1;
        ssize_t k = write(fd, p + off, n - off);
        if (k < 0 && errno == EINTR)
            continue;
        if (k <= 0) {
            system_failure(j);
            return -1;
        }
        off += (size_t)k;
    }
    return 0;
}
/* Staged streams retain the directory descriptor through rename/cleanup.
 * No path reopening and no cross-filesystem fallback during publication. */
static int close_file(ar_file *file) {
    int error = 0;
    if (file->fd >= 0 && close(file->fd)) error = errno;
    file->fd = -1;
    if (file->staging && file->directory_fd >= 0) {
        if (file->stage_name[0] && unlinkat(file->directory_fd, file->stage_name, 0) && !error) error = errno;
        file->stage_name[0] = 0;
        if (close(file->directory_fd) && !error) error = errno;
        file->directory_fd = -1;
    }
    if (error) { errno = error; return -1; }
    return 0;
}
static void stage_file(ar_job *j) {
    ar_file *file = j->file;
    file->staging = 1; file->directory_fd = -1;
    char *directory = ar_strdup(j->engine, j->a);
    if (!directory) { failure(j, "Native memory limit or allocation failure"); return; }
    char *slash = strrchr(directory, '/');
    if (!slash || !slash[1] || !strcmp(slash + 1, ".") || !strcmp(slash + 1, "..")) { errno = EINVAL; goto error; }
    file->stage_target = ar_strdup(j->engine, slash + 1);
    if (!file->stage_target) { failure(j, "Native memory limit or allocation failure"); goto finish; }
    if (slash == directory) slash[1] = 0; else *slash = 0;
    file->directory_fd = open(directory, O_RDONLY | O_DIRECTORY | O_CLOEXEC);
    if (file->directory_fd < 0) goto error;
    for (int attempt = 0; attempt < 16; attempt++) {
        unsigned char random[16]; size_t offset = 0;
        while (offset < sizeof(random)) {
            ssize_t n = getrandom(random + offset, sizeof(random) - offset, 0);
            if (n < 0 && errno == EINTR) continue;
            if (n <= 0) { if (!n) errno = EIO; goto error; }
            offset += (size_t)n;
        }
        char name[64] = ".asyncara-";
        for (size_t i = 0; i < sizeof(random); i++) snprintf(name + 9 + 2*i, 3, "%02x", random[i]);
        file->fd = openat(file->directory_fd, name, O_RDWR | O_CREAT | O_EXCL | O_CLOEXEC | O_NOFOLLOW, 0600);
        if (file->fd >= 0) { memcpy(file->stage_name, name, strlen(name)+1); break; }
        if (errno != EEXIST) goto error;
    }
    if (file->fd < 0) goto error;
    j->result = integer(j->engine, file->id);
    if (!j->result) { failure(j, "Native memory limit or allocation failure"); goto finish; }
    ar_free(directory); return;
error:
    system_failure(j);
finish:
    if (close_file(file) && !j->error[0]) system_failure(j);
    ar_free(directory);
}
static void do_handle(ar_job *j) {
    ar_file *file = j->file;
    struct stat st;
    if (j->op == OP_FILE_STAGE) { stage_file(j); return; }
    if (j->op == OP_FILE_OPEN) {
        int flags = O_CLOEXEC | O_NONBLOCK;
        flags |= strchr(j->b, '+') ? O_RDWR : (j->b[0] == 'r' ? O_RDONLY : O_WRONLY);
        if (j->b[0] != 'r')
            flags |= O_CREAT;
        if (j->b[0] == 'a')
            flags |= O_APPEND;
        if (j->b[0] == 'x')
            flags |= O_EXCL;
        file->fd = open(j->a, flags, 0666);
        if (file->fd < 0)
            goto system_error;
        if (fstat(file->fd, &st))
            goto open_error;
        if (!S_ISREG(st.st_mode)) {
            failure(j, "Only regular files are supported");
            goto close_open;
        }
        if (j->b[0] == 'w' && ftruncate(file->fd, 0))
            goto open_error;
        j->result = integer(j->engine, file->id);
        if (j->result)
            return;
        failure(j, "Native memory limit or allocation failure");
        goto close_open;
    open_error:
        system_failure(j);
    close_open:
        close(file->fd);
        file->fd = -1;
        return;
    }
    if (j->op == OP_FILE_READ) {
        char *buffer = ar_alloc(j->engine, j->length);
        if (!buffer) {
            failure(j, "Native memory limit or allocation failure");
            return;
        }
        ssize_t count;
        do {
            count = read(file->fd, buffer, j->length);
        } while (count < 0 && errno == EINTR && !stopped(j));
        if (count < 0)
            system_failure(j);
        else
            j->result = ar_string(j->engine, buffer, (size_t)count);
        ar_free(buffer);
    } else if (j->op == OP_FILE_WRITE) {
        if (!write_all(j, file->fd, j->b, j->b_len))
            j->result = integer(j->engine, (int64_t)j->b_len);
    } else if (j->op == OP_FILE_SEEK) {
        off_t offset = lseek(file->fd, (off_t)j->offset, (int)j->length);
        if (offset < 0)
            goto system_error;
        j->result = integer(j->engine, offset);
    } else if (j->op == OP_FILE_STAT) {
        if (fstat(file->fd, &st))
            goto system_error;
        j->result = ar_value_new(j->engine, V_ARRAY);
        if (!j->result || ar_add(j->result, "size", integer(j->engine, st.st_size)) ||
            ar_add(j->result, "mode", integer(j->engine, st.st_mode)) ||
            ar_add(j->result, "mtime", integer(j->engine, st.st_mtime)))
            failure(j, "Native memory limit or allocation failure");
    } else if (j->op == OP_FILE_SYNC) {
        if (fsync(file->fd))
            goto system_error;
        j->result = ar_value_new(j->engine, V_NULL);
    } else if (j->op == OP_FILE_PUBLISH) {
        if (!file->staging || !file->stage_name[0] || file->directory_fd < 0) { errno = EINVAL; goto system_error; }
        j->result = ar_value_new(j->engine, V_NULL);
        if (!j->result) { failure(j, "Native memory limit or allocation failure"); return; }
        if (j->offset && fsync(file->fd)) goto system_error;
        if (stopped(j)) return;
        if (renameat(file->directory_fd, file->stage_name, file->directory_fd, file->stage_target)) goto system_error;
        file->stage_name[0] = 0; j->committed = 1;
        if (j->offset && fsync(file->directory_fd)) goto system_error;
    } else {
        if (close_file(file)) goto system_error;
        j->result = ar_value_new(j->engine, V_NULL);
    }
    return;
system_error:
    system_failure(j);
}
/* Pin one directory for creation, rename and optional directory fsync. There
 * is no cross-filesystem copy fallback. Publish only fully written contents. */
static void do_atomic_write(ar_job *j) {
    char *directory = ar_strdup(j->engine, j->a);
    int dir = -1, fd = -1;
    bool temporary = false;
    char name[64] = {0};
    if (!directory) {
        failure(j, "Native memory limit or allocation failure");
        return;
    }
    const char *base = strrchr(j->a, '/');
    char *slash = strrchr(directory, '/');
    if (!base || !base[1] || !strcmp(base + 1, ".") || !strcmp(base + 1, "..")) {
        errno = EINVAL;
        goto error;
    }
    base++;
    if (slash == directory) slash[1] = 0; else *slash = 0;
    dir = open(directory, O_RDONLY | O_DIRECTORY | O_CLOEXEC);
    if (dir < 0)
        goto error;
    j->result = integer(j->engine, (int64_t)j->b_len);
    if (!j->result) {
        failure(j, "Native memory limit or allocation failure");
        goto finish;
    }
    for (int attempt = 0; attempt < 16; attempt++) {
        unsigned char random[16];
        size_t offset = 0;
        while (offset < sizeof(random)) {
            ssize_t count = getrandom(random + offset, sizeof(random) - offset, 0);
            if (count < 0 && errno == EINTR) continue;
            if (count <= 0) { if (!count) errno = EIO; goto error; }
            offset += (size_t)count;
        }
        memcpy(name, ".asyncara-", 9);
        for (size_t i = 0; i < sizeof(random); i++)
            snprintf(name + 9 + 2 * i, 3, "%02x", random[i]);
        fd = openat(dir, name, O_WRONLY | O_CREAT | O_EXCL | O_CLOEXEC | O_NOFOLLOW, 0600);
        if (fd >= 0) { temporary = true; break; }
        if (errno != EEXIST) goto error;
    }
    if (fd < 0) goto error;
    if (write_all(j, fd, j->b, j->b_len)) goto finish;
    if (fchmod(fd, (mode_t)j->length) || (j->offset && fsync(fd))) goto error;
    if (close(fd)) { fd = -1; goto error; }
    fd = -1;
    if (stopped(j)) goto finish;
    if (renameat(dir, name, dir, base)) goto error;
    temporary = false;
    j->committed = 1;
    if (j->offset && fsync(dir)) goto error;
    goto finish;
error:
    system_failure(j);
finish:
    if (fd >= 0 && close(fd) && !j->error[0]) system_failure(j);
    if (temporary && unlinkat(dir, name, 0)) {
        int code = errno;
        if (!j->error[0]) { errno = code; system_failure(j); }
        size_t length = strlen(j->error);
        snprintf(j->error + length, sizeof(j->error) - length,
                 "; temporary %s cleanup failed: %s", name, strerror(code));
    }
    if (dir >= 0 && close(dir) && !j->error[0]) system_failure(j);
    ar_free(directory);
}
static void do_file(ar_job *j) {
    if (j->op == OP_ATOMIC_WRITE) {
        do_atomic_write(j);
        return;
    }
    int fd = -1, out = -1;
    struct stat st;
    if (j->op == OP_READ || j->op == OP_READ_RANGE || j->op == OP_COPY) {
        fd = open(j->a, O_RDONLY | O_CLOEXEC | O_NONBLOCK);
        if (fd < 0)
            goto system_error;
        if (fstat(fd, &st))
            goto system_error;
        if (!S_ISREG(st.st_mode)) {
            failure(j, "Only regular files are supported");
            goto finish;
        }
        if (j->op == OP_COPY) {
            struct stat dest;
            if (!stat(j->b, &dest) && dest.st_dev == st.st_dev && dest.st_ino == st.st_ino) {
                failure(j, "Source and destination are the same file");
                goto finish;
            }
            out = open(j->b, O_WRONLY | O_CREAT | O_CLOEXEC | O_NONBLOCK, 0666);
            if (out < 0)
                goto system_error;
            if (fstat(out, &dest))
                goto system_error;
            if (dest.st_dev == st.st_dev && dest.st_ino == st.st_ino) {
                failure(j, "Source and destination are the same file");
                goto finish;
            }
            if (ftruncate(out, 0))
                goto system_error;
            if (!S_ISREG(dest.st_mode)) {
                failure(j, "Only regular files are supported");
                goto finish;
            }
            char buf[65536];
            ssize_t n;
            int64_t count = 0;
            while ((n = read(fd, buf, sizeof(buf))) != 0) {
                if (n < 0) {
                    if (errno == EINTR)
                        continue;
                    goto system_error;
                }
                if (write_all(j, out, buf, (size_t)n))
                    goto finish;
                count += n;
            }
            j->result = integer(j->engine, count);
            goto finish;
        }
        size_t limit = j->op == OP_READ_RANGE ? j->length : AR_LIMIT;
        if (j->op == OP_READ_RANGE && lseek(fd, j->offset, SEEK_SET) < 0)
            goto system_error;
        if (j->op == OP_READ && (st.st_size < 0 || (uint64_t)st.st_size > limit)) {
            failure(j, "File exceeds buffer limit; use read stream");
            goto finish;
        }
        size_t cap = limit;
        if (j->op == OP_READ) {
            cap = (size_t)st.st_size + 1;
            if (cap < 4096)
                cap = 4096;
            if (cap > limit)
                cap = limit;
        }
        char *buf = ar_alloc(j->engine, cap + 1);
        if (!buf) {
            failure(j, "Native memory limit or allocation failure");
            goto finish;
        }
        size_t n = 0;
        while (n < limit && !stopped(j)) {
            if (n == cap) {
                size_t next_cap = cap > limit / 2 ? limit : cap * 2;
                char *next = ar_realloc(j->engine, buf, next_cap + 1);
                if (!next) {
                    failure(j, "Native memory limit or allocation failure");
                    break;
                }
                buf = next;
                cap = next_cap;
            }
            ssize_t k = read(fd, buf + n, cap - n);
            if (k < 0 && errno == EINTR)
                continue;
            if (k < 0) {
                system_failure(j);
                break;
            }
            if (!k)
                break;
            n += (size_t)k;
        }
        if (j->op == OP_READ && n == limit && !j->error[0]) {
            char extra;
            ssize_t k = read(fd, &extra, 1);
            if (k > 0)
                failure(j, "File exceeds buffer limit; use read stream");
            if (k < 0)
                system_failure(j);
        }
        if (!j->error[0]) {
            /* This buffer already has native ownership and a spare terminator.
             * Transfer it to the result instead of allocating/copying it again. */
            j->result = ar_value_new(j->engine, V_STRING);
            if (j->result) {
                buf[n] = 0;
                j->result->string = buf;
                j->result->length = n;
                buf = NULL;
            }
        }
        ar_free(buf);
    } else if (j->op == OP_WRITE || j->op == OP_APPEND || j->op == OP_WRITE_RANGE) {
        int flags = O_WRONLY | O_CREAT | O_CLOEXEC | O_NONBLOCK;
        if (j->op == OP_WRITE)
            flags |= O_TRUNC;
        if (j->op == OP_APPEND)
            flags |= O_APPEND;
        fd = open(j->a, flags, 0666);
        if (fd < 0)
            goto system_error;
        if (fstat(fd, &st))
            goto system_error;
        if (!S_ISREG(st.st_mode)) {
            failure(j, "Only regular files are supported");
            goto finish;
        }
        if (j->op == OP_WRITE_RANGE && lseek(fd, j->offset, SEEK_SET) < 0)
            goto system_error;
        if (!write_all(j, fd, j->b, j->b_len))
            j->result = integer(j->engine, (int64_t)j->b_len);
    } else if (j->op == OP_STAT) {
        if (stat(j->a, &st))
            goto system_error;
        j->result = ar_value_new(j->engine, V_ARRAY);
        if (!j->result || ar_add(j->result, "size", integer(j->engine, st.st_size)) ||
            ar_add(j->result, "mode", integer(j->engine, st.st_mode)) ||
            ar_add(j->result, "mtime", integer(j->engine, st.st_mtime)))
            failure(j, "Native memory limit or allocation failure");
    } else if (j->op == OP_LIST) {
        DIR *dir = opendir(j->a);
        if (!dir)
            goto system_error;
        j->result = ar_value_new(j->engine, V_ARRAY);
        struct dirent *entry;
        if (!j->result) {
            closedir(dir);
            failure(j, "Native memory limit or allocation failure");
            goto finish;
        }
        errno = 0;
        while (!stopped(j) && (entry = readdir(dir))) {
            if (!strcmp(entry->d_name, ".") || !strcmp(entry->d_name, ".."))
                continue;
            if (ar_add(j->result, NULL, ar_string(j->engine, entry->d_name, strlen(entry->d_name)))) {
                failure(j, "Directory entry limit or allocation failure");
                break;
            }
        }
        if (errno)
            system_failure(j);
        closedir(dir);
    } else {
        int rc = j->op == OP_UNLINK   ? unlink(j->a)
                 : j->op == OP_RENAME ? rename(j->a, j->b)
                 : j->op == OP_MKDIR  ? mkdir(j->a, 0777)
                                      : rmdir(j->a);
        if (rc)
            goto system_error;
        j->result = ar_value_new(j->engine, V_BOOL);
        if (j->result)
            j->result->integer = 1;
    }
    goto finish;
system_error:
    system_failure(j);
finish:
    if (fd >= 0 && close(fd) && !j->error[0])
        system_failure(j);
    if (out >= 0 && close(out) && !j->error[0])
        system_failure(j);
}
static void do_dns(ar_job *j) {
    struct addrinfo hints = {0}, *result = NULL;
    hints.ai_socktype = SOCK_STREAM;
    hints.ai_family = AF_UNSPEC;
    int rc = getaddrinfo(j->a, NULL, &hints, &result);
    if (rc) {
        failure(j, gai_strerror(rc));
        return;
    }
    j->result = ar_value_new(j->engine, V_ARRAY);
    if (!j->result) {
        freeaddrinfo(result);
        return;
    }
    for (struct addrinfo *p = result; p; p = p->ai_next) {
        char buf[INET6_ADDRSTRLEN];
        void *addr;
        if (p->ai_family == AF_INET)
            addr = &((struct sockaddr_in *)p->ai_addr)->sin_addr;
        else if (p->ai_family == AF_INET6)
            addr = &((struct sockaddr_in6 *)p->ai_addr)->sin6_addr;
        else
            continue;
        if (inet_ntop(p->ai_family, addr, buf, sizeof(buf)) &&
            ar_add(j->result, NULL, ar_string(j->engine, buf, strlen(buf)))) {
            failure(j, "DNS result limit");
            break;
        }
    }
    freeaddrinfo(result);
}
static void do_sqlite(ar_job *j) {
    ar_db *db = j->db;
    if (j->op == OP_DB_OPEN) {
        int rc = sqlite3_open_v2(j->a, &db->handle,
                                 SQLITE_OPEN_READWRITE | SQLITE_OPEN_CREATE | SQLITE_OPEN_FULLMUTEX, NULL);
        if (rc != SQLITE_OK) {
            failure(j, db->handle ? sqlite3_errmsg(db->handle) : "SQLite open failed");
            if (db->handle)
                sqlite3_close(db->handle);
            db->handle = NULL;
            return;
        }
        /* Restrict foreign allocations before constructing values, not merely
         * when copying the result into Asyncara-owned buffers. */
        sqlite3_limit(db->handle, SQLITE_LIMIT_LENGTH, AR_LIMIT);
        sqlite3_limit(db->handle, SQLITE_LIMIT_SQL_LENGTH, AR_LIMIT);
        sqlite3_limit(db->handle, SQLITE_LIMIT_VARIABLE_NUMBER, 10000);
        sqlite3_busy_timeout(db->handle, 1000);
        j->result = integer(j->engine, db->id);
        if (!j->result) {
            sqlite3_close(db->handle);
            db->handle = NULL;
        }
        return;
    }
    if (j->op == OP_DB_CLOSE) {
        int rc = sqlite3_close(db->handle);
        if (rc != SQLITE_OK)
            failure(j, sqlite3_errstr(rc));
        else {
            db->handle = NULL;
            j->result = ar_value_new(j->engine, V_NULL);
        }
        return;
    }
    sqlite3_stmt *stmt = NULL;
    const char *tail;
    sqlite3_progress_handler(db->handle, 1000, sqlite_progress, j);
    int rc = sqlite3_prepare_v2(db->handle, j->a, -1, &stmt, &tail);
    if (rc != SQLITE_OK)
        goto error;
    while (*tail == ' ' || *tail == '\n' || *tail == '\r' || *tail == '\t')
        tail++;
    if (!stmt || *tail) {
        failure(j, "Exactly one SQL statement is required");
        goto finish;
    }
    size_t count = j->params ? j->params->count : 0;
    if ((size_t)sqlite3_bind_parameter_count(stmt) != count) {
        failure(j, "SQL parameter count mismatch");
        goto finish;
    }
    for (size_t i = 0; i < count; i++) {
        ar_value *v = j->params->items[i];
        int pos = (int)i + 1;
        switch (v->type) {
        case V_NULL:
            rc = sqlite3_bind_null(stmt, pos);
            break;
        case V_BOOL:
        case V_INT:
            rc = sqlite3_bind_int64(stmt, pos, v->integer);
            break;
        case V_DOUBLE:
            rc = sqlite3_bind_double(stmt, pos, v->number);
            break;
        case V_STRING:
            rc = sqlite3_bind_text64(stmt, pos, v->string, v->length, SQLITE_STATIC, SQLITE_UTF8);
            break;
        default:
            rc = SQLITE_MISMATCH;
        }
        if (rc != SQLITE_OK)
            goto error;
    }
    j->result = ar_value_new(j->engine, V_ARRAY);
    if (!j->result)
        goto finish;
    size_t bytes = 0;
    while ((rc = sqlite3_step(stmt)) == SQLITE_ROW) {
        ar_value *row = ar_value_new(j->engine, V_ARRAY);
        if (!row) {
            failure(j, "Native memory limit or allocation failure");
            goto finish;
        }
        for (int i = 0; i < sqlite3_column_count(stmt); i++) {
            /* SQLite may allocate both column names and converted values. A
             * NULL result can mean OOM, not just an empty SQL value. Check the
             * connection error immediately, before another SQLite call can
             * replace it; retain pointers only until this statement is reset. */
            const char *name = sqlite3_column_name(stmt, i);
            if (!name) {
                ar_value_free(row);
                failure(j, "SQLite column name allocation failure");
                goto finish;
            }
            ar_value *v = NULL;
            int type = sqlite3_column_type(stmt, i);
            switch (type) {
            case SQLITE_NULL:
                v = ar_value_new(j->engine, V_NULL);
                break;
            case SQLITE_INTEGER:
                v = integer(j->engine, sqlite3_column_int64(stmt, i));
                break;
            case SQLITE_FLOAT:
                v = ar_value_new(j->engine, V_DOUBLE);
                if (v)
                    v->number = sqlite3_column_double(stmt, i);
                break;
            default: {
                /* Force TEXT to UTF-8 before obtaining its byte count; the
                 * BLOB accessor preserves a database's UTF-16 representation.
                 * BLOB values must retain their original binary bytes. */
                const void *data = type == SQLITE_TEXT ? (const void *)sqlite3_column_text(stmt, i)
                                                       : sqlite3_column_blob(stmt, i);
                if (!data && sqlite3_errcode(db->handle) == SQLITE_NOMEM) {
                    ar_value_free(row);
                    failure(j, "SQLite value conversion allocation failure");
                    goto finish;
                }
                size_t len = (size_t)sqlite3_column_bytes(stmt, i);
                if ((!len && sqlite3_errcode(db->handle) == SQLITE_NOMEM) || (!data && len)) {
                    ar_value_free(row);
                    failure(j, "SQLite value conversion allocation failure");
                    goto finish;
                }
                bytes += len;
                if (bytes <= AR_LIMIT)
                    v = ar_string(j->engine, data, len);
            }
            }
            bytes += sizeof(ar_value) + strlen(name) + 1;
            if (bytes > AR_LIMIT || ar_add(row, name, v)) {
                if (bytes > AR_LIMIT)
                    ar_value_free(v);
                ar_value_free(row);
                failure(j, "SQLite result limit or allocation failure");
                goto finish;
            }
        }
        if (ar_add(j->result, NULL, row)) {
            failure(j, "SQLite row limit");
            goto finish;
        }
    }
    if (rc != SQLITE_DONE)
        goto error;
    goto finish;
error:
    failure(j, sqlite3_errmsg(db->handle));
finish:
    if (stmt)
        sqlite3_finalize(stmt);
    sqlite3_progress_handler(db->handle, 0, NULL, NULL);
}
void ar_done(ar_engine *e, ar_job *j) {
    long id = j->id;
    int local_completion = j->op == OP_HTTP;
    ar_trace_emit(e, AR_TRACE_JOB, 22, id, j->op, j->error[0] != 0, atomic_load(&j->cancelled));
    pthread_mutex_lock(&e->mutex);
    j->done = 1;
    j->done_next = NULL;
    if (e->done_tail)
        e->done_tail->done_next = j;
    else
        e->done_head = j;
    e->done_tail = j;
    pthread_mutex_unlock(&e->mutex);
    /* HTTP completion is published by the interpreter thread itself, before
     * its done-queue check or after its wait. It cannot race that thread into
     * sleep. Only worker publication needs the pipe notification. */
    if (local_completion) return;
    char byte = 1;
    /* Publication can race the interpreter's last done-queue check before
     * entering poll/epoll. An interrupted notification must be retried: the
     * published job alone cannot wake that wait. EAGAIN needs no retry because
     * the nonblocking pipe already contains a readable notification. */
    ssize_t ignored;
    do {
        ignored = write(e->wake[1], &byte, 1);
        ar_trace_emit(e, AR_TRACE_JOB, 23, id, ignored, ignored < 0 ? errno : 0, 0);
    } while (ignored < 0 && errno == EINTR);
    (void)ignored;
}
static void *worker(void *arg) {
    ar_engine *e = arg;
    for (;;) {
        pthread_mutex_lock(&e->mutex);
        while (!e->queue_head && !e->stopping)
            pthread_cond_wait(&e->condition, &e->mutex);
        if (!e->queue_head && e->stopping) {
            pthread_mutex_unlock(&e->mutex);
            break;
        }
        ar_job *j = e->queue_head;
        e->queue_head = j->queue_next;
        if (!e->queue_head)
            e->queue_tail = NULL;
        pthread_mutex_unlock(&e->mutex);
        /* An accepted close owns mandatory cleanup. Cancellation of a waiter
         * or scheduler drain must neither skip the close nor replace its real
         * outcome. The handle stays busy until ar_collect(), as for other jobs.
         * Cancellation before submission still belongs to the PHP Task. */
        ar_trace_emit(e, AR_TRACE_JOB, 21, j->id, j->op, 0, 0);
        bool closing = j->op == OP_FILE_CLOSE || j->op == OP_DB_CLOSE;
        if (closing || !stopped(j)) {
            if ((j->op >= OP_FILE_OPEN && j->op <= OP_FILE_CLOSE) || j->op == OP_FILE_STAGE || j->op == OP_FILE_PUBLISH)
                do_handle(j);
            else if (j->op == OP_DNS)
                do_dns(j);
            else if (j->op >= OP_DB_OPEN && j->op <= OP_DB_CLOSE)
                do_sqlite(j);
            else
                do_file(j);
        }
        if (!closing && atomic_load(&j->cancelled)) {
            /* Atomic replacement must not hide a failed temp cleanup or a
             * durability error behind concurrent cancellation. */
            if ((j->op != OP_ATOMIC_WRITE && j->op != OP_FILE_PUBLISH) || !j->error[0])
                failure(j, "Operation cancelled");
            if ((j->op == OP_FILE_OPEN || j->op == OP_FILE_STAGE) && j->file->fd >= 0) {
                if (close_file(j->file)) system_failure(j);
            }
            if (j->op == OP_DB_OPEN && j->db->handle) {
                sqlite3_close(j->db->handle);
                j->db->handle = NULL;
            }
        }
        if (!j->result && !j->error[0])
            failure(j, "Native memory limit or allocation failure");
        ar_done(e, j);
    }
    return NULL;
}
ar_engine *ar_engine_new(void) {
    ar_engine *e = calloc(1, sizeof(*e));
    if (!e)
        return NULL;
    atomic_init(&e->trace, NULL);
    if (pipe2(e->wake, O_NONBLOCK | O_CLOEXEC)) {
        free(e);
        return NULL;
    }
    if (pthread_mutex_init(&e->mutex, NULL)) {
        close(e->wake[0]);
        close(e->wake[1]);
        free(e);
        return NULL;
    }
    if (pthread_cond_init(&e->condition, NULL)) {
        pthread_mutex_destroy(&e->mutex);
        close(e->wake[0]);
        close(e->wake[1]);
        free(e);
        return NULL;
    }
    atomic_init(&e->memory_bytes, 0);
    atomic_init(&e->memory_peak, 0);
    atomic_init(&e->memory_rejections, 0);
    e->memory_limit = AR_MEMORY_LIMIT;
    e->http_max_connections = 64; e->http_max_host_connections = 8;
    const char *http_backend = getenv("ASYNCARA_HTTP_BACKEND");
    e->hyper_requested = http_backend && !strcmp(http_backend, "hyper-prototype");
    e->use_epoll = 1;
    const char *backend = getenv("ASYNCARA_BACKEND");
    if (backend && !strcmp(backend, "poll"))
        e->use_epoll = 0;
    if (ar_wait_initialize(e)) {
        int saved = errno; ar_engine_free(e); errno = saved; return NULL;
    }
    return e;
}
ar_job *ar_job_new(ar_engine *e, ar_op op) {
    ar_job *j = ar_calloc(e, 1, sizeof(*j));
    if (j) {
        j->op = op;
        j->id = ++e->next_id;
        j->engine = e;
        j->lifetime_next = e->allocations;
        e->allocations = j;
        atomic_init(&j->cancelled, 0);
    }
    return j;
}
void ar_job_free(ar_job *j) {
    if (!j)
        return;
    ar_trace_emit(j->engine, AR_TRACE_JOB, 26, j->id, j->op, 0, 0);
    ar_job **entry = &j->engine->allocations;
    while (*entry && *entry != j)
        entry = &(*entry)->lifetime_next;
    if (*entry)
        *entry = j->lifetime_next;
    curl_mime_free(j->mime);
    ar_free(j->upload_parts);
    if (j->curl) {
        /* Only detached handles reach collection. Reset all callbacks/options
         * before job-owned strings and buffers disappear. Cache is request-local. */
        curl_easy_reset(j->curl);
#if AR_HTTP_EASY_CACHE > 0
        /* A cancelled/failed transfer may retain curl's active receive buffer
         * even after easy_reset (notably curl 8.5). Such a handle rejects a
         * later CURLOPT_BUFFERSIZE. Dispose it; successful detached handles
         * retain the normal cache and multi-owned connection pool. */
        if (!j->error[0] && !j->receive_paused && !j->send_paused && !j->engine->stopping &&
            j->engine->http_idle_count < AR_HTTP_EASY_CACHE && j->engine->http_idle_count < 64)
            j->engine->http_idle[j->engine->http_idle_count++] = j->curl;
        else
#endif
            curl_easy_cleanup(j->curl);
    }
    curl_slist_free_all(j->headers);
    ar_free(j->a);
    ar_free(j->b);
    ar_free(j->body);
    ar_free(j->upload);
    ar_free(j->response_headers);
    ar_value_free(j->params);
    ar_value_free(j->result);
    ar_free(j);
}
ar_db *ar_db_find(ar_engine *e, long id) {
    for (ar_db *d = e->dbs; d; d = d->next)
        if (d->id == id)
            return d;
    return NULL;
}
ar_file *ar_file_find(ar_engine *e, long id) {
    for (ar_file *file = e->files; file; file = file->next)
        if (file->id == id)
            return file;
    return NULL;
}
int ar_submit(ar_engine *e, ar_job *j) {
    if (e->pending >= AR_MAX_PENDING)
        return -1;
    if (j->op != OP_HTTP && !e->thread_count) {
        /* Workers must never execute request/SAPI signal handlers. Block async
         * signals before creation so there is no unmasked startup window, then
         * restore exactly the interpreter thread's previous mask. Synchronous
         * fault signals remain available to crash/sanitizer handlers. */
        sigset_t blocked, previous;
        sigfillset(&blocked);
        const int faults[] = {SIGABRT, SIGBUS, SIGFPE, SIGILL, SIGSEGV, SIGTRAP, SIGSYS};
        for (size_t i = 0; i < sizeof(faults) / sizeof(*faults); i++) sigdelset(&blocked, faults[i]);
        int mask_error = pthread_sigmask(SIG_BLOCK, &blocked, &previous);
        if (mask_error) { errno = mask_error; return -1; }
        for (int i = 0; i < 4; i++) {
            if (pthread_create(&e->threads[i], NULL, worker, e))
                break;
            e->thread_count++;
        }
        mask_error = pthread_sigmask(SIG_SETMASK, &previous, NULL);
        if (mask_error) { errno = mask_error; return -1; }
        if (!e->thread_count)
            return -1;
    }
    j->next = e->jobs;
    e->jobs = j;
    e->pending++;
    ar_trace_emit(e, AR_TRACE_JOB, 20, j->id, j->op, e->pending, 0);
    if (j->db)
        j->db->busy = 1;
    if (j->file)
        j->file->busy = 1;
    if (j->op == OP_HTTP) {
        ar_http_start(e, j);
        return 0;
    }
    pthread_mutex_lock(&e->mutex);
    if (e->queue_tail)
        e->queue_tail->queue_next = j;
    else
        e->queue_head = j;
    e->queue_tail = j;
    pthread_cond_signal(&e->condition);
    pthread_mutex_unlock(&e->mutex);
    return 0;
}
void ar_cancel(ar_engine *e, long id) {
    for (ar_job *j = e->jobs; j; j = j->next)
        if (j->id == id) {
            ar_trace_emit(e, AR_TRACE_JOB, 25, id, j->op, 0, 0);
            atomic_store(&j->cancelled, 1);
            if (j->op == OP_HTTP && !j->done) {
#ifdef HAVE_ASYNCARA_HYPER
                if (j->http_hyper) {
                    if (ar_hyper_cancel(e->hyper, j)) ar_hyper_failed(e);
                    return;
                }
#endif
                if (j->curl)
                    curl_multi_remove_handle(e->multi, j->curl);
                failure(j, "Operation cancelled");
                ar_done(e, j);
            }
            return;
        }
}
ar_job *ar_collect(ar_engine *e) {
    char buf[1024];
    while (read(e->wake[0], buf, sizeof(buf)) > 0) {
    }
    pthread_mutex_lock(&e->mutex);
    ar_job *head = e->done_head;
    e->done_head = e->done_tail = NULL;
    pthread_mutex_unlock(&e->mutex);
    for (ar_job *j = head; j; j = j->done_next) {
        ar_job **p = &e->jobs;
        while (*p && *p != j)
            p = &(*p)->next;
        if (*p)
            *p = j->next;
        e->pending--;
        ar_trace_emit(e, AR_TRACE_JOB, 24, j->id, j->op, e->pending, 0);
        if (j->file) {
            j->file->busy = 0;
            if (j->file->fd < 0) {
                ar_file **entry = &e->files;
                while (*entry && *entry != j->file)
                    entry = &(*entry)->next;
                if (*entry)
                    *entry = j->file->next;
                ar_free(j->file->stage_target);
                ar_free(j->file);
                j->file = NULL;
            }
        }
        if (j->db) {
            j->db->busy = 0;
            if (!j->db->handle) {
                ar_db **d = &e->dbs;
                while (*d && *d != j->db)
                    d = &(*d)->next;
                if (*d)
                    *d = j->db->next;
                ar_free(j->db);
                j->db = NULL;
            }
        }
    }
    return head;
}
void ar_engine_free(ar_engine *e) {
    if (!e)
        return;
    ar_trace_emit(e, AR_TRACE_LIFE, 70, e->request_id, e->pending, e->thread_count, atomic_load(&e->memory_bytes));
    for (ar_job *j = e->jobs; j; j = j->next)
        atomic_store(&j->cancelled, 1);
    pthread_mutex_lock(&e->mutex);
    e->stopping = 1;
    pthread_cond_broadcast(&e->condition);
    pthread_mutex_unlock(&e->mutex);
    for (int i = 0; i < e->thread_count; i++)
        pthread_join(e->threads[i], NULL);
    ar_wait_cleanup(e);
#ifdef HAVE_ASYNCARA_HYPER
    /* Drop all Rust futures/socket owners before any C job is freed. */
    if (e->hyper) { ar_hyper_free(e->hyper); e->hyper = NULL; }
#endif
    /* Includes collected results and not-yet-submitted jobs: Zend can bail out
     * during input validation or result conversion on PHP memory exhaustion. */
    while (e->allocations) {
        ar_job *j = e->allocations;
        if (j->curl)
            curl_multi_remove_handle(e->multi, j->curl);
        ar_job_free(j);
    }
    while (e->http_idle_count) curl_easy_cleanup(e->http_idle[--e->http_idle_count]);
    if (e->multi)
        curl_multi_cleanup(e->multi);
    while (e->sockets) {
        ar_socket *s = e->sockets;
        e->sockets = s->next;
        ar_free(s);
    }
    while (e->dbs) {
        ar_db *d = e->dbs;
        e->dbs = d->next;
        if (d->handle)
            sqlite3_close(d->handle);
        ar_free(d);
    }
    while (e->files) {
        ar_file *file = e->files;
        e->files = file->next;
        (void)close_file(file);
        ar_free(file->stage_target);
        ar_free(file);
    }
    close(e->wake[0]);
    close(e->wake[1]);
    pthread_cond_destroy(&e->condition);
    pthread_mutex_destroy(&e->mutex);
    assert(atomic_load(&e->memory_bytes) == 0);
    /* No publisher survives the joins. No diagnostic file I/O at shutdown. */
    free(atomic_load_explicit(&e->trace, memory_order_relaxed));
    free(e);
}
