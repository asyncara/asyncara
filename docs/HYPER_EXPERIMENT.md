# Hyper HTTP backend experiment

Status: **IMPLEMENTED_BUT_NOT_FULLY_TESTED**, opt-in prototype. Default HTTP
continues to use curl multi with existing TLS, DNS, proxy, upload, download,
multipart and streaming support. No universal performance claim is established.

This experiment uses Hyper 1.12.0 through a pinned Rust static library and a
small C ABI. It is driven on the interpreter thread by Asyncara's real epoll/poll
wait; it does not start a Tokio executor or native network worker. Rust never
calls Zend. Existing public HttpClient/Future APIs and cancellation ownership
remain in place. No change to optional PHP hooks is involved.

## Deliberate scope

`ASYNCARA_HTTP_BACKEND=hyper-prototype` selects **numeric-address plaintext HTTP
GET** with buffered responses. Other methods, DNS names, HTTPS, custom headers,
redirect following, proxies, uploads and response streaming are rejected. Proxy
environment variables also cause rejection. There is no silent fallback which
could bypass a proxy or create a second independently limited connection pool.
Compression is explicitly rejected; identity is requested. Hyper handles HTTP/1.1
framing including chunking; the C buffer bound includes trailers. HTTP/2 and
HTTP/3 are not enabled. This is not a replacement for the complete curl path.

## Ownership and bounds

One request-local Rust engine owns pooled sockets, connection drivers and pending
response futures. Limits are the existing 1..256 connection settings, per-host
admission and at most 1024 native jobs. Each Rust driver has a 64 KiB read buffer
limit and 256 header field limit. URI length is capped at 8192. C-owned response
buffers retain the existing 16 MiB body and 64 KiB aggregate header limits and
native memory ledger. Rust and Hyper internal allocations are **not** included in
that ledger; this is not a total process memory ceiling. Rust allocator exhaustion
can abort the process and is not a recoverable PHP exception.

The Rust executor spends at most 512 connection/operation polls before returning
ready work to the normal loop; it does not spin waiting for sockets. Deadlines use
Rust monotonic Instant; the C wait preserves its original timer budget. Completion
calls only C queue publication. PHP conversion occurs after Rust returns. An
explicit cancellation drops the operation and its connection before publishing
completion. Cancelling one PHP waiter retains the shared producer. Request
shutdown drops Rust futures and sockets before freeing C jobs. No Rust worker can
complete against a finished PHP request.

## Build and evaluation

Provide an isolated installed Rust toolchain, PHP headers and Cargo target path:

```sh
CARGO_TARGET_DIR=/absolute/isolated/target \
ASYNCARA_OUTPUT=build/http-hyper.so PHP_BIN=/path/to/php \
PHP_INCLUDE_DIR=/path/to/php/include bash scripts/build-hyper-local.sh
ASYNCARA_HTTP_BACKEND=hyper-prototype ASYNCARA_BACKEND=poll \
/path/to/php -n -d extension=build/http-hyper.so tests/http/hyper.php
```

Cargo.lock pins the dependency tree. The default build has no Rust dependency.
The experimental local script does not install global tools or enable the backend
by default. Portable phpize integration and package certification are unfinished.
`asyncara_info()` exposes the selected HTTP backend and exact prototype submission,
completion and connection counts. The benchmark asserts those counts, zero pending
jobs and zero tracked result memory, so raw-native or standalone Rust throughput
cannot be presented as public HttpClient throughput.

## Evidence and limitations

Evidence root: `/home/coder/Asyncara-http-speed-20261109T073938Z`.
`hyper-c32-jit-data-r1/report.json`: 5 rotated samples of 16000 checked complete
1 KiB responses, 32 persistent connections, PHP 8.5 tracing JIT, poll, local nginx.
Public HttpClient median **14623.47 requests/sec** (range 12573.50..16966.21).
Matched curl_multi median 16796.93 and Swoole median 17167.87. The median exceeds
13000 for this narrowly defined workload; the minimum does not. 4x/3x relative
targets are **NOT_MET**. The 64 KiB body median is 6053.57, not 13000.
Other recorded concurrency/backend/JIT runs include slower results and remain
preserved. These are not a latency SLA, universal throughput floor or an
endurance result. Final matrix and memory results will be appended only after
completion. Historical Phase 4 failures are unaffected by these experiments.


## Validation and later measurements

The final optional module (`http-hyper-final-85.so`, SHA-256
`ce3cb79f2df7d7b73163a15c7b0f98faf66ac16c3230cded4319869d0e1beb26`)
passes targeted PHP 8.3.36 / 8.4.27 / 8.5.12 NTS and DEBUG runs on epoll and poll.
The default curl HTTP feature suites also pass on all three NTS versions. Full
PHP 8.5 epoll/poll suites pass. Gate A passes in 187.64 seconds. Scoped DEBUG
Valgrind runs report zero errors and zero definite/indirect/possible lost bytes;
3888..3994 bytes remain reachable, with no suppressions. Scoped GCC ASan/UBSan
and LeakSanitizer checks pass. Rust machine code itself was not ASan-instrumented;
C/PHP instrumentation and malloc interception are not a claim of full Rust
sanitizer coverage. The historical distribution-PHP discrepancy is unchanged.

`final-public-comparison-data/report.json` compares the same public PHP source
and module with curl and Hyper selected, using identical JIT/poll/concurrency32
and five rotated samples. 1 KiB medians: Hyper 9374.10, public Asyncara/curl
6276.85, direct curl_multi 9119.99, Swoole 13728.21 requests/sec. Thus the newer
run does **not** reproduce a 13000 median. The separately configured four-nginx-
worker control also fails that threshold. All samples and configurations are in
[http-speed-samples.json](phase6/http-speed-samples.json). The earlier 14623.47
median is retained as scoped historical evidence, not substituted for later
slower results. No consistent 13000 floor or 4x/3x target has been demonstrated.

`isolated-hyper-fpm-r2-data/report.json` verifies 60 main requests with one and
four real FPM workers, the original >=2-tick/pending overlap condition, fatal
request teardown and next-request state reset. Idle workers retain 6 descriptors
and one thread. This short prototype scope excludes streaming/upload/TLS because
they are rejected. The independent curl feature fixture covers those features
separately. Neither is long-duration endurance certification.
