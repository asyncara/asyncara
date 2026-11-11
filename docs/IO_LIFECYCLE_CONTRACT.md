# I/O lifecycle contract

## Isolated native development qualification (2026-11-07)

The original `phase5-native-core` checkpoint introduced a correction in
`ext/workers.c`: after native acceptance, `file_close` and `sqlite_close` are
mandatory cleanup operations. Their worker executes despite an operation
cancellation request and reports the actual close outcome, including errors.
Cancellation before a PHP Task submits its close remains unchanged. This does
not make an executing syscall forcibly interruptible or add a shutdown deadline.
See [NATIVE_CORE_ENGINEERING.md](NATIVE_CORE_ENGINEERING.md) for the source
analysis, preserved ownership invariants and prepared regression. This amendment
is not behavior of the frozen Phase 4 campaign binary. The subsequent isolated
fast-reliability track compiled and verified the correction, including failing
frozen-source controls and scoped memory tools; see [FAST_RELIABILITY.md](FAST_RELIABILITY.md).
This is not production certification.

This describes the implemented explicit APIs, including the Phase 4 buffered
HTTP cancellation drain and SQLite identity guards. It is a behavioral contract
with stated limits, not a claim of production certification. Asyncara remains
**EXPERIMENTAL**. See [NATIVE_API_FIRST.md](NATIVE_API_FIRST.md).

## Submission and completion

All pending awaits require the current scheduled Task Fiber. An already completed
Future can be read outside a run. Nested `Async::run()` is rejected.

| API | Start and owner | Completion |
|---|---|---|
| `Files::read/write/append/replaceAtomic/delete/rename/mkdir/rmdir/stat/entries/copy`, `Dns::resolve` | `Scheduler::native()` submits immediately, copying bounded inputs | Scheduler retains the native Future until collection |
| `Files::open`, `File` operations, `Files::exists/writeStream` | Immediately schedules a Task; native submission occurs when that Task runs and obtains its gate | File operations retain the gate through native completion |
| `HttpClient::request/get/post` | Immediate native submission when a permit is available; otherwise an owned Task waits in the existing FIFO admission queue | The returned Future owns the permit and former Task budget through result conversion or cancellation drain |
| HTTP response/file producer streams | Producer Task scheduled on construction; bounded queues may suspend it before any consumer awaits | Explicit `close()` cancels and joins the producer; iteration is single-consumer |
| SQLite | Tasks serialize connection access; native work starts after admission | Worker completion is drained before releasing the operation gate |
| PostgreSQL/MySQL | Tasks admit work through connection gates, then drive native library states | Readiness/deadline waits suspend Fibers; PHP converts bounded results |
| Redis | Task drives a serialized command/pipeline over TCP/TLS | Parser reads every pipeline reply or closes the connection on transport/protocol failure |

These are eager scheduling/submission semantics, not lazy execution on `await`.
Scheduling does not promise that the external operation has started before the
method returns. Filesystem path validation and request-body encoding can happen
synchronously before waiting.

`ext/workers.c:ar_job_new()` assigns increasing operation IDs within the native
request engine. File and SQLite IDs use separate request-local counters. They
are not stable across requests and are not serializable resource identities.
PostgreSQL/MySQL instead use Zend resources; Redis uses a stream owner. There is
no universal exposed operation ID covering every driver.

`ar_done()` queues native completion under the engine mutex. Worker completion
signals the wake pipe (including EINTR retry). Interpreter-thread HTTP completion
needs no pipe write: the normal poll/collect path observes its completed queue. `ar_collect()` detaches completed jobs, releases busy flags, and decrements
pending accounting. `asyncara_poll()` converts results on the PHP thread;
`Scheduler::poll()` removes each ID before settling its Future. Worker jobs have
one completion owner; HTTP cancellation checks `done` before queuing completion.
`Future::settle()` rejects a second settlement.

## Waiting, abandonment and result ownership

