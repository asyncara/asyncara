# Hyper buffered request completion

Current continuation: [client identity/redirect/MIME](HYPER_COMPLETION.md),
[HTTP/2 ownership](HYPER_HTTP2.md) and [explicit proxies](HYPER_PROXY.md) have
separate source/test identities. The dated scope below is preserved.

EXPERIMENTAL. This continuation starts at `26196e4` after the independently
verified server-r4 package. Buffered requests are IMPLEMENTED_AND_TESTED in the
functional/lifecycle scope below; independent packaging is recorded separately.
Completed evidence is scoped to the individual command, not full Hyper parity.

## API and bounds

The optional `hyper-prototype` backend uses existing `HttpClient::request()` and
`post()` APIs for buffered HTTP/1.1 requests over HTTP or verified HTTPS. JSON,
form and binary bodies use the same PHP encoding behavior as the default curl
backend. GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS and a custom SEARCH method
are exercised by the new fixture. Methods accept 1..32 uppercase ASCII letters;
CONNECT, TRACE and nonempty HEAD bodies are rejected.

```php
// Select ASYNCARA_HTTP_BACKEND=hyper-prototype before PHP request initialization.
Asyncara\Async::run(function (): void {
    $client = new Asyncara\HTTP\HttpClient(caFile: '/path/to/trust.pem');
    $created = $client->post('https://service.example/items', json: ['name' => 'one']);
    $updated = $client->request('PUT', 'https://service.example/blob', "a\0b",
        ['Content-Type: application/octet-stream']);
    [$first, $second] = Asyncara\Async::gather([$created, $updated]);
});
```

The example address is illustrative; executed tests use private local peers.

The C bridge passes a borrowed `ar_hyper_request` view to
`ar_hyper_submit_http`. Rust validates and copies it synchronously into
`RequestSpec`. No borrowed PHP option/body/header pointer survives submission.
Existing GET-only FFI entry points retain their ABI. The body is limited to
16 MiB; at most 256 header entries and 64 KiB combined header lines are admitted.
Duplicate ordinary headers retain order. Header syntax and values are checked
using Hyper's typed header implementation. Caller-owned Host, Content-Length,
Transfer-Encoding, Connection, Upgrade, Expect, Trailer, TE, Accept-Encoding
and proxy framing/authorization fields are rejected. Curl's empty-header
removal syntax is rejected rather than silently reinterpreted.

There is a C-owned body and an additional Rust-owned body copy. This is not
zero-copy. The C body remains charged to the existing native ledger through
operation completion; the Rust copy, Hyper/TLS buffers, PHP values, trust caches
and kernel buffers are additional. This does not establish aggregate process
memory accounting. Existing native operation admission, connection and body
bounds still apply. No unbounded upload queue or new worker pool is introduced.

DNS and CA-file loading remain worker-backed. Socket/TLS waits remain integrated
with the current readiness reactor. No Tokio executor or blocking network wrapper
is introduced. Streaming uploads/responses, decompression, redirects, proxies,
client mTLS and HTTP/2/3 remain outside this Hyper slice. Default curl retains its
existing functionality.

## Completion and early responses

An explicit closing response is never returned to the connection pool. Receiving
its complete final response discards any remaining upload by closing that
connection. Bytes already transmitted cannot be undone. HTTP/1.0 connections are
not retained by this slice. No automatic retry/replay of body-bearing requests is
introduced.

For keep-alive, receiving the final response is not enough to release a still
buffered upload. `Sender::ready()` means request-channel admission; it is not a
wire-flush guarantee. `ClientTransport` records pending writes until Hyper reaches
the transport's successful flush (including TLS output). `Engine::drive` retains
the completed response's native operation and C body accounting until that flush
or connection closure. Flush means local transport buffers reached the socket;
it does not acknowledge application consumption by the remote peer. Timeouts
and owner cancellation remain effective in this
state. The existing one-driver socket waker remains the only socket poller.

One waiting Task can cancel its wait without cancelling the operation owner.
Cancelling the owned request releases its connection and pending buffers.
The normal PHP request shutdown still owns the engine/pool's final cleanup.

## Reproductions and corrections

Raw evidence: `/home/coder/Asyncara-hyper-requests-20261110T042002Z`.

* `held-before-r1` and `held-old-r2` reproduce invalid pool reuse after an early
  413/Connection:close while an 8 MiB upload remains pending. `held-new-r2` passes;
  `repeat-new-r2` passes 100 rejection/recovery cycles. No acceptance deadline was
  raised.
