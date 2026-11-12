#ifdef HAVE_ASYNCARA_HYPER
#include "native.h"
#include <zlib.h>
#include <brotli/decode.h>
/* Custom allocation is a static-only zstd interface. Build rules require the
 * matching static archive, hidden from other extensions. Never resolve this
 * advanced interface through a dynamically loaded zstd. */
#define ZSTD_STATIC_LINKING_ONLY
#include <zstd.h>

struct ar_http_decoder {
    z_stream stream;
    size_t encoded;
    int gzip, ended, coding, allocation_failed;
    ar_engine *engine;
    BrotliDecoderState *brotli;
    ZSTD_DStream *zstd;
};
static voidpf allocate(voidpf opaque, uInt count, uInt size) {
    return ar_calloc(opaque, count, size);
}
static void release(voidpf opaque, voidpf data) { (void)opaque; ar_free(data); }
static void *codec_allocate(void *context, size_t bytes) {
    struct ar_http_decoder *d = context;
    void *p = ar_calloc(d->engine, 1, bytes);
    if (!p) d->allocation_failed = 1;
    return p;
}
struct ar_http_decoder *ar_http_decoder_new(ar_engine *e, int coding) {
    if (coding < 1 || coding > 4) return NULL;
    struct ar_http_decoder *d = ar_calloc(e, 1, sizeof(*d));
    if (!d) return NULL;
    d->coding = coding; d->engine = e;
    if (coding == 3) {
        d->brotli = BrotliDecoderCreateInstance(codec_allocate, release, d);
        if (!d->brotli) { ar_free(d); return NULL; }
        /* Standard Brotli only: large-window extension stays disabled. */
        return d;
    }
    if (coding == 4) {
        if (ZSTD_versionNumber() != ZSTD_VERSION_NUMBER) { ar_free(d); return NULL; }
        ZSTD_customMem memory = {codec_allocate, release, d};
        d->zstd = ZSTD_createDStream_advanced(memory);
        if (!d->zstd || ZSTD_isError(ZSTD_DCtx_setParameter(d->zstd, ZSTD_d_windowLogMax, 23))) {
            ZSTD_freeDStream(d->zstd); ar_free(d); return NULL;
        }
        return d; /* RFC9659: at most an 8 MiB HTTP zstd window. */
    }
    d->gzip = coding == 1;
    d->stream.zalloc = allocate; d->stream.zfree = release; d->stream.opaque = e;
    if (inflateInit2(&d->stream, d->gzip ? 31 : 15) != Z_OK) {
        if (d->stream.state) inflateEnd(&d->stream);
        ar_free(d); return NULL;
    }
    return d;
}
static int zlib_feed(struct ar_http_decoder *d, const unsigned char *data, size_t n,
                        int (*sink)(void *, const unsigned char *, size_t), void *context) {
    z_stream *z = &d->stream; z->next_in = (Bytef *)data; z->avail_in = (uInt)n;
    unsigned char output[32768]; int result = 0;
    for (;;) {
        if (d->ended) {
            if (!d->gzip) { result = -1; break; }
            /* RFC1952 concatenated members require a reset between members.
             * Preserve unconsumed borrowed bytes, including fragmented headers. */
            Bytef *next = z->next_in; uInt left = z->avail_in;
            if (inflateReset(z) != Z_OK) { result = -1; break; }
            z->next_in = next; z->avail_in = left; d->ended = 0;
        }
        uInt before = z->avail_in;
        z->next_out = output; z->avail_out = sizeof(output);
        int code = inflate(z, Z_NO_FLUSH);
        size_t produced = sizeof(output) - z->avail_out;
        if (produced && sink(context, output, produced)) { result = -4; break; }
        if (code == Z_STREAM_END) {
            d->ended = 1;
            if (!z->avail_in) break;
            continue;
        }
        if (code != Z_OK && code != Z_BUF_ERROR) { result = code == Z_MEM_ERROR ? -3 : -1; break; }
        if (before == z->avail_in && !produced) {
            if (z->avail_in) result = -1;
            break;
        }
        if (!z->avail_in && z->avail_out) break;
    }
    /* Do not retain caller/stack addresses between callbacks or during cleanup. */
    z->next_in = z->next_out = NULL; z->avail_in = z->avail_out = 0;
    return result;
}
static int brotli_feed(struct ar_http_decoder *d, const unsigned char *data, size_t n,
                       int (*sink)(void *, const unsigned char *, size_t), void *context) {
    if (d->ended) return -1; /* Brotli has one stream, no concatenated members. */
    size_t left = n; const unsigned char *input = data;
    unsigned char output[32768];
    for (;;) {
        size_t available = sizeof(output), before = left; unsigned char *next = output;
        BrotliDecoderResult result = BrotliDecoderDecompressStream(d->brotli,
            &left, &input, &available, &next, NULL);
        size_t produced = sizeof(output) - available;
        if (produced && sink(context, output, produced)) return -4;
        if (result == BROTLI_DECODER_RESULT_SUCCESS) { d->ended = 1; return left ? -1 : 0; }
        if (result == BROTLI_DECODER_RESULT_ERROR) return d->allocation_failed ? -3 : -1;
        if (result == BROTLI_DECODER_RESULT_NEEDS_MORE_INPUT) return left ? -1 : 0;
        if (left == before && !produced) return -1;
    }
}
static int zstd_feed(struct ar_http_decoder *d, const unsigned char *data, size_t n,
                     int (*sink)(void *, const unsigned char *, size_t), void *context) {
    ZSTD_inBuffer input = {data, n, 0}; unsigned char buffer[32768];
    for (;;) {
        size_t before = input.pos; ZSTD_outBuffer output = {buffer, sizeof(buffer), 0};
        size_t result = ZSTD_decompressStream(d->zstd, &output, &input);
        if (ZSTD_isError(result)) return d->allocation_failed ? -3 : -1;
        if (output.pos && sink(context, buffer, output.pos)) return -4;
        d->ended = result == 0;
        if (input.pos == input.size && (d->ended || output.pos < output.size)) return 0;
        if (input.pos == before && !output.pos) return -1;
        /* A subsequent frame automatically reuses the decoder's bounded state. */
    }
}
int ar_http_decoder_feed(struct ar_http_decoder *d, const unsigned char *data, size_t n,
                        int (*sink)(void *, const unsigned char *, size_t), void *context) {
    /* Reject before narrowing a size or dereferencing caller memory. Decoded
     * limits are independently enforced by the sink. All codec allocations
     * pass through the same aggregate ledger and failure cleanup. */
    if (n > AR_LIMIT - d->encoded) return -2;
    d->encoded += n;
    if (!n) return 0;
    if (d->coding == 3) return brotli_feed(d, data, n, sink, context);
    if (d->coding == 4) return zstd_feed(d, data, n, sink, context);
    return zlib_feed(d, data, n, sink, context);
}
int ar_http_decoder_finished(const struct ar_http_decoder *d) { return !d || d->ended; }
void ar_http_decoder_free(struct ar_http_decoder *d) {
    if (!d) return;
    if (d->brotli) BrotliDecoderDestroyInstance(d->brotli);
    else if (d->zstd) ZSTD_freeDStream(d->zstd);
    else inflateEnd(&d->stream);
    ar_free(d);
}
#endif
