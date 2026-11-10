# Native ownership audit — 2026-11-07

The current per-object matrix and tested copy policies are below under
“Resource copy policy continuation”; the opening review records the historical
checkpoint and its then-open gaps. Current evidence is in
[RESOURCE_PROVENANCE_PROGRAM.md](RESOURCE_PROVENANCE_PROGRAM.md).

HTTP continuation `09b4767` changes the current epoll ownership boundary:
the request engine now owns its descriptor from engine initialization through
shutdown, plus bounded cached registration arrays. Watched resources remain
borrowed; every reused registration is revalidated. The opening historical
table below describes the earlier transient implementation. Current evidence:
[HTTP_EPOLL_REUSE.md](HTTP_EPOLL_REUSE.md).

Historical scope: source review from `f2c0b83` through `9bee5c3`, focused negative controls,
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

## Resource copy policy continuation (2026-11-07)

`tests/resource_copy.php` now rejects cloning and (un)serialization for mutable
identity owners: Scheduler, Future/Task, Timer, TaskGroup, CancellationToken,
Queue/Event/Lock/Semaphore, TCP stream/server, UDP, File, AsyncStream, HTTP client
and response stream, SQL/Redis connections, WebSocket connection, connection
pool and ProcessPool. Existing File/SQLite guards are retained. Assignment
shares the canonical object; it does not create a second owner.

Statement descriptors (SQLite/PostgreSQL/MySQL), Emitter, CancellationSource and
the PSR HTTP adapter permit shallow cloning: they retain the same canonical
connection/queue/token/client. They reject serialization. Responses, response
heads and WebSocket messages retain value-copy behavior. Context and the API
facades have static behavior, not owned native instance handles. TaskResult and
WaitResult are value containers whose object members remain shared references;
serialization of resource-bearing members is rejected transitively. FrameCodec
copies its value-only parser state; that does not clone a connection.

Negative controls on the unchanged source showed a cloned TcpStream destructor
closing the original descriptor and a cloned Event settling the same Future
twice. `Internal/NonCopyable` rejects cloning before a clone destructor can run.
The regression also checks forged unserialization, shared waiter cancellation,
pending file writes followed by ordered close, repeated close, canonical SQL
statement aliases, pool/stream owners and zero retained native handles/payloads.

Evidence: `/home/coder/Asyncara-resource-provenance-20261107T212909Z/`: retained
`copy-before`, `copy-negative`, six `copy-r3-*` PHP83/84/85 epoll/poll results and
`p0-gate-a-results/report.json`. Gate A passed; database-specific live connection
copy checks in `tests/databases.php` also passed against local PostgreSQL,
MariaDB and Redis on epoll/poll (`p1-database-*`). This closes the ordinary shallow-copy gap identified above; it
does not authorize manipulating raw descriptors or native IDs independently.

An abandoned eager operation remains owned by the scheduler/request until
collection. Dropping the last *user* reference therefore does not cancel it.
File/SQLite handle wrappers require explicit close; otherwise the native request
engine retains bounded handles until request shutdown. No last-reference
destructor rollback or hard shutdown deadline is promised.

## Current per-object identity and lifetime matrix

Policies are about ordinary public API use. `NON_COPYABLE` rejects clone and
(un)serialization while allowing assignment of the same object reference.
`SAFE_CLONE` below means an alias of a canonical owner, not an independent
native handle. It forbids serialization. Tests exercise both rejection and
continued operation/cleanup, not only exception type.