Identity-bearing owners reject cloning and serialization. Assignment shares
the canonical owner; it does not duplicate its native handle, admission count
or close authority. SQL Statement descriptors, Emitter, CancellationSource and
the PSR adapter may be cloned as aliases to their existing canonical owners;
they also reject serialization. Value-only responses/messages remain copyable.
The complete policy matrix and tests are in
[NATIVE_OWNERSHIP_AUDIT.md](NATIVE_OWNERSHIP_AUDIT.md).

`TcpStream` also rejects constructing a second wrapper around an already-owned
PHP stream. Its weak registration does not retain an abandoned owner. The raw
`resource()` escape hatch does not authorize independent ownership or external
`fclose`; application code bypassing the wrapper can invalidate its resource.

* `Future::await()` can be called repeatedly and by multiple Tasks. It returns
  the stored PHP value or throws the stored exception; it does not repeat I/O.
  Object results retain object identity rather than being deep-copied.
* A cancellation token is checked even when the Future has already completed.
  `result()` reads an already terminal value without awaiting.
* Cancelling one `await($token)` removes that wait subscription. It does not
  cancel a shared operation. The lifecycle regression exercises two waiters on
  one HTTP producer, then repeated reads of the completed result.
* Unawaited native work remains in the scheduler's native map and is drained by
  `Async::run()`. Dropping a variable does not undo submission. A plain native
  Future's unobserved rejection is not automatically rethrown by the scheduler;
  applications must await operations whose errors matter. Unobserved Task
  failures have separate scheduler failure reporting. Buffered HTTP uses an owned
  completion Future with the same unobserved-failure reporting and admission
  budget as its former per-request Task; it is not a plain unowned native Future.
* Last user-reference loss is not last native-owner loss. File/SQLite handles
  remain request-owned until explicit close or request teardown. A stream's
  producer can retain its stream through the scheduler; call `close()` to join
  early abandonment. No universal reference-counted cancellation is provided.
* Native strings/rows/buffers belong to the native job until PHP conversion and
  job release. PHP values then belong to the Future/consumer and normal PHP GC.
  PGresult and notification ownership remains recorded in the native resource
  during conversion so exceptional request teardown can release it.
* Native allocation tracking includes collected-but-not-freed jobs. Request
  bailout cannot make that ownership disappear merely by unwinding a C frame.

Sources: `Future.php`, `Task.php`, `Scheduler.php`, `ext/php_asyncara.c`,
`ext/workers.c`, and `ext/postgresql.c`.

## Cancellation: distinct actions

`Task::cancel()` requests cooperative cancellation. It is observed at supported
suspension/admission points, not by preempting arbitrary PHP or C code.

`Future::cancel()` invokes its operation canceller when one exists. It does **not**
itself mean the Future is terminal. A manually created Future with no canceller
has no cancellation action. Completion racing cancellation can win.

**Ownership rule:** shared consumers use waiter cancellation, not operation-wide
`cancel()`. `Future::cancel()` does not implement per-consumer ownership or
reference-counted cancellation authority; anyone holding the same operation
Future can request cancellation for all consumers. That is an explicit API
limitation, not an automatic safe-sharing guarantee. `Async::await($future)` is
for an **owned** operation: if interrupted, it cancels and shield-joins unfinished
work. Do not use that ownership helper for shared work.

| Driver/resource | Underlying action and completion boundary |
|---|---|
| Buffered HTTP | Remove active curl handle, queue cancellation, collect native result before releasing the owned Future or queued Task/permit |
| HTTP response/upload streams | Cancel transfer and owned producer/pump, shield-join their completion and close owned readable sources |
| File handles | Flag worker cancellation; retain per-handle gate through native completion; check Task cancellation before returning |
| Whole-file/path operations | Flag native job; executing syscalls may finish. Await/drain before assuming resources have been released |
| SQLite query | Progress handler observes atomic cancellation; shield-drain before allowing the next statement. Busy handling can delay observation |
| PostgreSQL | Local cancellation/error during active query closes the connection and releases native results; no server cancellation acknowledgement |
| MySQL/MariaDB | Local cancellation/error during active query closes the connection; no confirmed server-side query termination |
| Redis | In-flight pipeline failure/cancellation closes transport rather than reusing an unread response sequence. Cancellation before admission need not close it |

