# Asyncara HTTP server — experimental development

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
in, and append `4` to the example command. `pcntl` is also required. The normal
C-only/phpize build does not include the server; this experimental server uses
the explicit `build-hyper-local.sh` path.

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
replacement. Linux only. HTTPS termination, HTTP/2, HTTP/3, WebSocket upgrades,
streaming request/response APIs and production deployment certification are not
implemented by this server.

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
JSON decoding uses PHP ext-json's C parser. Request bodies and response bodies are
bounded and buffered; this API does not promise streaming.

`Request::param(name, default)` reads a named route parameter. `query(name,
default)` decodes flat query names/values lazily; repeated names use the last
value, and PHP-style nested query arrays are not synthesized. `header(name,
default)` is case-insensitive and joins repeated values with commas. `json()`
decodes to arrays by default and throws for malformed JSON; App maps that error
to 400. Request properties `method`, `target`, `path`, and `body` are readonly.
Routing supports origin-form request targets, not a forward-proxy API.

Middleware has the signature `(Request $request, callable $next): Response`.
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
