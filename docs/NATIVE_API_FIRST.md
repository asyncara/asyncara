# Native Async API First — Optional Transparent Compatibility

This is the architectural direction adopted for Phase 4 lifecycle hardening.
Asyncara remains **EXPERIMENTAL**. This decision preserves the existing runtime
and public methods; it is not a migration or a new implementation.

## Principle

**One capability, one canonical Async API, multiple interchangeable backends
where technically justified.** Asyncara owns comprehensive asynchronous APIs.
Applications opt into those APIs at their I/O boundaries. Ordinary PHP calls do
not become asynchronous automatically, and core functionality never requires
function interception.

| Capability | Existing canonical API | Actual execution model |
|---|---|---|
| HTTP | `HTTP\HttpClient`, response and response-stream types | Event-driven curl multi socket/timer integration; disk work uses file workers |
| Filesystem | `IO\Files`, `IO\File` | Four native pthread workers; serialized file handles |
| SQL factory | `Database\Database` | Selects an implemented driver; preserves driver-specific semantics |
| SQLite | `SQLite\Connection` | SQLite C API in bounded native workers; per-connection serialization |
| PostgreSQL | `PostgreSQL\Connection` | Non-blocking libpq state machine |
| MySQL/MariaDB | `MySQL\Connection` | Connector/C start/continue state machine; independently tested server families |
| Redis | `Redis\Connection` | Non-blocking TCP/TLS transport and bounded RESP parsing |
| TCP/UDP | `IO\TcpStream`, `IO\TcpServer`, `IO\UdpSocket` | Non-blocking sockets with readiness waits |
| DNS | `IO\Dns` | Native worker resolver; not an event-driven DNS protocol implementation |
| TLS | `IO\TcpStream::connect(tls: true)` | Non-blocking stream handshake/read/write; driver-specific TLS also exists |
| WebSocket | `WebSocket\Connection` | Framing over the existing TCP/TLS transports |
| PHP jobs | `Pool\ProcessPool` | Separate processes, bounded data-only IPC and registered callable descriptors |

Use the actual methods in [API.md](API.md). This table introduces no aliases.
Backend and protocol limitations remain in [BACKENDS.md](BACKENDS.md),
[COMPATIBILITY.md](COMPATIBILITY.md), and [ROADMAP.md](ROADMAP.md).

## Terminology and ownership

* A **Native Asyncara API** is maintained by Asyncara. It does not imply
  kernel-level asynchronous I/O.
* **Native non-blocking I/O** uses non-blocking OS/library operations plus
  readiness/deadline waits on the event loop.
* **Event-driven library integration** delegates network progress to a library
  such as curl multi and integrates its sockets and deadlines with the loop.
* **Worker-backed async** runs blocking C operations outside the interpreter
  thread. The caller's Fiber suspends; the workers never manipulate Zend state.
* **Multiprocess parallelism** runs PHP jobs in separate OS processes.

Submission, cancellation, draining, result ownership and request shutdown are
specified in [IO_LIFECYCLE_CONTRACT.md](IO_LIFECYCLE_CONTRACT.md). PHP callbacks,
conversion, protocol parsing and synchronous setup still consume interpreter
time; asynchronous waiting is not CPU preemption.

## Optional compatibility

Existing opt-in `sleep`/`usleep` hooks remain, including their documented signal
limitations. The explicit clients do not require cURL, PDO, MySQLi, ext-Redis,
stream or filesystem hooks. See [HOOK_COMPATIBILITY.md](HOOK_COMPATIBILITY.md).

Universal interception is not a goal. No new hook expansion is part of this
phase, absent an independently demonstrated critical compatibility defect.
Future adapters require concrete ecosystem value, safe Zend integration,
per-function compatibility tests and a truthful capability report.

## Backend abstraction and next gate

Keep backend details out of normal application control flow while exposing the
actual backend through runtime diagnostics. epoll and poll are implemented
alternatives at the native wait boundary. Filesystem operations currently have
one worker backend. io_uring filesystem operations remain **NOT_IMPLEMENTED**;
a NOP probe is not file I/O support. Do not introduce a backend registry before
a second real implementation justifies it.

The immediate gate is the unresolved local uninstrumented FPM responsiveness
failure and request-safe I/O ownership. Diagnostic passes, hosted results and
mixed CLI endurance cannot substitute for local FPM acceptance. Preserve the
existing APIs, limits, historical failures and acceptance thresholds.

## Native development continuation

The isolated Phase5 track retains this architecture. Native wait-budget and
SQLite conversion error repairs are documented in
[NATIVE_RELIABILITY_PROGRAM.md](NATIVE_RELIABILITY_PROGRAM.md). Developer feedback
now uses deterministic real-kernel tests plus operation-volume and short FPM
gates; the original local FPM failures remain unresolved historical evidence.
No public API, optional timer hook policy, filesystem backend or driver strategy
is replaced by this work.

Resource ownership and opt-in bounded provenance now extend the same APIs; see
[RESOURCE_PROVENANCE_PROGRAM.md](RESOURCE_PROVENANCE_PROGRAM.md). Neither tracing
nor optional timer hooks are required for native I/O. Diagnostics expose backend
metadata without creating an interchangeable filesystem backend.
