# Hyper transport: owned HTTP/2 streams

Status: **IMPLEMENTED_AND_TESTED in the local scope below**. The complete Rust
Hyper backend and Hyper HTTP Client entities remain **PARTIALLY_IMPLEMENTED**.
Release classification remains EXPERIMENTAL.

The optional transport now negotiates `h2` or `http/1.1` through verified rustls
ALPN. Plain HTTP remains HTTP/1.1. Public HttpClient methods are unchanged.
HTTP/2 covers buffered requests, encoded response streams and known/unknown-size
upload streams. Files still use bounded native workers. No runtime hooks,
Tokio runtime, background Rust executor or blocking network calls are added.

## Native ownership and limits

HTTP/1.1 continues through Hyper1.12.0. HTTP/2 uses the Hyper project's **h2
0.4.20 protocol library directly**, with a small Tokio I/O-trait adapter to the
same readiness/TLS transport. `ext/hyper/src/protocol.rs` owns SendStream and
RecvStream together in the request Future; `lib.rs` owns the connection driver
and its canonical socket waker. Tokio is an I/O-trait dependency, not a scheduler.

The choice follows a reproduced ownership failure in an uncommitted prototype
using Hyper's HTTP/2 executor: an early413 with a zero peer upload window left
an opaque body task and request stream alive after PHP completion. Revoking its
C callback prevented a stale pointer call but did not reset a task blocked on
flow control. The retained prototype and failing report are listed below. The
final glue owns the actual send handle; destruction resets that stream and
discards pending upload data without closing healthy sibling streams. No
working HTTP/1 module was replaced and no dependency source was patched.

A request does not await admission for its *next* HTTP/2 stream before releasing
its current stream. The single-concurrent-stream/zero-upload-window regression
checks16 consecutive early responses,16 resets and one physical connection.
Separate waiter cancellation does not cancel the owner. Owner cancellation,
timeout, early response and response-stream close release the owned stream.
This cannot undo bytes already delivered. GOAWAY and connection failures are
explicit; submitted requests are not blindly replayed. Subsequent requests can
open a new connection.

Limits:64 admitted operations per multiplexed connection; existing process
connection/host limits;65,535-byte stream receive window;1MiB connection receive
window;16KiB frames;64KiB header lists and send buffer;4KiB HPACK table;64 reset
retention/pending/error entries. Server push is disabled. Existing C16MiB buffered
response/decode limits,64KiB stream/upload slots and bounded PHP queues remain.
Upload dispatch emits at most32 frames per poll and yields while flow-controlled.

These are layered bounds, **not complete Rust/TLS/process memory accounting**.
h2/HPACK/Bytes/rustls and kernel allocations are outside the managed C ledger.
An idle pool may retain bounded connection state until request teardown. A zero
managed-memory counter alone does not prove all dependencies released memory.