| Object/resource | Copy policy / sharing | Lifetime owner | Cancellation authority | Release / shutdown | Executable coverage and remaining risk |
|---|---|---|---|---|---|
| Scheduler | NON_COPYABLE; one active run | run scope, Task references | run cleanup cancels/drains children | PHP GC; native engine independently request-owned | resource_copy, kernel, virtual_time; arbitrary callbacks can block |
| Task / Fiber | NON_COPYABLE; multiple references/waiters | Scheduler until terminal; Task owns Fiber | own token; waiter token removes that waiter | completed removes scheduler entry; PHP cycles use GC | resource_copy, io_lifecycle, kernel; no preemption |
| Future / Awaitable | Future NON_COPYABLE; external Awaitable implementors define their own identity | producer/Scheduler/result holders | cancel delegates to producer; await(token) only cancels wait | collection settles once; result cached; request drains native ownership | resource_copy, io_lifecycle; no per-consumer operation authority |
| Timer | NON_COPYABLE | Scheduler heap and callback | explicit cancel/removal; repeated removal idempotent | remove or expiry; run drain cancels remaining callbacks | virtual_time, resource_copy; no C timer heap |
| TaskGroup | NON_COPYABLE; child references shared | group scope | group cancels siblings then joins | finish/drain, PHP GC | resource_copy, core TaskGroup tests; no separate native group object |
| CancellationToken / Source | Token NON_COPYABLE; Source SAFE_CLONE shares readonly token | token/source/task references | all Source aliases request the same cancellation | listener cleanup; PHP GC | resource_copy shared-token and cancelled/surviving waiter |
| Queue / Event | NON_COPYABLE | canonical primitive plus waiters | cancellation removes its pending get/put/wait | close/release waiters, PHP GC | resource_copy, synchronization tests; capacity0 explicitly unbounded |
| Lock / Semaphore | NON_COPYABLE | canonical counters and waiter list | token while acquiring; owner releases permit | finally releases; PHP GC | resource_copy, core synchronization; do not externally falsify permits |
| Context | static facade; values are ordinary shallow PHP values | Task context array | none | Task/GC/request teardown | existing context tests; globals/statics are not task-isolated |
| TcpStream | NON_COPYABLE; duplicate constructor rejected; weak registration | canonical wrapper/active method Task owns PHP stream | Task wait token; close invalidates all users | explicit close/destructor, wake waiters; PHP resource teardown | resource_copy, kernel, stream_readiness; raw resource escape can bypass ownership |
| TcpServer / UdpSocket | NON_COPYABLE | wrapper owns listening/datagram stream | wait cancellation; canonical close | repeated close/destructor/request resource teardown | resource_copy, UDP ownership, FPM; no cross-request object sharing |
| DNS | no separate resource object; Future identity policy | worker job + Scheduler | operation flag; resolver call may finish | collect/result free, request joins | native tests, slow DNS; not forcibly interruptible |
| WebSocket Connection | NON_COPYABLE; TcpStream is canonical transport | connection/parser and active operations | explicit close/Task cancellation | underlying stream closes; pending methods retain connection | resource_copy, protocol/interop suites; callers must not independently operate an attached transport |
| FrameCodec / Message | VALUE_COPY | independent string/scalar state | none | PHP GC | resource_copy and protocol tests; cloned parser does not clone a connection |
| HttpClient / permit | NON_COPYABLE; concurrent Tasks share one client | client Semaphore; request Task retains permit | owned Task cancellation drains native job | permit finally released; request-local curl multi freed by engine | resource_copy, HTTP lifecycle; no curl hook dependency |
| Response / ResponseHead | VALUE_COPY | PHP values | none | PHP GC | resource_copy, HTTP tests; header arrays contain values |
| ResponseStream / AsyncStream | NON_COPYABLE; one iterator | producer Task, queue, completion/head Future | explicit stream close cancels and joins producer | bounded queue + producer cleanup; request owns pending jobs | resource_copy, io_lifecycle/stream tests; dropping a consumer is not a universal join |
| HTTP upload/download operation | NON_COPYABLE Task/Future; body streams remain single-consumer | request Task, permit and pump; download owns response producer and file writer | explicit operation cancellation drains job/pump; one waiter token only detaches that waiter | finally closes response/ReadableStream input and owned file stream, releases permit; request engine owns unfinished native jobs | io_lifecycle, http_streaming, resource_copy; an application iterable may block and must not be concurrently consumed elsewhere |
| curl multi/cache entries | no copyable PHP native owner | request engine owns one multi/cache; request jobs own easy handles | cancel a job removes its transfer, not the shared multi | easy cleanup at collection; multi/global lifecycle at engine/module teardown | HTTP lifecycle/limits/FPM; foreign curl allocations outside ledger |
| Emitter | SAFE_CLONE shares canonical Queue | producer and queue | queue close/producer cancellation | producer finally closes; PHP GC | resource_copy cloned emission + serialization rejection |
| PSR client adapter | SAFE_CLONE shares HttpClient/factories | adapter + canonical client | underlying request ownership | normal response/exception cleanup | resource_copy when Composer dependencies installed; core PSR HTTP tests |
| SQLite Connection / native ar_db | NON_COPYABLE | request engine owns handle; PHP gate owns operation ordering | owned query Task cancellation; progress callback | explicit queued close; collect frees closed entry; shutdown joins then closes | live resource_copy, io_lifecycle, close_cancellation, sqlite conversion; executing transaction effects not undone by dropping references |
| PostgreSQL / MySQL Connection | NON_COPYABLE | PHP wrapper/gates; Zend resource owns library handle/results | local operation cancellation closes active connection | native resource destructor/request teardown | databases epoll/poll copy/bind/query/transaction tests; remote cancellation acknowledgement not provided |
| SQL Statement descriptors (all three) | SAFE_CLONE shares canonical Connection, copies SQL value | connection references | execute's operation owner | connection close affects every descriptor; PHP GC | SQLite resource_copy + live PG/MariaDB databases; no independent statement handle ownership |
| Redis Connection | NON_COPYABLE | canonical TcpStream/parser/response queues | in-flight cancellation may close shared transport | close/request resource teardown | databases RESP2/RESP3 copy/pipeline/PubSub/cancellation; no isolated remote command rollback |
| ConnectionPool | NON_COPYABLE | idle Queue and leased/queued count | borrower token; close refuses leases | closer finally restores capacity, pool close drains | resource_copy, queued-lease regression; caller must join borrowers |
| IO File | NON_COPYABLE | request ar_file; PHP operations retain wrapper/gate | accepted worker cancellation, mandatory close | explicit close/collect or request teardown after joins | resource_copy pending write/drop/close; file handle tests; no automatic last-user-reference close |
| ProcessPool / jobs | NON_COPYABLE | pool owns proc resources, pipes and queued Tasks | job/pool may terminate owned child | close/reap; destructor/request process resources | resource_copy checks child count; existing crash/IPC tests; shutdown can wait on OS behavior |
| Watcher / pending network operation | no copyable native object; keyed registration | PHP scheduler retains resource until removal; engine owns cached epoll fd/arrays | wait/token/close; completion removes scheduler registration; cache validates fd identity | temporary poll/index arrays freed each wait; epoll cache freed at engine shutdown | kernel, native_wait, readiness, epoll_cache controls; raw close bypass remains outside contract |
| Native worker job / completion | no PHP object copy; request operation ID | allocation list until result conversion/release | explicit atomic flag; one publication/collection owner | join before engine destruction; conversion bailout retains allocation list | allocation/close/fatal/injection tests; internal callers must not republish a job |
| Diagnostic ring | no PHP resource owner to clone; bounded snapshots are value arrays | request engine, published pointer retained through worker joins | capture mask; extraction does not cancel operations | native free after joins; export before RSHUTDOWN only | ring concurrent/overflow/allocation tests, diagnostic FPM; loss and EBUSY are explicit |
| TaskResult / WaitResult / HookReport | VALUE_COPY containers; object members remain shared | ordinary PHP references | none added by copying | PHP GC; nested owner serialization remains rejected | existing aggregate/report tests; no deep-copy promise |