Cancellation cannot undo bytes sent, writes completed, a rename already
published, or a database commit accepted remotely. Closing a SQL connection is
not acknowledgement that its last statement stopped. No `PQcancel` acknowledgement
or MySQL kill-query protocol is implemented. After ambiguous external effects,
application-level reconciliation may be required.

## Timeouts

`Async::timeout()` applies a monotonic Task deadline, requests cancellation, and
joins Task cleanup. Its return can occur **after** the deadline when shielded
cleanup/native work must drain. It is not a hard wall-time execution bound.

| Timeout kind | Current behavior |
|---|---|
| HTTP transfer total | Client timeout passed to curl after client admission; includes network transfer and paused streaming time |
| HTTP admission | No separate client queue timeout; wrap the owned call in `Async::timeout()` when a queue-inclusive deadline is needed |
| TCP/TLS connect | Explicit `TcpStream::connect()` deadline covers DNS/connect/handshake Task work |
| PG/MySQL connect/query | Driver Task deadline includes connection-operation admission; supported native socket states suspend the Fiber |
| Redis command/pipeline | Driver deadline wraps gate acquisition and pipeline I/O; `nextMessage()` has no implicit per-message idle deadline |
| SQLite busy | Native 1,000 ms busy timeout is a locking policy, not a general query execution deadline |
| Filesystem worker execution | No forcible syscall timeout; Task/owned-operation deadlines request cancellation and may wait for completion |
| Idle timeout | No universal idle-timeout contract across these clients; use explicit Task deadlines where appropriate |
| Shutdown | Worker joins do not have a safe hard interruption deadline. FPM process termination is a separate deployment action |

No timeout guarantees rollback of an already executing native or remote action.

## Partial I/O and errors

* `File::read(n)` returns up to `n` bytes; `''` means EOF for these regular-file
  handles. `File::write()` retries partial writes/EINTR until complete or failure.
  A failed/cancelled write can already have modified a prefix; it returns no
  fabricated successful byte count. `File::seek/stat/sync` execute in workers.
* `Files::read()` buffers the full permitted file; use `readStream()` for large
  files. Stream defaults are 64 KiB chunks and a two-item producer queue.
  `writeStream()` waits for each chunk write; arbitrary supplied iterables can
  perform blocking application work and are not made asynchronous automatically.
* `replaceAtomic()` creates an exclusive temporary file in a pinned destination
  directory, publishes via same-directory rename, and removes unpublished
  temporaries. Optional durability calls fsync on file and directory. Atomic
  visibility differs from crash durability; filesystem/hardware semantics still
  apply. A post-publication durability error carries `FilesystemException`'s
  committed flag. `rename()` does not copy across filesystems to simulate atomicity.
* `File::sync()` requests fsync. There is no userland buffered-file flush API;
  successful write/close alone does not claim crash durability.
* Filesystem OS failures carry typed errno where available. `exists()` returns
  false for missing paths, and propagates other errors. Path capture preserves
  working-directory semantics and checks `open_basedir` on the PHP thread; this
  synchronous check and TOCTOU limitations preclude a universal non-blocking or
  hostile-filesystem sandbox claim.
* Buffered HTTP throws on curl transport/partial-transfer errors and returns a
  `Response` for HTTP error status codes. Streams may yield a prefix before an
  error. Downloads currently can leave a partial/truncated destination; they do
  not provide atomic download. HTTP/1.1 streaming yields encoded response bytes;
  buffered requests enable curl decoding. Redirect replay is disabled for streamed
  uploads. Content-length validation and malformed/truncated responses have
  existing local fixture tests, not universal proxy/protocol certification.
* SQL results are buffered bounded arrays, not row streams. SQL/transport errors
  throw; PG/MySQL close on active-query failure, SQLite can remain reusable after
  statement failure. `prepare()` retains a SQL descriptor; there is no persistent
  server statement cache. Redis pipeline server errors are values in the returned
  array; `command()` throws its individual server error. Transport/protocol errors
  do not return a fabricated complete pipeline.

## Transactions and resource affinity