The protocol reference is [RFC9113](https://www.rfc-editor.org/rfc/rfc9113.html),
particularly stream errors, flow control and incomplete request messages.

## Executed evidence

Raw root:`/home/coder/Asyncara-hyper-completion-20261111T235911Z`.
Source parent:`57575e5000f0f1f74fa230e46e7df6f30ea5e36b`; the tests additionally
record working-source manifests. No archive/push is implied by this checkpoint.

- `h2-before-r1`: previous codec module cannot communicate with the h2-only peer;
  expected FAIL retained.
- `h2-wire-r1`: test selected the wrong backend environment name; FAIL retained.
  Corrected test asserts actual `hyper-prototype` selection.
- `h2-early-before-r1`: prototype FAIL, one early response, zero resets. Source
  is preserved in `h2-executor-prototype/`.
- `h2-early-r2`: owned-stream implementation PASS. `h2-focused-r1`: four PASS
  epoll/poll lifecycle and stricter single-stream/zero-window early-upload cases.
- `h2-matrix-r1`:36 commands PASS in127.680586s. PHP8.3.36/8.4.27/8.5.12,
  DEBUG and GCC ASan/UBSan each run Hyper lifecycle/early-upload and existing
  curl HTTP/2 controls on epoll/poll.13 Rust unit tests pass; two fixture-dependent
  TLS tests were ignored in that invocation and are not counted as passes.

The independent Python h2.4.4.1/OpenSSL peer records actual connection/stream IDs.
The lifecycle test proves four held requests share one TLS connection, with two
required timer ticks before release; cancelling one waiter/owner preserves
siblings. It checks buffered upload/HEAD/gzip/redirects, oversized headers/body,
short/reset responses, deadlines, backpressure, early response-stream close,
known/unknown-length uploads, producer failure, trailers, informational replies,
GOAWAY, abrupt disconnect, recovery and final native/Future/timer/watcher cleanup.
GCC instruments PHP/C, not every Rust/dependency allocation.

Reproduction (install only the pinned independent peer dependencies from
`tests/http/http2-requirements.txt` in an isolated Python environment):

```sh
python tests/http/http2.py --transport hyper --php /path/php --module /path/asyncara.so --backend epoll --out /new/h2-lifecycle
python tests/http/http2.py --transport hyper --case early-upload --php /path/php --module /path/asyncara.so --backend poll --out /new/h2-early
python tests/http/http2.py --transport hyper --server --php /path/php --module /path/asyncara.so --backend epoll --out /new/h2-handler
```

The `--server` variant runs the same lifecycle assertions in an App handler;
this certifies the outbound client path, not an HTTP/2 server listener.

## Remaining boundaries

Hyper proxies, HTTP/3, cleartext h2c, HTTP/2 server listeners, push, extended
CONNECT, automatic retry and broad internet/appliance certification remain
unimplemented or unverified. curl response streams still force HTTP/1.1. Native
Server still speaks HTTP/1.1. Existing TLS verification remains mandatory.
Historical zero-tick and dependency memory findings are not resolved by these
short protocol tests. No fastest-in-PHP or long-duration stability claim follows.

## Acceptance observer and remaining validation

`h2-validation-r1` retains an unexplained GCC/poll Native Server500 response;
the original observer did not retain the PHP exception. The observer now keeps
exception metadata and remaining stdout. Six subsequent unchanged runs passed
(`h2-server-gcc-repeat-r1`), which alone does not explain the original failure.
An isolated early-response experiment then reproduced a narrower assertion defect
(`early-producer-observer-r1`, iteration27): HTTP413 arrived before the upload
generator started; both started/closed flags were false and native pending count
was zero. An unopened generator has no finally scope to release. This is consistent
with, but does not prove, the cause of the original unrecorded exception.

The lifecycle peer now waits for the first DATA frame before its early413. The
original deadline and cleanup assertions remain, additionally requiring producer
start. The separate zero-window regression still checks response before upload
start. Six GCC/poll Native Server repetitions pass (`h2-server-gcc-repeat-r2`).

Gate A passes in184.195098s and the full HTTP gate passes in195.2251s
(`h2-validation-r2-remaining`). That combined run then failed before loading the
extension because its supervisor passed an absolute module path to a script
expecting a relative path. The corrected invocation passes complete PHP85 suites
on epoll31.890690s/poll30.568048s (`h2-full-r3`). No runtime/test threshold changed.

Ordinary DEBUG Valgrind (`h2-memory-r1`) passes the lifecycle case on epoll/poll:
zero errors and definite/indirect/possible losses;5354 bytes remain reachable.
Both cold3s early-upload cases fail a deadline with zero memory errors/losses.
These incomplete workloads are FAIL, not memory-clean acceptance passes.

## Draining connection regression

The first independent phpize build completed, but its lifecycle run failed around
GOAWAY (`codecs-h2-phpize-r1`). The new deterministic `http2_goaway.php` keeps
an admitted stream pending, delivers GOAWAY, and requires a new connection to
release the old stream. The original H2 candidate fails in0.502033s
(`h2-goaway-before-r1`); the correction passes16cycles in0.681314s
(`h2-goaway-after-r1`).

`Conn::poll` now records retired admission separately from connection failure.
A draining connection's driver remains alive for admitted streams. Pool selection
excludes it; an assigned operation which has not yet been submitted can detach
and acquire another connection without replaying bytes. Existing connection
limits remain. This fixes a reproduced new implementation defect, not the
historical Phase4 zero-tick failure.

`h2-goaway-matrix-r1`:56commands PASS in184.884180s: PHP83/84/85, DEBUG and
GCC ASan/UBSan; lifecycle, early-upload, GOAWAY, unchanged curlH2 and Native
Server handler cases on both backends. The same13 ordinary Rust tests pass.
Two fixture-dependent RustTLS tests passed separately on the preceding source;
the GOAWAY change does not alter their TLS implementation. Final broad, memory
and independently rebuilt package validation follows this source checkpoint.