`tests/resource_copy.php` also measures descriptor and child counts and verifies
zero native pending operations, file/SQLite handles and payload bytes after
cleanup. Raw native IDs, Reflection-created invalid state and independent fclose
of escaped resources are not granted additional ownership by these policies.
Native request shutdown joins worker publishers; no worker accesses Zend values.
Soft PHP allocation bailout is cleanup-only, not safe application recovery.
The earlier matrix remains historical scope; the copy-policy gaps described
there are addressed by this continuation, not retroactively by old archives.

## HTTP continuation ownership delta (2026-11-09)

Native92d0ba0 / HTTP acquisition45a4a20, on isolated `phase6-http`.
[HTTP_ENGINEERING.md](HTTP_ENGINEERING.md) records the completed validation and
limits; this section does not relabel earlier ownership results.

| Resource | Copy/sharing | Owner and release | Cancellation boundary | Evidence |
|---|---|---|---|---|
| Staged File and pinned parent descriptor | Existing File non-copyable/shared-reference policy | Request engine owns native registration; File serializes operations; close unlinks unpublished entry and closes both descriptors; RSHUTDOWN joins workers first | Download shields acquisition through assignment, then observes cancellation and closes; generic resource-valued Futures still require explicit ownership | `tests/http/acquire_cancel.php`, `stage_native.c`, `stage_shutdown.py` |
| Multiple-file MIME source | Files opened sequentially; no duplicated descriptor owners | Upload producer owns active source; native job owns MIME descriptors and one64 KiB slot | Cancel producer and native operation, drain completion, release client permit; server effects are not rolled back | `completion.php`, `native_completion.c`, original streaming suite |
| Completed buffered body | Native allocation moves once from job to ar_value; PHP conversion still copies | Job pointer is cleared on transfer; failed value allocation leaves job owner intact; result free releases allocation | Collection/cancellation authority unchanged | `native_completion.c`, `buffer_budget.php` |
| Cached curl easy handle | No PHP resource alias; bounded request-local cache | Detached/reset at job free; destroyed at request shutdown; shared multi owns connection cache | No active-job handle enters idle cache | `pooling.php`, header/cookie reuse checks, `fpm_request.php` |