SQLite manual `beginTransaction/commit/rollback` associate ownership with the
calling Task. Queries from that owner still serialize through the operation
gate; other Tasks wait on the transaction gate. Nested manual transactions are
rejected. Always finish a manual transaction in `finally`; abandoning one is not
an implicit rollback/connection-reuse guarantee. The callback transaction helper
performs shielded commit/rollback cleanup.

PostgreSQL/MySQL currently expose callback transactions with owner/gate affinity.
Consistent manual state APIs, savepoint helpers and prepared caches remain gaps.
Do not issue ad hoc `BEGIN` and assume the callback ownership machinery applies.
Redis MULTI/EXEC pipelines retain Redis semantics, not SQL transaction semantics.

`IO\File` and `SQLite\Connection` reject cloning/serialization of native IDs.
Other connection objects must also be treated as owned live resources, not value
objects; equivalent copy guards are not yet uniformly enforced across drivers.
The common SQL factory chooses SQLite/PG/MySQL; Redis remains separate.

## Backpressure and memory boundaries

The authoritative limits are in [RESOURCE_LIMITS.md](RESOURCE_LIMITS.md). Defaults
include 20,000 Tasks, 40,000 pending Futures per request, 1,024 native operations,
four workers, 256 MiB tracked native memory, 16 MiB buffered HTTP bodies and
PG/MySQL result budgets (SQLite additionally limits individual values/SQL),
64 KiB HTTP stream/upload buffers, 128 file handles and 128 SQLite connections.
Client permits and connection-pool leases impose their own independent bounds.
Producer queues suspend rather than silently discard application data.

Curl, SQLite, libpq, Connector/C, OpenSSL, PHP heaps, kernel buffers, native thread
stacks and child processes are not all covered by the native allocation ledger.
Foreign allocations can precede result-bound checks. Configured pool bounds do
not cap arbitrary separately constructed connections/pools. PHP memory limits do
not constitute process RSS limits. See existing notification/pipeline overflow
policies; no silent dropping of SQL or Redis messages is promised.

## Request-safe cleanup

| Owner | Final cleanup responsibility |
|---|---|
| PHP Task/Fiber | `finally`, owned-operation join, structured group completion |
| Scheduler | Wait unsubscription, timer removal, native Future map collection; cancel/drain on exceptional run exit |
| Native HTTP/file/SQLite/DNS operation | Request engine owns job/allocation list and completion queue; workers use copied C data only |
| File/SQLite handle | Explicit serialized close for timely release; request engine closes remaining handles after worker joins |
| PG/MySQL resource | PHP connection close and Zend resource destructor retain/free native results and connection state |
| Redis/TCP resource | Explicit stream close wakes registered waiters; request resource teardown closes remaining streams |
| FPM request | RINIT clears engine pointer/hooks; RSHUTDOWN disables hooks, joins workers, releases engine and clears pointer |

Ordinary exceptions, cancellation and timeouts run supported cooperative cleanup.
Request fatal memory exhaustion cannot resume the failed application. Tested soft
OOM paths retain native allocations for request teardown; PHP hard-limit process
termination, SIGKILL, crashes or machine failure can bypass PHP/RSHUTDOWN entirely.
The OS then reclaims process resources, without application rollback or durability
guarantees. Graceful recycling waits for request exit; forced replacement does
not run every cleanup callback.

Shutdown joins and synchronous setup/destruction may block. An in-flight blocking
filesystem/resolver call cannot safely be forcibly interrupted. A successful
functional teardown test is not a bounded-shutdown proof for arbitrary devices,
filesystems, servers or third-party extensions. Context isolates explicit task
values, not globals, static properties or all extension state within a request.

## Executable evidence and remaining gate

* `tests/io_lifecycle.php`: ownership-copy guards, terminal HTTP cancellation
  drain, shared waiter cancellation, repeated results, abandoned file submission,
  SQLite timeout/drain/reuse, and early bounded file-stream close.
* `tests/audit/regressions.php`, `tests/audit/slow_files.php`, `tests/audit/io.php`:
  scheduler teardown/transaction/pool regressions and deliberately delayed I/O.
