#include "../../ext/native.h"
#include <assert.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <zlib.h>

static size_t fail_at, allocations;
void *__real_malloc(size_t);
void *__wrap_malloc(size_t n) {
    if (++allocations == fail_at) return NULL;
    return __real_malloc(n);
}
typedef struct { size_t bytes, reject; int verify; } sink_state;
static int sink(void *context, const unsigned char *data, size_t n) {
    sink_state *s = context;
    if (s->reject || n > AR_LIMIT - s->bytes) return -1;
    if (s->verify) for (size_t i = 0; i < n; i++) assert(data[i] == 'x');
    s->bytes += n; return 0;
}
static size_t wire(unsigned char *out, const unsigned char *data, size_t n, int gzip) {
    z_stream z = {0}; assert(deflateInit2(&z, 6, Z_DEFLATED, gzip ? 31 : 15, 8, Z_DEFAULT_STRATEGY) == Z_OK);
    z.next_in = (Bytef *)data; z.avail_in = n; z.next_out = out; z.avail_out = 4096;
    assert(deflate(&z, Z_FINISH) == Z_STREAM_END); size_t result = z.total_out; assert(deflateEnd(&z) == Z_OK); return result;
}
int main(void) {
    ar_engine e = {0}; e.memory_limit = 1048576;
    unsigned char data[131072], compressed[8192]; memset(data, 'x', sizeof(data));
    for (int coding = 1; coding <= 2; coding++) {
        size_t n = wire(compressed, data, sizeof(data), coding == 1);
        for (size_t step = 1; step <= 257; step *= 4) {
            struct ar_http_decoder *d = ar_http_decoder_new(&e, coding); assert(d); sink_state s = {0,0,1};
            for (size_t i = 0; i < n; i += step) assert(ar_http_decoder_feed(d, compressed + i, step < n-i ? step : n-i, sink, &s) == 0);
            assert(ar_http_decoder_finished(d)); assert(s.bytes == sizeof(data)); ar_http_decoder_free(d); assert(atomic_load(&e.memory_bytes) == 0);
        }
        for (size_t end = 0; end < n; end++) {
            struct ar_http_decoder *d = ar_http_decoder_new(&e, coding); assert(d); sink_state s = {0};
            assert(ar_http_decoder_feed(d, compressed, end, sink, &s) == 0); assert(!ar_http_decoder_finished(d)); ar_http_decoder_free(d); assert(atomic_load(&e.memory_bytes) == 0);
        }
        for (size_t failure = 1; failure <= 4; failure++) {
            allocations = 0; fail_at = failure; struct ar_http_decoder *d = ar_http_decoder_new(&e, coding);
            if (d) { sink_state s = {0}; (void)ar_http_decoder_feed(d, compressed, n, sink, &s); ar_http_decoder_free(d); }
            fail_at = 0; assert(atomic_load(&e.memory_bytes) == 0);
        }
        struct ar_http_decoder *d = ar_http_decoder_new(&e, coding); assert(d); sink_state s = {.reject=1};
        assert(ar_http_decoder_feed(d, compressed, n, sink, &s) < 0); ar_http_decoder_free(d); assert(atomic_load(&e.memory_bytes) == 0);
        d = ar_http_decoder_new(&e, coding); assert(d); s = (sink_state){0};
        assert(ar_http_decoder_feed(d, compressed, (size_t)AR_LIMIT+1, sink, &s) < 0); ar_http_decoder_free(d); assert(atomic_load(&e.memory_bytes) == 0);
        compressed[n-1] ^= 1; d = ar_http_decoder_new(&e, coding); assert(d);
        assert(ar_http_decoder_feed(d, compressed, n, sink, &s) < 0); ar_http_decoder_free(d); assert(atomic_load(&e.memory_bytes) == 0);
    }
    size_t n = wire(compressed, data, sizeof(data), 1); memcpy(compressed+n,compressed,n);
    struct ar_http_decoder *d = ar_http_decoder_new(&e, 1); assert(d); sink_state s = {0,0,1};
    assert(ar_http_decoder_feed(d,compressed,n*2,sink,&s)==0&&ar_http_decoder_finished(d)); assert(s.bytes==2*sizeof(data));
    ar_http_decoder_free(d); assert(atomic_load(&e.memory_bytes)==0);
    printf("PASS decoder fragmentation, truncation, concatenation, CRC, sink failure, input limit, allocation failures; peak=%zu\n",atomic_load(&e.memory_peak));
}
