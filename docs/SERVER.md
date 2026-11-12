# Asyncara HTTP server — experimental development

## Development priority (2026-11-11)

The owner selected **Asyncara Server as the primary development and validation
mode**. FPM compatibility and existing tests remain available. New FPM-specific
failures are retained and deferred instead of blocking native-server progress;
a defect reproduced in shared runtime ownership, memory or I/O paths still
requires correction. Existing FPM results and historical failures keep their
original status. This priority decision is not a claim of new compatibility or
production readiness.


## Connection-local accept errors

Linux can return a queued TCP connection's pending error from `accept4()`;
this does not invalidate the listening socket or other accepted connections.
`Server::drive()` now retries `ECONNABORTED`, `ENETDOWN`, `EPROTO`,
`ENOPROTOOPT`, `EHOSTDOWN`, `ENONET`, `EHOSTUNREACH`, `EOPNOTSUPP` and
`ENETUNREACH` within its existing **64 attempts per turn**. Existing EINTR and
EAGAIN behavior remains. Invalid-listener and resource-exhaustion errors still
propagate; the server does not silently conceal EBADF, EMFILE, ENFILE, ENOMEM,
ENOBUFS or EINVAL. The classification follows the Linux
[accept(2) pending-network-error contract](https://man7.org/linux/man-pages/man2/accept.2.html).

The original implementation poisoned the entire server on ECONNABORTED and
cancelled an unrelated pending PHP handler. The regression leaves a real request
pending across two timer ticks, acknowledges each syscall injection, serves a
second timer-backed request, then independently releases the held request and
checks native cleanup. The separate native budget regression verifies persistent
errors cannot extend the existing accept loop beyond 64 attempts.

`tests/server/accept_fault.py` uses a test-only wrapper. PHP 8.3's RTLD_DEEPBIND
can bypass preload interposition, so CI explicitly links a separate fault module;
normal protocol/TLS/streaming tests use the production module. Fault injection is
not a feature of the production module. Raw failures and validation scope are
retained in [the accept-isolation evidence](phase7/server-accept.json).
Validation: 133 build/test commands passed across PHP 8.3/8.4/8.5 NTS, DEBUG
and GCC ASan/UBSan configurations on epoll/poll. Gate A passed 55 checks in
226.661 seconds; both complete PHP 8.5 suites and eight four-worker/body-lifetime
commands passed. Native Valgrind returned zero errors and zero live allocations;
DEBUG returned zero lost bytes, with 4,114 still reachable bytes in each run.
These are scoped results, not universal memory or endurance certification.

This fix does not establish the cause of the historical FPM zero-tick failure,
the retained SQL-handler disconnect, or the earlier slow-input TLS reset.

## Run the example

The server is opt-in: build the Rust bridge and native extension together. Use a
PHP 8.3–8.5 NTS SDK matching the interpreter's exact patch version, the existing
native development dependencies, and an installed Cargo toolchain. No global
PHP configuration change is required:

```sh
export PHP_BIN=/absolute/path/to/php
export PHP_INCLUDE_DIR=/absolute/path/to/matching/include/php
export CARGO_TARGET_DIR="$PWD/build/server-cargo"
export ASYNCARA_OUTPUT=build/asyncara-server.so
./scripts/build-hyper-local.sh
"$PHP_BIN" -n -d extension="$PWD/build/asyncara-server.so" \
    examples/server.php 127.0.0.1:8080
```

For four workers, load that interpreter's `posix` extension if it is not built
in, and append `4` to the example command. `pcntl` is also required. The default C-only build does not include the server. The opt-in
`--with-asyncara-hyper=DIR` phpize configuration and `build-hyper-local.sh` include
it; see INSTALLATION.md for reproducible commands.

```php
use Asyncara\App;
use Asyncara\Server\{Request, Response};

$app = new App();
$app->get('/status', fn() => Response::json(['status' => 'OK']));
$app->get('/work/{id}', function (Request $request) {
    Asyncara::sleep(5); // milliseconds; Fiber suspension, not blocking sleep
    return Response::json(['id' => $request->param('id')]);
});
$app->post('/data', fn(Request $request) => Response::json($request->json(), 201));
$app->listen('127.0.0.1:8080');
```

Load `autoload.php` or Composer's generated autoloader before this code. The
complete example includes groups and registered authentication middleware.

## Execution and ownership

Native Hyper 1.12.0 HTTP/1 transport uses Asyncara epoll/poll, with PHP handlers
running as Asyncara Fibers on the interpreter thread. There is no Tokio executor
and no cross-thread Zend access. This is a new opt-in server, not an HTTP-client
replacement. Linux only. Optional HTTPS termination and response streaming are described below.
HTTP/2, HTTP/3, WebSocket upgrades and production deployment certification remain unavailable.
Request streaming is opt-in as described below.

Use `Asyncara\App`, `Asyncara\Server\Request`, and
`Asyncara\Server\Response`. PHP class names are case-insensitive:
`Asyncara\Http\Response` conflicts with the existing client
`Asyncara\HTTP\Response`, whose instance `json()` decodes a response. The server
has static `Response::json()` / `Response::text()` factories in its own namespace.
The existing client API is preserved. `Asyncara::sleep(int milliseconds)` forwards
to the existing Fiber timer; `Asyncara\Async::sleep()` still takes seconds.

Routes support exact paths, `{name}` parameters, nested groups, and registered
named/callable middleware. Static routes take precedence; dynamic routes are
matched in registration order. Undefined named middleware fails before listening.
JSON decoding uses PHP ext-json's C parser. Request bodies are buffered by default; ordinary Response bodies are bounded and buffered.
Response::stream selects the separate bounded, demand-driven response path.

`Request::param(name, default)` reads a named route parameter. `query(name,
default)` decodes flat query names/values lazily; repeated names use the last
value, and PHP-style nested query arrays are not synthesized. `header(name,
default)` is case-insensitive and joins repeated values with commas. `json()`
decodes to arrays by default and throws for malformed JSON; App maps that error
to 400. Request properties `method`, `target`, `path`, and `body` are readonly.
Routing supports origin-form request targets, not a forward-proxy API.

Middleware has the signature `(Request $request, callable $next): Response|StreamResponse`.
Register names with `$app->middleware('auth', $handler)` before `listen()`, then
attach them with `$app->post(...)->middleware('auth')`. Middleware may suspend
using the same explicit Asyncara APIs as a route. Exceptions produce 500 without
returning exception text to the client. Applications can log failures in their
middleware; automatic request/error logging is not implemented here.

`listen(address, options, onReady)` accepts a numeric IP:port. Defaults per process:
1024 connections, 1 MiB request body, 8 MiB response body, 64 MiB owned payload
budget, 30 s connection/request-cycle deadline, 10000 requests per connection.
Head size is limited to 64 KiB and 100 fields. The deadline starts on accept and
resets on accepting a PHP response. It includes slow headers/body, handlers and
output; it is not an inactivity timer or preemption of blocking PHP code.
Payload accounting does not include Hyper parser buffers, PHP allocations,
allocator overhead, thread stacks or kernel socket buffers. Response bytes retain
their budget charge until the final transport owner drops them.

`workers` defaults to 1. Multiple workers require CLI, pcntl, posix, a fixed port,
and a single-threaded parent before any Asyncara native runtime initialization.
The supervisor forks interpreters; each binds with SO_REUSEPORT and owns separate
runtime state. Resources should be opened inside worker handlers. Context is
request-isolated; application globals/statics are process-shared. No worker
restart or cross-worker application-state synchronization is advertised.

`stop()` and CLI SIGTERM/SIGINT stop accepting and drain existing connections.
An abnormal worker exit stops the pool; it does not silently reduce capacity.
The parent reaps its own workers and enforces a bounded shutdown grace period.

PHP 8.5 epoll/poll protocol checks and four-worker lifecycle checks pass.
The first worker shutdown test exposed EINTR being overwritten by accept(EAGAIN)
after native wait; its failure is preserved and wait errno is now saved.
Performance acceptance against Go fasthttp is not established by functional
tests. Exploratory measurements must not be used as a production throughput
claim. See the [matched benchmark results](LATEST_SERVER_BENCHMARK.md). The all-cases
Go target is **NOT_MET**.

Evidence root: `/home/coder/Asyncara-server-evidence-20261109T232945Z`.
Tests: `tests/server/run.py`, `tests/server/workers.py`.
Matched benchmark: `benchmarks/server/compare.py`; both servers perform real
routing/query extraction and JSON response generation for every request. The Go
reference uses an immutable prewarmed payload cache, fixing the unsafe concurrent
map writes in the supplied example. No cached full HTTP replies are substituted.

Connection reuse now keeps one PHP handler Fiber per HTTP/1 connection; it does
not cache handler results. A single-slot inbox preserves HTTP/1 request ordering.
Connection generation IDs distinguish fd reuse, and native close/cancel events
retire that Fiber. Context is cleared between requests. Handlers must explicitly
own/join any additional Tasks they spawn; automatic cleanup of arbitrary detached
application Tasks is not promised. Global variables and statics are not request
isolated. Server writes use `sendmsg` vectors; this avoids flattening head/body,
but is not a kernel zero-copy guarantee.

The configurable connection ceiling is 16381, reserving three slots from the
16384 global readiness limit. Other Asyncara I/O consumes that same global limit;
leave capacity for it. Rust payload limits and PHP's memory limit are separate.
Server notifications are coalesced until the event queue is drained. Overflow is
bounded by admitted connections; new acceptance stops until queued lifecycle
events are collected.

Matched PHP 8.3.36, 8.4.27, 8.5.12 NTS, PHP 8.5.12 DEBUG and GCC ASan/UBSan builds
passed the focused server protocol/API checks on epoll and poll at native
checkpoint `3a9a31d` (`matrix-r2/report.json`, 25 successful commands including
five builds). Gate A passed in 233.240 seconds, with 47 expected command results.
Scoped C/PHP
sanitizer success does not mean the Rust library was compiler-instrumented with
ASan or that arbitrary application handlers are nonblocking.

Focused protocol tests prove two timer ticks while an independently held HTTP
request remains pending, 32 concurrent handlers, chunked uploads, pipelining,
HEAD, malformed Host/framing rejection, body/header/connection limits, a 2 MiB
response, timeout, disconnect cancellation, Context isolation, and native HTTP
client/filesystem/SQLite calls inside a handler. These are short tests, not
endurance certification. Ordinary PHP blocking calls still block their worker.

Public status: **PARTIALLY_IMPLEMENTED / EXPERIMENTAL**. The example's HTTP/1
features are implemented and tested; the omissions above and performance
acceptance remain distinct gates. The historical Phase4 zero-tick failure and
system-libcurl dependency findings are not resolved by this server work.

## Delivery review corrections

Checkpoint `195f088` fixes two independently reproduced defects in the initial
server. Persistent connection Fibers no longer capture the first request array:
all requests enter through the same single-slot Inbox. Eight completed512KiB
first bodies retained4,413,344 PHP bytes before the correction; the same live
connections retain179,936 bytes after it. Native payload accounting was already
zero, illustrating why the separate PHP ownership test is necessary.
`tests/server/body_lifetime.py` preserves this regression.

Rust Host validation now rejects userinfo and nonnumeric port suffixes. Generic
URI Authority parsing alone accepted `Host: user@localhost`; the original raw
request returned200 and is retained in `host-before/report.json`. Host has a
narrower grammar than a general URI authority ([RFC9110 section7.2](https://www.rfc-editor.org/rfc/rfc9110.html#section-7.2),
[RFC9112 section3.2](https://www.rfc-editor.org/rfc/rfc9112.html#section-3.2)).
Unit and real socket regressions cover this boundary. This is not a claim of
complete HTTP conformance or proxy interoperability certification.

At `195f088`, the expanded matrix passes35 commands (five builds, API/protocol/
body-lifetime cases on both backends). Gate A passes333.776514s. Complete PHP85
suites pass again on epoll and poll, as do four-worker normal/abnormal teardown
and the native interrupted-wait negative control. DEBUG Valgrind with actual
512KiB request bodies and live connections reports0 errors/definite/indirect/
possible loss;4066 bytes remain reachable in the dynamic-loader scope. Rust
server unit tests and the Host-parser-only control both report48 possibly lost
bytes from `std::thread::current::init_current`, with0 definite/indirect loss.
All raw reports remain available through [validation evidence](phase7/server-validation.json).
These scoped controls do not settle the historical distribution-PHP discrepancy
or the separate system-libcurl finding. Native `asyncara_server_*` functions
are internal bridge operations; `App` is the public lifecycle owner.

The final ten-combination protocol repetition also verifies that the fiftieth
keep-alive response carries Connection: close and reaches EOF.

## Source package

Server source archive: `build/asyncara-0.1.0-dev-server-r1.tar.gz`,557 files,
packaged commit `b1dc5b21bd5b175ddb33077a267b73f2684ad553`, native `195f088`.
SHA-256: `6839e7cac6ef6e6526389fca00db7640e17c816335850d1826bea25c8cec7dbb`.
Byte-reproducible and Git-tree matched; a fresh extracted Rust/C PHP85 build passes
server protocol, body lifetime and API tests on epoll/poll.
[Independent build attestation](phase7/server-r1-package.json). Not published;
performance acceptance remains NOT_MET and release remains EXPERIMENTAL.


## HTTPS and streamed responses (2026-11-10 continuation)

```php
use Asyncara\Server\Response;
use Asyncara\IO\Files;
$app->get('/file', fn() => Response::stream(
    Files::readStream(__DIR__ . '/data.bin'),
    200, ['content-type' => 'application/octet-stream']
));
$app->listen('127.0.0.1:8443', [
    'tls' => ['certificate' => __DIR__.'/chain.pem', 'privateKey' => __DIR__.'/key.pem'],
    'responseBytes' => 16777216,
]);
```

The opt-in Rust build now includes rustls0.23.46 with the AWS-LC provider and
TLS1.2/1.3. ALPN only advertises HTTP/1.1; a client offering exclusively h2 is
rejected. Clients offering no ALPN can use HTTP/1.1. There is one configured
certificate chain/key per listener; no SNI-based certificate routing or live
certificate rotation. Optional `tls.clientCa` requires a verified client
certificate from that PEM trust store. There is no verification-disable option.
Session tickets, resumption cache, early data and TLS renegotiation are disabled
or unavailable. This does not add HTTPS to the separate Hyper **client** backend.

PEM files are local, limited to256KiB each, read during listener setup before
entering the event loop; PHP enforces open_basedir. Encrypted private keys and
remote stream wrappers are unsupported. Do not log private keys. The PHP setup
frame releases its PEM strings after native configuration. Rustls/AWS-LC own the
parsed key material. Prefork children configure their own transport after fork.

Handshake, ciphertext reads and writes use the same connection-owned waker and
real nonblocking sockets as the existing reactor. No Tokio executor or TLS worker
pool is created. Handshake/output loops have a16-iteration dispatch bound and
yield when not ready. The existing cycle deadline includes the handshake.
`set_buffer_limit(65536)` bounds rustls application-data output; it is not a bound
on all handshake, key, certificate, parser, PHP or kernel allocations. These
allocations are outside Asyncara's payload ledger. See the
[rustls connection documentation](https://docs.rs/rustls/0.23.46/rustls/server/struct.ServerConnection.html).

`Response::stream(iterable $chunks, int $status=200, array $headers=[])` returns a
non-copyable, single-use `Server\StreamResponse`. A producer yields strings of
1..65536 bytes (empty strings are skipped). Hyper requests data through a bounded
native event, App resumes the owning Fiber, and one chunk is supplied. The
producer can suspend using explicit Asyncara APIs. Iterable advancement starts
on demand; HEAD never iterates the body. An Asyncara ReadableStream may already
have an eager producer Task before it is passed in: HEAD, rejected headers and
connection cancellation close and join that owned producer. Transport buffering and kernel send buffers can
accept several chunks before backpressure reaches the producer: yield completion
does not mean the remote application consumed those bytes.

`responseBytes` bounds the total generated body (default8MiB, maximum16MiB),
`bufferBytes` bounds Asyncara-owned payload, and at most one chunk occupies the
native producer slot. Hyper/TLS buffers and application-held input are additional.
HTTP/1.1 uses chunked framing; framing headers remain server-owned. Response
trailers, unbounded bodies and HTTP/2 are not advertised.
A producer failure or limit violation after headers closes that connection;
it cannot replace an already-started response with500. Disconnect/deadline cancels
the handler and releases its producer. External references to the producer remain
the application's responsibility. StreamResponse cannot be reused or cloned.
Ordinary buffered Response remains a copyable value.

Tests `tests/server/tls.py` and `tests/server/stream.py` use an independent Python
TLS/socket peer, a private CA, two ticks while input/output remains independently
held, malformed trust/identity/authentication controls, slow-reader backpressure,
16MiB transfers through a256KiB payload budget, producer failure and cleanup.
Validation status and remaining project-wide partial work are recorded in
[COMPLETION_STATUS.md](COMPLETION_STATUS.md). Earlier package/test paragraphs above
remain historical evidence for server-r1, not tests of this continuation.

The TLS/streaming continuation is packaged separately as server-r2 from
`3991adc2b02b46f1525495cefde9c743d9c4e7a3`; 575 files, SHA256
`d06985c353f62a767d33e094baa57508a9b214c692836b23b59cdf93d7be76bd`.
Its independent clean build and 13 checks pass on PHP85 epoll/poll.
[Package attestation](phase7/server-r2-package.json). Server-r1 above is unchanged.

## Streaming incoming requests

`listen(..., ['streamRequests' => true])` dispatches validated request headers
before collecting the whole body. The default remains buffered, preserving
`Request::$body`. In the opt-in mode `body` is an empty string; use `stream()` or
`json()` explicitly. `json()` collects the bounded body on demand, calls ext-json,
and caches the bytes for repeated decoding. It is not a streaming JSON parser.

```php
$app->post('/upload', function (Request $request) {
    $hash = hash_init('sha256');
    $size = 0;
    foreach ($request->stream() as $chunk) {
        hash_update($hash, $chunk);
        $size += strlen($chunk);
        // Explicit async I/O can suspend here without reading the next chunk.
    }
    return Response::json(['bytes' => $size, 'sha256' => hash_final($hash)]);
});
$app->listen('127.0.0.1:8080', [
    'streamRequests' => true,
    'bodyBytes' => 8388608,
    'bufferBytes' => 262144,
]);
```

The native service Future owns Hyper Incoming; PHP's single-use,
non-copyable RequestBodyStream requests at most one 1..65536-byte event at a time.
No new producer Task, executor, thread or whole-body buffer is introduced.
When the handler awaits another operation, incoming application-data polling
stops until it requests its next chunk. Hyper, TLS and kernel buffers may still
hold additional bytes; bufferBytes is the owned payload budget, not total RSS.
Total input is limited by bodyBytes even when chunked Content-Length is unknown.

Only the current request handler may consume or close input. Assignment and a
Request metadata clone share that canonical input; cloning/serializing the input
is rejected. Do not hand it to a child Task or Files::writeStream (which owns a
producer Task); instead await File::write for each chunk in the handler. Input
is closed when the handler returns, throws, is cancelled, or finishes iteration.
Close stops further consumption and releases PHP Task/Inbox references. The
native service still owns Incoming until the reply or connection teardown; it
does not force-drain a rejected upload. This is a **half-duplex** API: finish input
before returning a streamed response. A response generator cannot continue
reading that request body after the handler returns.

A fully consumed valid body permits keep-alive. An early response, malformed or
oversized body closes the connection, preventing unread body bytes from becoming
a subsequent request. Parsed body failures map to400,413 or503 when a reply is
still possible; a peer/parser disconnect can instead terminate the transport.
Trailers are consumed by Hyper but not exposed to PHP. Cancellation and the
existing cycle deadline cannot retract already-read bytes. Timeout semantics
and all default limits are unchanged.

`tests/server/input.py` uses independent real peers to hold body bytes pending,
verify two timer ticks before release, transfer8MiB through256KiB payload budget,
exercise slow consumption, chunked limits, malformed input, early rejection,
foreign-consumer rejection, repeated JSON decoding and zero final runtime counts.
It runs against plain/TLS epoll/poll. Detailed results and qualified sanitizer
failures are recorded in the request-stream continuation evidence, separately
from server-r2.

## Verified request-stream source package

Source: `f9c6f96b9f94a39cb4db834bd9be4640eb112fdc`. Archive: `build/asyncara-0.1.0-dev-server-r3.tar.gz`,
581files, SHA256 `7d4ac74d7ee36c8d39ac620312d0a3aaaa69f17b8a36789e163a71660146e17c`.
A second archive is byte-identical; all files match the source Git tree. A fresh
extraction, fresh Cargo target and native build pass17 independent checks,
including input/output streaming, TLS, protocol, API and body ownership on
both backends. [Attestation](phase7/server-r3-package.json).
Independent module SHA256: `2afda8b4316a4191ad8b9ec2d450986e854921ab2032e6d654afa4f146b2374a`.
The earlier ASan slow-input failure remains qualified above; packaging does not
erase it. No release publication or new performance acceptance.

## Independent source package (2026-11-11)

`asyncara-0.1.0-dev-filesystem-admission-r1.tar.gz` contains **691 files** from
`45d574c1802311894ca72c32273e25d731106092`. SHA-256:
`a9621c87c19abd523cd55914d1360c36f6357f646c00e88b9d0234eb69418fc0`.
Two independently created archives are byte-identical; every packaged file was
compared with the Git tree. A fresh Cargo/C build in an extracted directory
completed in 152.702s; all 25 build/validation commands passed in
202.659s combined command time. This was a new binary, not a copied module.

Independent module SHA-256:
`08688e337273a8831f890cd84034f915f9c6799b0d5419d67081e93d7beefe19`.
Tests cover filesystem/path policy, atomic cleanup, curl/Hyper file transfers,
TCP/Redis trust paths and Native Server HTTP/1.1 protocol, TLS/mTLS, response and
request streaming, and four TLS workers with streamed input, on epoll and poll.
The server fixture includes HTTP client, file and SQLite calls inside handlers,
timer progress before independent release, disconnect cancellation and cleanup.
FPM compatibility remains retained but no further FPM run was added to this
package verification, following the owner priority decision. Historical FPM
failures remain unresolved; these server passes are not FPM or endurance passes.

[Build and raw server attestations](phase7/filesystem-admission-package.json).
The archive does not include the later documentation-only package attestation.
No GitHub release has been published. Release remains EXPERIMENTAL.