* `tests/file_handles.php`, `tests/filesystem_atomic.php`,
  `tests/filesystem_buffer_ownership.php`: descriptor ordering, typed failures,
  atomic replacement and native result ownership.
* `tests/http_streaming.php`, `tests/databases.php`, `tests/database_tls.php`,
  `tests/database/resource_bounds.php`: existing protocol/cancellation/limit tests.
* `tests/audit/fpm_request.php`, `scripts/audit-fpm.py`, `tests/soak/fpm.py`:
  original request/isolation/progress/idle-resource assertions remain unchanged.
* `tests/soak/fpm_progress_control.py`: independent gated response and deliberate
  off-CPU controls; diagnostics only. Historical local FPM zero-tick failures
  remain unresolved, even when this control or instrumented runs pass.

Actual executions, binary identities and remaining certification limits are
recorded in [PHASE4_DELIVERY.md](PHASE4_DELIVERY.md) and
[FPM_LIFECYCLE.md](FPM_LIFECYCLE.md). No new HTTP/2/3, hook, io_uring, remote TLS,
framework or userland ThreadPool support is implied by this contract.

## Native reliability continuation

The isolated native track now preserves the requested `ar_wait` timeout budget
through readiness preparation using a monotonic absolute deadline. This bounds
additional *requested kernel sleep*, not PHP execution, host scheduling delay,
foreign-library computation or a hard end-to-end latency guarantee. Integer
rounding is upward to milliseconds; expired budgets request an immediate poll.

SQLite result conversion can allocate inside SQLite itself. Column-name and
value conversion failures now reject the operation and finalize the statement
instead of dereferencing NULL or returning false empty data. SQL NULL and valid
empty values retain their existing meaning. This changes error handling, not
the worker-backed model, transaction ordering or cancellation authority.
See [the scoped ownership matrix](NATIVE_OWNERSHIP_AUDIT.md) and
[executed native reliability evidence](NATIVE_RELIABILITY_PROGRAM.md).


## HTTP completion checkpoint: publication and handle reuse

`multipartFiles()` opens each owned file source only when its turn starts and
closes it on completion, failure or cancellation. The native MIME callbacks share
one 64 KiB upload slot; no Zend callbacks run inside curl. Upload cancellation
cannot undo server-visible bytes. Redirect replay is unavailable for streamed
bodies. Curl multi submission is eager at Task admission; newly admitted handles
are kicked together at the next normal scheduler poll. No await is needed to
start an admitted task.

`download(atomic: true)` owns a worker-created `File::stage()` handle, pins its
parent directory, writes bounded chunks, and calls `File::publish()` only after
the complete response. `close()` discards unpublished temporary files. Accepted
close remains mandatory even if the waiter cancels. Request shutdown joins
workers before closing staged files/directories. This does not recover fatal PHP
requests or guarantee cleanup after SIGKILL/host failure. A failed unlink is an
error, not a promise that the temporary entry disappeared.

Publication changes namespace visibility atomically within the pinned directory;
it does not preserve old file permissions (new mode 0600), undo publication on
late cancellation, or guarantee crash durability by itself. Durable publication
fsyncs data before rename and the parent directory afterward. Failure after
rename retains `committed=true` in the filesystem exception. `publish()` does not
close the underlying file descriptor; it may still be read/written until close.
Repeated publication is rejected, repeated public close is harmless.

Easy handles enter a request-owned bounded cache only after detachment/collection;
all callbacks/options reset before job buffers are freed. This cache is not a
cross-request pool. Source/result owners, cancellation authority and client
permits keep their existing rules. No timer hook is needed by any HTTP API.

Atomic download acquisition is shielded until its staged File is assigned to the
download owner. Only then is parent cancellation observed, so the finally block
can close the resource even when cancellation races the acquisition completion.
Generic `Async::await()` joins unfinished operations but cannot infer how to
dispose an already completed resource-valued result. For explicit resource
acquisition use `Async::using()` or shield acquisition and retain the object for
finally cleanup. `tests/http/acquire_cancel.php` proves the download boundary;
this is not a universal destructor/last-reference cancellation guarantee.


