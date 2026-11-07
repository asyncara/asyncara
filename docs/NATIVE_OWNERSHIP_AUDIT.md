# Native ownership audit — 2026-11-07

Scope: source review from `f2c0b83` through `9bee5c3`, focused negative controls,
existing lifecycle tests, and the final validation indexed in
[NATIVE_RELIABILITY_PROGRAM.md](NATIVE_RELIABILITY_PROGRAM.md). This is a scoped
ownership review, not universal safety certification. Scheduler objects, Tasks,
Fibers, Futures and timers are PHP objects; there is no second C Fiber engine.

| Resource | Creation / lifetime owner | Sharing / cancellation authority | Release / request shutdown | Coverage and remaining risk |
|---|---|---|---|---|
| Request engine | `ext/php_asyncara.c:get_engine`, request-local `engine`; `workers.c:ar_engine_new` | Only interpreter accesses Zend; workers hold engine until joined | RINIT clears pointer; RSHUTDOWN calls `ar_engine_free`, cancels jobs, joins workers, then frees graph | FPM isolation/fatal/pending cases, allocation failure harness; join cannot impose a deadline on an uninterruptible filesystem syscall |
| Native job/result | `ar_job_new` registers lifetime before validation/submission; `ar_submit` adds pending ownership | Atomic cancellation flag; caller holding operation ID can request cancellation. Cancellation does not revoke the worker's ownership | `ar_done` publishes once under mutex; `ar_collect` detaches, clears handle busy, removes pending ownership; result converted on interpreter then `ar_job_free`; lifetime list survives conversion bailout | Completion EINTR/EAGAIN, cancellation, allocation/fatal tests; internal API assumes a job is submitted/published only once |
| PHP Task/Fiber | Scheduler task table and ready queue own active Task; Task owns Fiber | Await cancellation removes a waiter. Task cancellation requests that Task's token; no forced native termination | Completion settles Future; scoped runtime drains; PHP GC owns cycles | Real two-tick barriers, seeded queue/cancellation tests; arbitrary PHP/native callbacks can still block |
| Future / TaskGroup | Future owns cached PHP result/error/listeners; group owns children until join | Multiple awaits supported. A waiter token does not own shared production. `Future::cancel()` delegates to operation's canceller; authority is not reference-counted | Settle clears canceller/listeners/pending WeakMap entry. Group joins/cancels siblings as documented | `io_lifecycle.php` shared-waiter and repeated await tests; arbitrary cloning/serialization of runtime internals is not certified |
| Timer | PHP `Scheduler` heap owns deadline/callback until expiry/removal | Task/token cleanup removes its scheduled wait; repeated Timer has explicit cancel | Cancel/expiry releases callback; runtime drains on exit | Virtual time uses same PHP heap; real kernel tests verify wait boundary separately. No claim of native C heap transitions |
| Readiness watcher | PHP scheduler owns stream-resource reference; C `ar_wait` owns transient poll/epoll arrays | One or more waiting operations; resource close calls scheduler `closed()` before fclose | Transient C arrays/epoll fd freed every wait; errors preserve errno | Duplicate fd registration, partial readiness, close/reuse, EINTR, timeout and clock-error tests; manually bypassing public close notification is outside the ownership contract |
| TCP / UDP / TLS | PHP stream resource owns OS fd; wrapper owns resource reference | Shallow wrapper copies can alias the same resource; closing an alias invalidates the shared endpoint | Explicit close and request resource teardown; PHP resource prevents independently freeing the same fd twice | Existing socket/TLS/UDP suites and fast close/reuse tests; wrapper copy semantics are not independently owned sockets and must not be advertised as such |
| Buffered HTTP | PHP request Task/client permit owns native HTTP job; curl multi belongs to request engine | Cancelling one awaiter does not cancel producer. Producer cancellation removes easy handle and drains completion | `ar_cancel` checks `!done`; Task retains permit until collection; job frees easy/mime/headers/body; engine frees multi | HTTP cancellation-collection regression, full HTTP and FPM; library/kernel allocations outside native ledger |
| HTTP streaming | ResponseStream/upload producer and bounded queues plus native paused buffers | Explicit stream close cancels and joins; consumer cancellation cannot undo network bytes | Curl pause/resume retains bytes until acknowledged; producer cleanup closes owned file/stream resources | Streaming/partial/cancel/full suite and FPM streaming. Early iterator exit requests cancel; explicit close is the join boundary |
| SQLite | `ar_db` request list owns sqlite handle; PHP Connection gate serializes work, transaction owner retains affinity | Connection clone/serialization rejected. Worker borrows handle and parameters until statement finalization; progress callback observes cancel | Accepted close cannot be skipped by cancellation. Collect frees closed handle; shutdown joins before sqlite close | `close_cancellation.c`, `io_lifecycle.php`, worker-volume checks. `sqlite_conversion.c` exposed unchecked SQLite allocation failures; `f470ea9` fixes allocation failures; `9bee5c3` preserves UTF-8 text conversion. Foreign SQLite allocations not fully charged |
| File handle / filesystem job | Request `ar_file` owns fd; PHP File gate holds operation through shielded completion | File clone/serialization rejected; accepted write may finish despite cancellation | Accepted close runs even if cancelled; collect frees closed handle; shutdown joins workers before closing remaining fds | File/atomic/error/stream/fatal tests; cancellation cannot roll back accepted writes; durability depends on filesystem/device |
| PostgreSQL | Zend resource registered before connection creation; resource holds PGconn, PGresult and PGnotify during PHP conversion | Connection gate/transaction affinity; local cancellation closes/drains as implemented, not server acknowledgement | `pg_close_handle` clears retained result/notification and connection; resource destructor on request teardown | Local database/notification/resource tests; external libpq buffers outside ledger, no universal remote cancellation acknowledgement |
| MySQL/MariaDB | Zend resource owns Connector/C handle, statement, binds and conversion buffers | Async start/continue runs on interpreter and waits readiness; connection gate serializes | Resource destructor closes connection/statement and frees binds. Connector source `ma_invalidate_stmts` clears MYSQL links before connection storage release | Source review rejects speculative double-close claim; local integration separately from prior Oracle evidence. Broader clone semantics and remote matrix not certified |
| Redis | PHP Connection owns nonblocking TcpStream, parser buffer, pending responses and bounded notification queues | Serialized pipeline/transaction transport; cancel closes shared connection where required | Explicit close rejects pending work; PHP resource/request teardown closes socket | RESP/limits/transaction/PubSub tests. Cluster/Sentinel/streaming aggregates unsupported; no universal isolated-waiter guarantee |
| ConnectionPool | PHP pool owns idle queue; leased counter includes queued acquisitions | Scoped borrower owns lease. close refuses outstanding borrowers | Factory/error/closer cleanup shielded; returned slot restored in finally | Existing queued-lease regression. Shallow cloning can share queue with copied counters; copying pools is outside audited usage and remains a policy/guard follow-up |
| ProcessPool | PHP pool owns proc_open resources and framed IPC; child owns its interpreter | Registry jobs only; cancellation can terminate owned child; distinct from worker threads | Pool close/reaping; OS releases dead child resources | Existing process/IPC/crash/limits tests. Kernel-uninterruptible child exit has no hard teardown guarantee; no C ProcessPool implementation claimed |
| Memory ledger | `ext/memory.c` engine-bound atomics, overflow-checked allocation headers | Workers allocate only native C storage, never zval or PHP callbacks | Free subtracts exact charged bytes; mapped growth charges both buffers; engine checks zero at teardown | Allocation injection/ASan/native volume checkpoints; excludes foreign heaps, kernel buffers, thread stacks, child processes and PHP allocator |

