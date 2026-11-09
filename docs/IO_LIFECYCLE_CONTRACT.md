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
| `HttpClient::request/get/post` | Immediately schedules an owned Task; submits after obtaining a client permit | Permit retained through result conversion or cancellation drain |
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

`ar_done()` queues native completion under the engine mutex and signals its wake
pipe. `ar_collect()` detaches completed jobs, releases busy flags, and decrements
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
  failures have separate scheduler failure reporting.
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
| Buffered HTTP | Remove active curl handle, queue cancellation, collect native result before releasing the public Task/permit |
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