## Buffered HTTP dispatch optimization (2026-11-09)

An uncontended buffered request returns `HTTP\BufferedRequest`, a Future, rather
than allocating an extra Task/Fiber. Callers must rely on the public Future
contract, not an incidental Task subtype. Explicit cancellation requests native
cancellation and waits for collection before settling. Shared waiter cancellation
still detaches only that waiter. Success, error and submission-failure paths each
release admission exactly once. Operation and unobserved-failure counts retain
the existing task-limit protection. `tests/http/buffered_owner.php` verifies these
boundaries including abandoned results and root failure.

Completed native HTTP buffers stay on their registered job until conversion; no
generic native result tree duplicates them. The common four-option submission
view exists only during synchronous native setup and is cleared before returning
to Zend. It never reaches a worker or survives an await. Complex options retain
owned conversion. `tests/http/options_lifetime.php` checks referenced option
mutation, validation failure and rejected admission cleanup. Task Future waits
reuse observers with first-settler semantics; `tests/http/wait_observer.php`
checks exceptional reuse and independent shared consumers.

The opt-in [Hyper prototype](HYPER_EXPERIMENT.md) has a deliberately narrower
HTTP-only scope. It does not change the default curl contract or silently provide
TLS/proxy/streaming fallback. Its request-local Rust owners are dropped before C
job cleanup. Rust/Hyper internal allocation is outside the native buffer ledger.

## Request-local readiness cache (09b4767)

The engine owns its epoll descriptor from initialization through request teardown.
It never owns the watched application descriptor. Reuse requires an unchanged
descriptor vector and successful MOD revalidation of every descriptor; stale
registration or vector changes discard the instance. This includes close/reuse
while another descriptor retains the old open file description. The original
monotonic wait budget includes registration work. Cached control storage has its
own strict bound and `waitControlBytes` counter outside the payload ledger.
Poll and public cancellation/sharing semantics are unchanged. Exact failure and
cleanup evidence: [HTTP_EPOLL_REUSE.md](HTTP_EPOLL_REUSE.md).


## Optional Hyper fatal engine errors

The narrow Hyper prototype has a request-local engine shared by its admitted
buffered GET jobs. A caught Rust unwind poisons that whole engine, not merely the
current waiter. C drops Rust futures and sockets before publishing failures for
remaining jobs. A job already completed is not completed again; completion
counters include engine-wide failures. Fatal submission after retaining a C job
pointer follows the same teardown before that unadmitted job can be collected.
A subsequent request can create a fresh engine. This does not change ordinary
single-waiter cancellation ownership. Tests inject eight boundary failures on
epoll/poll and check exactly-once collection and zero tracked job/buffer ownership.
See HYPER_SAFETY_REVIEW.md for allocation-abort/destructor/unsafe-FFI exclusions.
TLS, decompression and streaming are not supplied by this prototype; default
curl lifecycle semantics still apply to those existing HTTP capabilities.

## Optional Hyper DNS ownership (922a024)

Domain requests use an owned Task and existing native DNS workers; numeric URLs
retain direct buffered submission. The canonical client permit spans resolution,
HTTP and cancellation collection. Sharing a Future does not transfer authority
from its request owner to one waiter. Unawaited owned Tasks still run.
Resolution consumes the total/connect budgets after admission. On cancellation
or deadline, running getaddrinfo must finish before its owner releases the permit;
the Event Loop remains available. A late result cannot start HTTP. This is a
logical deadline with drained cleanup, not forcible DNS-worker termination.
After the native result is collected, Rust owns a copy of1..16 validated endpoints.
Retries occur only before a request future starts, with one connect budget and
origin-specific pooling. No automatic replay after sending. DNS is not cached by
Asyncara and this is not Happy Eyeballs. Detailed tests/limits: HYPER_DNS.md.


## Server response streams and TLS