* `tls-write-backpressure/unit-before.log` fails in 0.13s: actual kernel EAGAIN on
  outbound TLS plus actual POLLIN for an independent reply still returns Pending
  from `TlsIo::poll_read`. Re-entering `handshake` flushed application output before
  reading. A handshake-complete latch preserves the initial handshake flush while
  removing this later read/write coupling. The same unit passes in 0.12s. It uses
  real loopback sockets and private-CA TLS, not an immediate completed Future.
* `early-keepalive/before-r1` records premature operation completion. The first
  attempted `Sender.ready()` correction was insufficient and its failures remain
  in `focused-r2` and `early-fixed-window-*`. Fixed-window listeners exclude TCP
  autotuning as a substitute for pending I/O. The actual flush-owner correction
  passes `focused-r3-poll`: 60 requests, two ticks during held uploads, final zero
  native pending/owned bytes and zero live Tasks/Futures/timers/watchers.

`matrix-r1` and `matrix-r2` contain real 30s request failures. They are not erased
by the new focused passes. The completed-driver-retention hypothesis was initially
unproven; no patch was applied until the separate EOF reproduction below. These
defects do not establish the cause of the historical Phase 4 six-hour zero-tick
failure.

The broader HTTP gate then failed at `/head/64000` in `integration-r1`, even though
the complete valid response had arrived. `geometry-diagnostic-r1.log` records
closed=true, driver retained, no pending writes and an unwoken transfer Future.
Keeping the completed Hyper Driver alive retains its dispatch Receiver, leaving
the new `Sender::ready()` wait pending after graceful EOF. The real-socket unit
`completed_response_then_eof_releases_dispatch_receiver` independently fails in
3.01s before the fix (`driver-drop-before.log`). `Conn::poll` now immediately drops
the completed driver so Receiver destruction notifies the response owner. This
does not retry the request, change deadlines, or discard an incomplete response.
The same unit passes in0.01s after the fix. Unchanged transfer_geometry passes
on epoll/poll in1.045/1.083s. This EOF regression was introduced during the new
post-response ownership work; it is not attributed to the earlier server-r4 GET
implementation, whose prior geometry test passed.

The added late-cancellation fixture also exposed a separate intermittent upload
timeout (`late-cancel-matrix`, `late-cancel-repeat-r1`). A diagnostic-only module
captured it in `native-deadline-r2/run-4`: the connection remained live, both
canonical read/write wakers were registered, and writes were genuinely pending.
The independent TCP snapshot recorded 3,882,496 unsent bytes, a 16,000-byte send
window and 99.3% receive-window-limited busy time. The peer had reduced SO_RCVBUF
after application data was already in flight, then enlarged it at release.

`python-peer-control-r1` reproduces the stall with Python/OpenSSL sockets and the
same peer, without PHP, Asyncara, Rust or Hyper: run 3 fails both the 30s write
and 30s read deadlines. The fixture correction sets bounded receive windows on
separate held-upload listeners **before SYN/accept**, avoiding mid-flight window
shrinking. Payload size, required two ticks, cancellation, deadlines and cleanup
assertions are unchanged. `python-peer-control-r2` passes 12 independent uploads.
Original peer/PHP sources and hashes are retained in `receive-window-before`.
This identifies the reproduced fixture stall; it does not retroactively explain
every earlier timeout whose request/native state was not recorded.

## Reproducible validation

`tests/http/hyper_requests.py --php PHP --module MODULE --backend epoll --out NEW`
(and poll) generates isolated private certificates and verifies methods, binary
bodies, duplicate headers, pool identity, early replies, flush ownership, limits,
cancellation and final cleanup. The output path must not already exist.

`ASYNCARA_TLS_TEST_CERT_DIR=NEW/certs cargo test --release --locked
--manifest-path ext/hyper/Cargo.toml --lib
established_tls_reads_while_application_writes_are_blocked -- --ignored`
executes the separate controlled kernel/TLS regression. The fixture-dependent
test is explicitly invoked in CI; an ignored ordinary test run is not its PASS.

`tests/http/hyper_fpm.py --requests --extension MODULE --output NEW` adds a distinct
bounded POST fixture to the existing short lifecycle harness, retaining its
four-operation peer barrier and original two-tick/overlap assertions. It does not
replace the original GET fixture or historical endurance campaign.

