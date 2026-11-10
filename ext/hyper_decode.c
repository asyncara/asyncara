#ifdef HAVE_ASYNCARA_HYPER
#include "native.h"
#include <zlib.h>

struct ar_http_decoder {
    z_stream stream;
    size_t encoded;
    int gzip, ended;
};
static voidpf allocate(voidpf opaque, uInt count, uInt size) {
    return ar_calloc(opaque, count, size);
}
static void release(voidpf opaque, voidpf data) { (void)opaque; ar_free(data); }
struct ar_http_decoder *ar_http_decoder_new(ar_engine *e, int coding) {
    if (coding != 1 && coding != 2) return NULL;
    struct ar_http_decoder *d = ar_calloc(e, 1, sizeof(*d));
    if (!d) return NULL;
    d->gzip = coding == 1;
    d->stream.zalloc = allocate; d->stream.zfree = release; d->stream.opaque = e;
    if (inflateInit2(&d->stream, d->gzip ? 31 : 15) != Z_OK) {
        if (d->stream.state) inflateEnd(&d->stream);
        ar_free(d); return NULL;
    }
    return d;
}
int ar_http_decoder_feed(struct ar_http_decoder *d, const unsigned char *data, size_t n,
                        int (*sink)(void *, const unsigned char *, size_t), void *context) {
    /* Bound compressed input independently from the sink's decoded-body bound.
     * Check before narrowing to uInt or dereferencing the input. */
    if (n > AR_LIMIT - d->encoded) return -2;
    d->encoded += n;
    if (!n) return 0;
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
int ar_http_decoder_finished(const struct ar_http_decoder *d) { return !d || d->ended; }
void ar_http_decoder_free(struct ar_http_decoder *d) {
    if (!d) return;
    inflateEnd(&d->stream); ar_free(d);
}
#endif
