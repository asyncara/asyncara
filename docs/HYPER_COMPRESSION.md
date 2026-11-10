# Hyper buffered response decoding

Current scope: IMPLEMENTED_BUT_NOT_FULLY_TESTED while the compatibility and
memory campaign completes. Release remains EXPERIMENTAL.

The optional Hyper HTTP/1.1 client advertises `gzip, deflate` for buffered
responses. gzip (including concatenated members) and RFC1950-wrapped deflate
are decoded by zlib through `ext/hyper_decode.c`. Rust owns HTTP framing,
readiness, TLS and connection lifetime. C owns the decoder and response buffer.
No curl fallback, Tokio executor or blocking network wait is introduced.

`ResponseStream` continues to request identity and delivers encoded bytes if a
server nevertheless sends compressed content. It retains its existing pause,
backpressure, cancellation and 64KiB native slot semantics. This change does not
introduce a streaming decompression API. Response headers describe the received
representation; content-length may differ from the decoded buffered body length.
HEAD/204/304 representation metadata does not create a decoder for an absent body.

## Bounds and ownership

Compressed input and decoded buffered output are each limited to16MiB. zlib state,
its internal allocations and output storage use the Asyncara native ledger.
The decoder uses32KiB transient stack scratch and retains no borrowed frame/stack
address between callbacks. These bounds do not account for Rust/Hyper/rustls
allocations or kernel buffers. Decoding consumes bounded CPU on the event-loop
thread; asynchronous I/O does not mean CPU work is preempted.

A job owns its decoder until ordinary completion, error, cancellation or request
cleanup collects the job. A truncated compressed body, checksum error, invalid
framing, rejected output or allocation failure rejects the Future and prevents
the failed connection being returned to the pool. Bytes already received cannot
be undone. A subsequent request may create a fresh connection.

Unsupported coding names, composite coding chains and duplicate non-identity
codings fail explicitly. Repeated identity fields retain their previous behavior.
Brotli, zstd and legacy raw-deflate HTTP responses are not supported here. The
default curl client retains its dependency-provided decoding capabilities.

The implementation uses zlib's public allocation and streaming APIs; see the
[official manual](https://zlib.net/manual.html). It does not implement DEFLATE
parsing itself or treat unsupported encodings as identity.

## Reproducible checks

`tests/http/hyper_decode.c` exercises the actual C codec with small input chunks,
every truncation boundary, concatenation, checksum corruption, sink rejection,
input bounds and injected allocations. A one-byte mutation in unused deflate
padding is not necessarily corruption: the initial invalid negative oracle and
an independent Python zlib control are preserved in the evidence directory.

`tests/http/hyper_compression.py --php PHP --module MODULE --backend epoll --out NEW`
uses a deterministic Python wire producer and private CA, without PHP's zlib
extension. Repeat with poll; `--bounds` uses a separate process with a1MiB native
limit set before submission. Tests retain two ticks while the peer withholds the
rest of a compressed response, and cover HTTP/HTTPS, known and chunked framing,
CRC errors, truncated encoded/HTTP bodies, decode expansion, cancellation,
deadlines, empty bodies, recovery, raw stream identity and final collection.

These tests are not broad proxy/HTTP2/3 certification, a latency SLA, a historical
zero-tick explanation or a universal memory-safety guarantee.