Matrix, FPM, memory and failure-injection results are below. Independent package
attestation is separate from these working-tree source checks.

## Compatibility matrix chronology

`matrix-r3/report.json` passes 95/95 commands: PHP8.3/8.4/8.5 NTS, DEBUG
and GCC ASan/UBSan configurations, each on epoll/poll. This includes the server
protocol, API, TLS, input/output streaming and client TLS/new request cases.
Six repeated normal request runs also pass. The C/PHP sanitizer build does not
instrument all Rust or external-library internals. Native source and matrix
fixtures matched their recorded manifest at completion. Later fixture additions
cover cancellation after response completion but before upload flush, use the
pre-SYN bounded windows described above, and explicitly verify FPM backend
selection. Their separate validation must not be confused with this earlier run.

After the completed-driver correction, `matrix-r4/report.json` passes95/95
commands on all five configurations and both backends, including the final
62-request fixture with late cancellation and corrected receive-window setup.
All native/API/test files match that run's source manifest. Before this last
native change, `fixed-window-final` separately passed30/30 request runs and the
Python-only control passed12 uploads; those are retained at their prior module
identity. Broader gates and independent packaging remain separately recorded.

## Final functional, lifecycle and memory checks

`regressions-r2/report.json` passes17 commands: Rust9 ordinary units plus the
two explicitly invoked TLS fixture units, Gate A47 commands/252.651866s,
full PHP85 epoll/poll suites, eight four-worker server controls (plain/TLS,
normal/forced-worker-exit), body ownership and allocation audits.

`integration-r2/http/report.json` passes74 checks. The short FPM reports and
`fpm-independent-review.json` recalculate120 main responses/480 POST operations,
with each body65536 bytes, on both backends. Each backend has20 sequential main
requests and40 requests across four real workers; fatal request handling is
followed by recovery in the same worker. All original overlap/two-tick assertions
pass; minimum ticks across four-worker responses are16/9 on epoll/poll. Every
idle sample has6 descriptors and1 thread. These small sample sets do not prove
long-run RSS stability or replace the historical FPM campaign.

The aggregate `integration-r2` retains **FAIL** because fresh Rust/AWS-LC
compilation hit the old180s combined build/test watchdog before tests started.
No compiler/test process remained after inspection. The harness now separately
records compilation with a600s build watchdog; runtime execution retains180s and
all original assertions. A fresh `ffi-r3` passes20 commands, including16 actual
C/Rust failure-injection cases on epoll/poll. Test controls are absent from the
production module. The completed build took179.298s; ordinary unit execution
took2.022s. This infrastructure failure is not relabeled as a runtime pass.

`memory-r2` passes actual HTTP/TLS methods,8MiB uploads, early responses,
cancellation and cleanup under unsuppressed Valgrind3.22/DEBUG PHP on epoll/poll
(52.333s/47.606s). Both full reports show zero errors and zero definite, indirect
or possible loss;5306bytes remain reachable in19blocks. These are diagnostic
memory runs, not latency acceptance. Distribution-PHP/upstream findings retain
their independent historical scope.

`integration-final-review.json` verifies the successful component reports,
module hashes and full Valgrind summaries while retaining the original aggregate
failure. The only post-matrix executable-source change is the FFI harness's
separate build stage/process-group handling, independently exercised by ffi-r3.
The CI workflow now invokes request and FPM regressions explicitly; no hosted
CI result is claimed before it executes.

## C-only and performance controls

`c-only-r2` passes build/identity and method/transfer-geometry checks on epoll/poll
without Rust. The earlier `c-only` failure is a caller output-path mistake: the
build helper prefixed its working directory to an absolute module path during
load verification. The compiler succeeded. The second runner uses the expected
relative output; no build/runtime assertion was changed.

`comparison-r1` completes30 rotated samples/240000 responses with three samples
per client/body, matched PHP8.5.12 references,32 connections and four nginx
workers. Functional checks PASS; performance NOT_MET. Rust median4763.23/s at
1KiB and3060.37/s at64KiB does not meet the earlier13k/4x/3x request. The old
native-module control uses the same current PHP wrapper. Detailed samples and
scope are in LATEST_BENCHMARK.md; no general speed improvement is claimed.

## Independent buffered-request package