`App` remains the server lifecycle owner. `Server\StreamResponse` is a single-use,
non-copyable producer owner; assignment shares it. Hyper requests one chunk via a
native demand event, delivered to that connection's existing Inbox/Fiber. Cancelling
or losing that connection cancels its handler and releases the owned producer;
external producer references remain owned by their caller. Native payload charges
follow Bytes references until final transport release, not merely PHP yield.
Producer errors or total/chunk bound violations after response headers abort the
connection. No second error response is injected into an existing HTTP message.
HEAD does not evaluate the stream. Empty chunks do not generate an HTTP terminal
chunk; only producer completion does. Streamed responses cannot supply framing
headers or trailers. Total responseBytes and the existing cycle deadline apply.

TLS configuration is created before accepting connections. Each peer owns a
rustls connection and shares immutable server configuration. Existing nonblocking
socket/waker ownership remains canonical. The connection deadline includes the
handshake and paused streaming time; cancellation does not roll back transmitted
bytes. Setup PEM file reads happen before the runtime, not inside a callback.
The transport sends close_notify during ordinary shutdown; abrupt disconnect,
fatal process termination and expired deadlines may bypass graceful closure.

## Opt-in server input stream ownership

With App.streamRequests, Incoming is owned by the native service Future, and its
registry entry is Weak. One demand produces one <=64KiB PHP chunk; PHP copying
finishes before the native event is popped. A PHP allocation bailout leaves that
event owned by Rust until request teardown. Cancellation removes the connection,
drops the native service and cancels its PHP handler. No worker uses Zend state.
RequestBodyStream is non-copyable and handler-scoped. Rejecting a foreign waiter
must not consume, cancel or close the owner's input. Close releases its Task and
Inbox references, is idempotent, and stops consumption; native cleanup occurs on
reply/drop, without an implicit draining Task. Early responses force connection
closure. Input must finish before response streaming starts; duplex is unavailable.
`Request::json()` deliberately buffers bounded input; ordinary Request::$body
behavior is unchanged unless streamRequests was explicitly enabled. See SERVER.md.

## Hyper HTTPS request ownership

The optional Hyper backend supports verified buffered HTTPS GET using the same
HttpClient API. Its owned Task holds the client permit through CA-file loading,
DNS, network completion and cancellation drain. CA files and DNS use existing
workers; TLS and HTTP use the event-driven connection driver. One waiter may
cancel its wait without destroying the shared request owner. Explicit owner
cancellation closes its native connection before completion collection; it
cannot retract bytes already sent.

The total/connect budgets begin after permit admission and include CA loading
and DNS; the remaining connect budget includes the TLS handshake. Already-running
worker operations drain on cancellation. Each HttpClient retains its first trust
snapshot; a new client reloads changed file bytes. The native pool/cache is
PHP-request-local, not owned by an individual HttpClient. Reuse requires the
same origin, resolved endpoint and exact trust-policy identity. Sixteen cached
policies is a hard admission bound, not unlimited certificate rotation.
`tests/http/hyper_tls.php` independently holds body/handshake completion until
two timer ticks, tests cancellation/recovery and verifies trust isolation.
See [trust limits and unsupported features](HYPER_TLS.md).

## Hyper buffered request and flush ownership

The method/header/body view passed by C is borrowed only during synchronous
submission. Rust validates and copies it before returning. The C job and its
native-memory charge remain alive through the response and any still-pending
keep-alive upload flush. A complete final response is not proof that the request
body left local buffers. Sender channel readiness is also not a wire-flush
guarantee. The transport flush marker supplies that distinction; it does not
acknowledge remote application consumption.

An explicitly closing response cancels the remaining upload by dropping its
connection. A completed driver is dropped immediately to release Hyper's
dispatch receiver and notify pending sender waiters. No body replay or automatic
retry is introduced. Owner cancellation and deadlines remain active during the
post-response flush state; an unrelated waiter's cancellation does not acquire
that authority. Request teardown drops all request-local engine/pool owners.
See [reproductions and scoped tests](HYPER_REQUESTS.md).

## PostgreSQL opt-in prepared ownership continuation

