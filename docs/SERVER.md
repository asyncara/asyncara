# Asyncara HTTP server — experimental development

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

Early PHP 8.5 epoll/poll protocol checks and four-worker lifecycle checks pass.
The first worker shutdown test exposed EINTR being overwritten by accept(EAGAIN)
after native wait; its failure is preserved and wait errno is now saved.
Performance acceptance against Go fasthttp is NOT MET. Exploratory measurements
must not be used as a production throughput claim. Full matrix and deeper
resource/security validation are ongoing.

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
passed the focused server protocol/API checks on epoll and poll at checkpoint
80b1499. Later configuration-bound validation is tracked separately. Scoped C/PHP
sanitizer success does not mean the Rust library was compiler-instrumented with
ASan or that arbitrary application handlers are nonblocking.
