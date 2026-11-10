# Hyper buffered response decoding

Current scope: IMPLEMENTED_AND_TESTED for the bounded buffered gzip/deflate
behavior and configurations documented below. Release remains EXPERIMENTAL.

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

## Executed evidence

Native source `23e652f`; the following checks use that runtime. The later
`tests/http/hyper.php` expectation update replaces obsolete gzip rejection with
an exact decoded-byte comparison. Every unrelated rejection assertion remains.
The initial HTTP gate FAIL remains preserved; its passing successor is distinct.

Raw root: `/home/coder/Asyncara-priority-20261110T205459Z`.

- `compression-matrix-r1/report.json`:45 build/test commands PASS,317.721s;
  PHP8.3.36/8.4.27/8.5.12/DEBUG/GCC ASan+UBSan, epoll and poll.
- `compression-memory-r1/report.json`: nine scoped native/PHP memory checks
  PASS. Native codec peak tracked allocation40,152bytes. Unsuppressed DEBUG
  Valgrind reports zero errors/definite/indirect/possible losses. Functional
  controls retain5,306bytes/19blocks; capacity controls4,066bytes/14blocks.
  Release Rust/AWS-LC is not compiler-instrumented by these GCC C/PHP builds.
- `compression-phpize-r1/report.json`: isolated phpize/configure/make and scoped
  server/client protocol/TLS/stream checks PASS,12commands. This build links
  the existing Rust library; fresh Rust packaging is separate.
- `compression-regressions-r1`: Gate A PASS47commands/183.667s; fullPHP85
  epoll34.536s and poll36.593s PASS. HTTP stopped at the obsolete gzip rejection.
- `compression-regressions-r2`: HTTP84checks PASS131.137s; server protocol/TLS,
  allocation audit and compressed-response FPM PASS on both backends.
  Each FPM run has20 sequential plus40 four-worker main responses (240 HTTP
  operations). Minimum ticks:epoll28/22, poll24/23; idle workers6fds/1thread.
  Raw request records, isolated FPM logs and fixture/module hashes are retained.
  These are short functional controls, not endurance certification. The fatal
  fixture leaves a request pending but does not prove the decoder was active
  at the exact fatal instruction.

The failure artifacts include the original unsupported-codec negative control,
invalid checksum-oracle trial, post-start memory-reconfiguration test rejection
and obsolete gzip-rejection assertion. None is overwritten or counted as PASS.
Historical distribution-PHP/fatal-bailout, libpq and libcurl findings remain open
in their original scope. This codec's scoped results do not erase those findings.

Rust13units (zero ignored) and16 native FFI failure controls pass in `compression-extra-r1`; their fault-enabled library is isolated from the PHP module. C-only build and ordinary curl streaming pass on both backends. [Durable commands/fingerprints](phase7/hyper-compression.json) retain the failed first HTTP gate and its successful successor separately.
