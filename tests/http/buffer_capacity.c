/* Exercise the actual receive callbacks, byte integrity and allocation ledger.
 * Capacity is not wire throughput. --compact adds the new storage invariant;
 * the unchanged implementation must still pass the functional checks first. */
#include "../../ext/backend.c"
#include <assert.h>

static unsigned excessive;
static void exact(ar_engine *e, size_t bytes, size_t chunk, int header) {
    ar_job *j = ar_job_new(e, OP_HTTP); assert(j);
    char input[16384]; size_t written = 0;
    while (written < bytes) {
        size_t n = bytes - written; if (n > chunk) n = chunk;
        for (size_t i = 0; i < n; i++) input[i] = (char)((written + i) % 251);
        assert(receive_buffer(input, 1, n, j, header) == n); written += n;
    }
    char *body = header ? j->response_headers : j->body;
    size_t length = header ? j->headers_len : j->body_len;
    size_t capacity = header ? j->headers_capacity : j->body_capacity;
    assert(length == bytes && capacity > length && body[length] == 0);
    for (size_t i = 0; i < bytes; i++) assert((unsigned char)body[i] == i % 251);
    if (bytes >= 4096 && !(bytes & (bytes - 1)) && capacity > bytes + 1) excessive++;
    printf("header=%d bytes=%zu chunk=%zu capacity=%zu\n", header, bytes, chunk, capacity);
    ar_job_free(j); assert(!e->allocations && !atomic_load(&e->memory_bytes));
}
int main(int argc, char **argv) {
    assert(argc == 1 || (argc == 2 && !strcmp(argv[1], "--compact")));
    assert(curl_global_init(CURL_GLOBAL_DEFAULT) == CURLE_OK);
    ar_engine *e = ar_engine_new(); assert(e);
    const size_t sizes[] = {1, 1024, 4095, 4096, 4097, 16384, 32768, 65535, 65536, 65537, 1048576};
    const size_t chunks[] = {1, 4096, 16384};
    for (size_t i = 0; i < sizeof(sizes)/sizeof(*sizes); i++)
        for (size_t c = 0; c < sizeof(chunks)/sizeof(*chunks); c++) {
            /* Large byte-at-a-time input adds no new boundary coverage. */
            if (sizes[i] > 65537 && chunks[c] == 1) continue;
            exact(e, sizes[i], chunks[c], 0);
            if (sizes[i] <= 65536) exact(e, sizes[i], chunks[c], 1);
        }
    ar_job *j = ar_job_new(e, OP_HTTP); assert(j);
    char data[16384]; memset(data, 'x', sizeof(data));
    for (size_t n = 0; n < AR_LIMIT; n += sizeof(data)) assert(receive_body(data, 1, sizeof(data), j) == sizeof(data));
    assert(j->body_len == AR_LIMIT && j->body_capacity == AR_LIMIT + 1);
    char *saved = j->body; size_t reserved = atomic_load(&e->memory_bytes);
    assert(receive_body(data, 1, 1, j) == 0 && strstr(j->error, "limit"));
    assert(j->body == saved && j->body_len == AR_LIMIT && atomic_load(&e->memory_bytes) == reserved);
    ar_job_free(j);
    j = ar_job_new(e, OP_HTTP); assert(j); j->stream_response = 1;
    for (int i = 0; i < 4; i++) assert(receive_body(data, 1, sizeof(data), j) == sizeof(data));
    reserved = atomic_load(&e->memory_bytes); saved = j->body;
    assert(j->body_len == AR_HTTP_STREAM_LIMIT && j->body_capacity == AR_HTTP_STREAM_LIMIT + 1);
    assert(receive_body(data, 1, 1, j) == CURL_WRITEFUNC_PAUSE && j->receive_paused);
    assert(j->body == saved && atomic_load(&e->memory_bytes) == reserved);
    j->body_len = 0; j->receive_paused = 0;
    assert(receive_body(data, 1, 1, j) == 1 && j->body == saved && j->body[1] == 0);
    atomic_store(&j->cancelled, 1);
    assert(receive_body(data, 1, 1, j) == 0 && j->body_len == 1);
    ar_job_free(j);
    /* Four nearly complete 512KiB bodies already fit a 3MiB aggregate ledger.
     * Their last payload byte should not require another full capacity tier.
     * Keep every owner alive until all four callbacks have been attempted. */
    ar_job *batch[4]; unsigned bounded_completions = 0;
    e->memory_limit = 3 * 1024 * 1024;
    for (int i = 0; i < 4; i++) { batch[i] = ar_job_new(e, OP_HTTP); assert(batch[i]); }
    for (int part = 0; part < 32; part++) for (int i = 0; i < 4; i++) {
        size_t amount = sizeof(data) - (part == 31);
        assert(receive_body(data, 1, amount, batch[i]) == amount);
    }
    for (int i = 0; i < 4; i++) {
        if (receive_body(data, 1, 1, batch[i]) == 1) bounded_completions++;
        else assert(strstr(batch[i]->error, "Native memory"));
        assert(batch[i]->body[batch[i]->body_len] == 0);
        for (size_t n = 0; n < batch[i]->body_len; n++) assert(batch[i]->body[n] == 'x');
    }
    printf("boundedCompletions=%u/4 trackedBytes=%zu limit=%zu\n", bounded_completions,
        atomic_load(&e->memory_bytes), e->memory_limit);
    for (int i = 0; i < 4; i++) ar_job_free(batch[i]);
    assert(!atomic_load(&e->memory_bytes)); e->memory_limit = AR_MEMORY_LIMIT;
    j = ar_job_new(e, OP_HTTP); assert(j);
    assert(receive_body(data, 1, sizeof(data), j) == sizeof(data));
    e->memory_limit = atomic_load(&e->memory_bytes); reserved = e->memory_limit;
    saved = j->body; size_t length = j->body_len;
    /* Force growth past both the old and proposed capacities. */
    assert(receive_body(data, SIZE_MAX, 2, j) == 0);
    assert(receive_body(data, 1, AR_LIMIT, j) == 0); /* reject before input access */
    while (j->body_len + sizeof(data) + 1 <= j->body_capacity)
        assert(receive_body(data, 1, sizeof(data), j) == sizeof(data));
    length = j->body_len;
    assert(receive_body(data, 1, sizeof(data), j) == 0);
    assert(j->body == saved && j->body_len == length && atomic_load(&e->memory_bytes) == reserved);
    for (size_t i = 0; i < length; i++) assert(j->body[i] == 'x');
    assert(j->body[length] == 0); ar_job_free(j);
    assert(!e->pending && !e->allocations && !atomic_load(&e->memory_bytes));
    ar_engine_free(e); curl_global_cleanup();
    printf("PASS callback data/bounds/pause/cancel/allocation cleanup; excessivePowerOfTwoCapacities=%u\n", excessive);
    if (argc == 2 && (excessive || bounded_completions != 4)) {
        fputs("FAIL terminator caused extra capacity doubling\n", stderr); return 2;
    }
}