The confirmed acquisition race retained a handle/private staging entry until
request shutdown; it did not establish a post-shutdown native leak. The repair
prevents this within-request retention without imposing a destructor-driven
cancellation policy on arbitrary shared Futures. Library and kernel memory remain
outside complete native-ledger accounting.


## Native server TLS/stream ownership continuation

| Resource | Copy/sharing policy | Owner and release | Cancellation |
|---|---|---|---|
| rustls ServerConfig | Immutable Arc shared within one worker; no cross-worker interpreter state | Listener and accepted TLS peers; final Arc releases parsed cert/key state | Listener close stops admission; peer shutdown releases its reference |
| TlsIo/ServerConnection | NON_COPYABLE, one driver/waker with canonical shared Socket | Hyper connection; RAII on completion/abort/deadline/request teardown | Existing server owner; no PHP callback crosses a Rust borrow |
| StreamResponse | NON_COPYABLE, single-use; assignment shares owner | PHP handler owns producer until completion or unwind; external references remain caller-owned | Owning connection/handler; not an unrelated shared waiter |
| Native StreamingBody | NON_COPYABLE, one HTTP message | Hyper body owns StreamState; registry contains only Weak entries | Drop emits cancellation if unfinished and removes registry entry |
| Stream chunk | Shared immutable Bytes | Data accounting survives until final transport reference drops | Cannot retract already-buffered/transmitted data |
| Demand event | Request/connection generation IDs, no payload | Bounded one outstanding demand per stream; existing App Inbox | Closed-owner events are discarded; no new handler is created for stale demand |

Tests include copy rejection, early output, independent timer progress, backpressure,
producer failure, total/chunk limit failure, canceled generator cleanup, TLS
handshake failures, mTLS rejection, and final zero payload accounting. These are
scoped tests; foreign TLS allocations are not covered by the payload ledger.

HEAD and rejected response headers must also close an eager Asyncara
ReadableStream producer, even when no iteration began. The failing-before control
retained a blocked producer; `StreamResponse::close()` now cancels/joins it and
releases the response reference. A caller-retained arbitrary Generator has its
ordinary PHP lifetime; no global Future cancellation policy is introduced.

## Request streaming ownership continuation

| Resource | Copy/sharing | Owner/release | Cancellation and evidence |
|---|---|---|---|
| Hyper Incoming/InputState | NON_COPYABLE; Weak registry | Native Pending service Future; drop on response or connection teardown | Demand-only reads; EOF/invalid/limit events; real peer disconnect/deadline checks |
| RequestBodyStream | NON_COPYABLE; assignment shares identity | Handler Task; close clears Task and Inbox references | Foreign Task consumption/close rejected before mutation; owner can still read; input.py/api.php |
| Request metadata | VALUE_COPY when buffered; SHARED_REFERENCE to input when streaming | PHP values; copied metadata does not duplicate native ownership | Input remains handler-scoped and single-use; serialization traversing it is rejected |
| Input event/tail | One demanded owned event plus Hyper Bytes remainder | Shared payload accounting until event pop; native tail until next demand/drop | <=64KiB event, bodyBytes cumulative bound; Hyper/TLS allocations remain outside full accounting |

The first close implementation retained Inbox after closing: an independent weak
reference control failed before the correction and passes after it. API tests
retain this regression. Closing input is not the same as cancelling the whole
connection; App/native response ownership decides when unread framing requires
closure. External PHP references to already-delivered chunks remain caller-owned.