Source: `d0c270a538c6d638149e4188f079dc3c2ae575bd`. Archive: `build/asyncara-0.1.0-dev-server-r5.tar.gz`,
598 files, SHA256 `721fa8b8e6139d56ba79b8496dac69dba5102964c3f78f3886f719e48c3438b0`.
Two archives are byte-identical and every entry matches the source commit. Fresh
extraction, fresh Cargo target and native build pass23 commands: server protocol,
API, TLS, input/output streaming, ownership and client TLS/buffered requests on
epoll/poll. Independent module SHA256:
`e55adac75d0798a5408a080b926bffa22352876ea2860f858c136bd4574b5ca7`.
[Build attestation](phase7/server-r5-package.json). No release/push or universal
performance/endurance certification. Historical archives remain unchanged.


## Early buffered response admission (2026-11-12 continuation)

The optional Hyper transport now checks an ordinary final response's advertised
Content-Length against the existing C-owned16MiB buffered-body bound before
waiting for its body. `transfer()` calls `ar_hyper_body_size()`; oversized
buffered responses fail with `OperationException`. This is earlier resource
admission, not a larger body limit or a claim that the previous implementation
allowed unbounded body accumulation. Unknown lengths still use the incremental
receive/decoder limits. Encoded and decoded limits remain separate.

HEAD and304 lengths describe representations and do not trigger this guard.
204 and HTTP/1 Transfer-Encoding framing are left to the existing protocol
parser. Streams retain their existing bounded-buffer policy and can transfer
more than16MiB. Curl behavior is unchanged; the early check is specific to Hyper.
A failed HTTP/1 operation closes its transport. HTTP/2 resets only that request's
owned stream, preserving unrelated admitted requests and the shared connection.

`tests/http/declared_size.php` uses a peer that sends an oversized header but
never sends body bytes. The old module fails the one-second waiter deadline;
the corrected module must reject with the capacity error instead. Exact16MiB,
unknown-length overflow,17MiB-class bounded streaming, HEAD/304 and final native
operation/Future/ledger collection are checked separately. The H2 variant
requires a held sibling to survive on the same connection. No timeout or
historical assertion is relaxed. `declared_size.py` runs HTTP/1;
`http2.py --transport hyper --case declared-size` runs verified TLS H2, optionally
with `--proxy-auth` for nested TLS. The HTTP gate and CI include these cases.

External evidence root:
`/home/coder/Asyncara-hyper-completion-20261111T235911Z`.
`declared-before-r1` preserves the old-module FAIL. The first new HTTP/1 peer
closed HEAD/304 without declaring Connection:close, racing pool reuse on poll;
`declared-after-poll-r1` preserves that fixture failure. Its wire declaration
was corrected, not the runtime retry policy. The five-build matrix passes38 commands in213.312257s: PHP8.3.36/8.4.27/8.5.12,
8.5.12 DEBUG and GCC ASan/UBSan, each on epoll/poll. This covers HTTP1, H2 direct
and nested-proxy cases, plus H2 lifecycle controls and15 Rust unit tests. GCC
instruments PHP/C, not release Rust or AWS-LC. Broad validation is recorded separately.
The C ledger threshold in the stream test is not a total RSS/Rust allocator cap.


Full-workload ordinary DEBUG Valgrind remains **FAIL** in all four declared-size
cases at the original5s transport deadline. Each report nevertheless has0
errors/definite/indirect/possible lost bytes; reachable4114bytes for HTTP1 and
5354 for H2 are retained. These clean allocation summaries do not make failed
workloads pass. In the separate admission-only memory diagnostic, both HTTP1
backends and H2/poll PASS with zero errors/loss; H2/epoll FAILs the cold warm-up
request before admission is exercised. No timeout is increased and no suppression
is used. See `declared-memory-r1` and `declared-memory-admission-r1` under the
external evidence root.

An identical bulk-stream-only differential control (`declared-bulk-controls-r1`)
compares the prior proxy DEBUG module with the candidate. Normal runs complete
16777217bytes; Valgrind runs time out after4325376 and4175936bytes respectively
at5.220s/5.229s. This demonstrates that the instrumented streaming deadline
failure predates this guard. It does not explain every historical timeout or
certify all memory paths. The separate control does not replace full acceptance.

Coherent normal validation **PASS**: Gate A55 checks207.413931s; HTTP113 checks224.763513s; standard PHP8.5 epoll41.412782s/poll41.085507s. Native and test manifests stayed unchanged during these runs. [Source/binary/raw evidence](phase7/hyper-body-admission.json). Full Valgrind failures above remain failures; independent source packaging is separate.