The current optional cache is owned by the canonical Connection, not by its
Statement descriptors. One operation gate covers LRU eviction, native preparation,
result drain and execution; transaction affinity is unchanged. Statement clones
share that connection. Only a successful preparation result publishes an entry.
Normal owner cancellation/error closes the connection and clears its entries.
A waiter-only timeout leaves shared work alive; Async::await expresses ownership
and joins cancelled work. No server cancellation acknowledgement or automatic
replay is implied. Default cache capacity0 preserves unnamed-query behavior.
Exact bounds, server invalidation limitations and validation status are in
[POSTGRESQL_PREPARED.md](POSTGRESQL_PREPARED.md).

## Hyper stream ownership

The optional Hyper transport now follows the existing streaming client owner:
producer Task/permit -> request engine C job -> Rust transfer/connection driver.
C publishes complete headers and bounded body/upload availability before another
kernel wait. A paused Rust response Future owns its unaccepted Bytes until the
C slot is drained; an upload driver copies its borrowed C slot before returning
to PHP. Resume wakes the operation and canonical driver without invoking PHP
while Rust borrows the engine. Cancellation drops Rust owners before the C job
can be collected. File I/O still belongs to native workers. Shared header-waiter
cancellation does not authorize transfer cancellation; explicit stream close
cancels/joins its producer. Deadlines cover paused transfer time, and an unread
peer reset need not deliver a final HTTP response. See HTTP_STREAMING.md for
bounds, tests and distinctions from buffered request decoding and MIME.

## PostgreSQL client TLS verification (2026-11-11)

Explicit PostgreSQL sslcert/sslkey/sslpassword options belong to the existing
connection, with no new operation owner or global password callback. Setup still
uses nonblocking PQconnectPoll network waits; library key/trust-file reads and
crypto can block setup. Local cancellation closes the connection without remote
cancellation acknowledgement. The certificate-only lock test requires two timers
before an independent owner releases the query, and FPM tests verify request
cleanup. [Exact scope](POSTGRESQL_MTLS.md) includes ordinary unload loss.

## MySQL/MariaDB client TLS ownership (2026-11-11)

Native `94614a6` owns all nine copied option strings until connection resource
release; Connector/C owns separate library copies. certFile/keyFile must be
paired and keyPassword requires the pair. Strict verified TCP/IP-SAN checks and
PHP open_basedir prechecks apply; no interactive key prompt is permitted.
Trust-file reads and cryptography remain synchronous, external allocations are
not covered by the native ledger, and prechecks are not an atomic filesystem
sandbox. Local cancellation closes the connection without claiming remote query
acknowledgement. Shared-waiter cancellation leaves the owner query pending.
Tests hold a server lock until two timers dispatch, then inspect result/connection
cleanup and independent server sessions. [Exact evidence](MYSQL_MTLS.md) includes
four ordinary-unload DEBUG Valgrind controls with zero lost bytes, separately
from unresolved PostgreSQL/library and distribution-PHP findings.

## Explicit HTTP trust filename lifetime (2026-11-11)

HttpClient now captures explicit relative caFile at construction, including
buffered/queued/stream/upload paths. Native HTTP admission independently captures
its owned filename and checks PHP open_basedir before external curl access.
[Before/after and cleanup evidence](HTTP_TRUST_PATHS.md) covers both transports,
both backends and private-CA FPM. Filename capture is not a content/symlink
snapshot or atomic sandbox. Curl trust-file/crypto work remains library-owned;
Hyper retains bounded worker reads and its per-client trust byte cache. Workers
join at request shutdown, not immediately when a completed file Future is freed.

## Separate HTTPS proxy trust (2026-11-11)

The curl client accepts optional `proxyCaFile` with an explicit HTTPS proxy.
Proxy and origin certificate/hostname verification remain independent and enabled.
Both explicit trust filenames have owned absolute-path snapshots and native
open_basedir admission checks. No certificate-content/symlink snapshot or atomic
filesystem sandbox is promised. The existing network event loop, upload/file
workers, buffer bounds and cancellation owners are unchanged.
[Exact API, tested configurations, memory and FPM evidence](HTTPS_PROXY.md).
The optional Hyper transport still rejects proxies.