## Confirmed corrections and inspected failure paths

- `ar_wait` previously restarted its relative timeout after preparation. An
  absolute monotonic deadline now bounds that budget through the syscall
  boundary. Allocation/epoll preparation, rounding, overflow and clock failures
  have independent link-boundary regressions; wrappers are test-only.
- `do_sqlite` dereferenced a nullable column name and nullable converted value;
  byte conversion failure could be treated as empty data. Documented accessor
  OOM was injected into the real worker path: two SIGSEGVs and one wrong success
  before repair. The fix checks errors immediately, frees the partial row,
  finalizes the statement, and preserves subsequent connection use. Empty
  string/blob and SQL NULL are controls, not errors.
- The first SQLite candidate also exposed a semantic regression on UTF-16 databases. The preserved public regression fails on `f470ea9`; `9bee5c3` uses the UTF-8 text accessor for TEXT and the blob accessor for BLOB, retaining error checks. UTF-16le/be, multibyte text, embedded NUL and binary values now have explicit coverage.
- Existing accepted-close and completion-notification repairs remain covered.
  Worker publication ends all job access before collection may free the job;
  shutdown joins the publishing thread before freeing its engine/wake pipe.
- Partial engine initialization closes pipe ends and destroys only initialized
  mutex/condition resources. Partial thread creation retains the successfully
  created bounded set; shutdown joins that set. Submission failure leaves the
  job on its lifetime list until ordinary cleanup or request shutdown.
- PostgreSQL result pointers are attached to a Zend resource before conversion;
  PHP allocation bailout cannot silently detach their native ownership.
- PHP cyclic objects retained until process shutdown are a separate allocator
  finding. See [DEPENDENCY_FINDINGS.md](DEPENDENCY_FINDINGS.md); no production
  GC workaround or Valgrind suppression was added.

## Limits of the audit

Normal errors, cancellation, deadlines and request teardown are distinct from
SIGKILL, process crash, hard OS exhaustion or PHP's unrecoverable bailout. No
claim is made that user cleanup executes in those terminal cases. No foreign
allocator, arbitrary third-party extension, application callback, copy of a
runtime owner, or unsafe raw-native function use is universally certified.
Future work should define/reject copying of remaining connection/pool owners
before advertising independent cloned resources. This review does not hide
that gap behind passing ordinary-usage tests.

Diagnostic ownership was inspected separately from result ownership:
`asyncara_info` counts request-owned file/database list entries without reading
worker-mutated handles, locks the worker queue while counting queued jobs and
uses atomics for allocation counters. Native handle admission checks `busy`
before reading fd/SQLite state; collection's mutex acquire establishes completion
before clearing `busy`. Curl buffer fields are interpreter-owned. These are
source invariants, with scoped race tests; they do not instrument every external
library or make arbitrary simultaneous raw-native API access supported.
