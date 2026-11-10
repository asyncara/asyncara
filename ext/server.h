#ifndef ASYNCARA_SERVER_H
#define ASYNCARA_SERVER_H
/* Borrowed views remain valid until the next mutating server call. Zend copies
 * them after Rust returns, then acknowledges consumption. No Zend across Rust. */
typedef struct {
    uint64_t id, connection; int kind;
    const unsigned char *method; size_t method_len;
    const unsigned char *target; size_t target_len;
    const unsigned char *headers; size_t headers_len;
    const unsigned char *body; size_t body_len;
} ar_server_view;
typedef struct { const unsigned char *name; size_t name_len; const unsigned char *value; size_t value_len; } ar_server_header;
typedef struct {
    int notification_fd;
    size_t active, queued, pending, bytes;
    uint64_t accepted, closed, timed_out, errors;
    const unsigned char *address; size_t address_len;
} ar_server_status;
void *ar_server_new(const unsigned char *, size_t, size_t, size_t, size_t, size_t, uint64_t, size_t, int);
void ar_server_free(void *);
int ar_server_drive(void *);
int ar_server_timeout(void *);
size_t ar_server_fds(void *, struct pollfd *, size_t);
int ar_server_ready(void *, int, int);
int ar_server_peek(void *, ar_server_view *);
int ar_server_pop(void *);
int ar_server_respond(void *, uint64_t, uint16_t, const ar_server_header *, size_t, const unsigned char *, size_t);
int ar_server_info(void *, ar_server_status *);
int ar_server_shutdown(void *);
#endif
