> Historical Phase 2 report at baseline `4d90dbe`. Phase 3 changes and later evidence are recorded in [ROADMAP.md](ROADMAP.md), [DATABASE_COMPATIBILITY.md](DATABASE_COMPATIBILITY.md), [FPM_LIFECYCLE.md](FPM_LIFECYCLE.md) and [SOAK_TESTING.md](SOAK_TESTING.md). The original findings and measurements below are preserved.

# Independent engineering audit — 2026-11-02

**Release assessment: EXPERIMENTAL.** The implementation provides real cooperative scheduling, asynchronous I/O and process parallelism on the tested Linux NTS builds. The audit found seven high-severity defects that the original test suite missed. The working tree repairs them and contains executed regression tests. The preserved original distribution contains those defects and must not be published as the corrected implementation. This assessment does not certify production use.

“Independent” here means fresh extraction/builds, new adversarial tests, independently collected measurements and source review without treating the preceding work log as proof. This is not an external organization’s security certification or an exhaustive proof of safety.

## 1. Repository and evidence provenance

- Baseline Git commit: `f11c892b2a5b9ccae492772565f46211863b2894` (`dev`). Git status was empty at the start. No pre-existing user changes were overwritten, and this audit creates no commit.
- Original distribution: `build/asyncara-0.1.0-dev.tar.gz`.
- SHA-256: `a26ccf06960ed1ed5eeab4c2e16eb3144e5d85058a5d2adfd47106f89101031f`.
- All 127 archive entries were inspected: regular files, safe relative paths, no links, toolchains, binaries, credentials or generated native build products. All matched the original working tree byte for byte. The complete per-file listing, sizes and SHA-256 values are in [archive-manifest.json](audit/archive-manifest.json).
- [evidence.json](audit/evidence.json) retains baseline failures, installed-package results, corrected regressions, scheduler measurements, FPM samples, stress samples, implementation hashes and selected log excerpts/hashes. Raw logs and independent build prefixes are under the path in `build/audit-path` (this run: `/tmp/asyncara-audit-vdvyqsv4`). Temporary directories can expire; the checked-in evidence is the durable subset.
- Changed implementation: `ext/{php_asyncara,postgresql,mysql}.c`, Scheduler/Async, the three SQL Connection classes, ConnectionPool and ProcessPool. Test/build tooling, audit tests and documentation are also modified/added; the final inventory is below. Generated builds/dependencies remain ignored.
- Source search found no TODO/FIXME or empty fake driver implementations. `XXXXXX` in the native test is a mkstemp template. Explicit unsupported-operation errors and capability flags are intentional, not implementations of those missing features. TaskResult, WaitResult, exceptions and interfaces are small real abstractions, not evidence of additional drivers.

### Actual module inventory

| Area | Actual implementation | Scope / omissions |
|---|---|---|
| Native extension | One module; Zend bridge, request lifecycle, worker pool, readiness backend, curl multi, allocation accounting; optional libpq/Connector/C code included at compile time | No separate native scheduler or proprietary Fiber implementation |
| Scheduler/tasks | PHP Fiber objects, FIFO ready queue, monotonic timer heap, Future/Task, cancellation, timeouts, wait/asCompleted/groups | Cooperative; nested Async::run rejected; no CPU preemption or signal watcher |
| Synchronization/context | Lock, FIFO Semaphore, manual-reset Event, bounded/default Queue, shallow task context | No automatic superglobal/static isolation; no independent Channel API |
| Network | TCP connect/listen/accept/read/write, UDP, worker DNS, PHP/OpenSSL TLS, buffered libcurl HTTP, generic scoped connection pool | No WebSocket; HTTP streaming/multipart absent; IPv6 and TLS edge matrix incomplete |
| Files | All exposed Files operations, stable File handles, chunk streams, seek/stat/sync | pthread workers; no io_uring or atomic replacement; directory results buffered |
| Databases | SQLite C worker implementation, nonblocking libpq, nonblocking Connector/C, userland RESP over nonblocking TCP | Local-server scope below; no transparent PDO replacement |
| Parallel execution | Native operation threads and registry-based PHP ProcessPool | No PHP userland ThreadPool; no silent substitution on NTS |
| Compatibility | Chained execute-internal dispatch for sleep/usleep; Composer PSR-4; PSR-18 using supplied PSR-17 factories | No Laravel/Symfony/WordPress lifecycle adapter, PSR-3 integration or alternate-loop adapter |
| Diagnostics | Counts, native-owned allocation accounting, maximum task duration and timer lag | No exact identification of unknown blocking calls; third-party memory not counted |

### Host, build configuration and dependencies

Ubuntu 24.04.5, Linux 6.8.0-147, x86_64 KVM, six visible CPU cores. Host PHP 8.5.11 NTS; separately extracted PHP 8.3.36 and 8.4.27 NTS. Host extension headers are 8.5.12 with the same API 20250925; exact PHP 8.5.11 debug and instrumented source builds additionally cover the matching-version boundary. Old installed PHP branches are outside the extension’s compile-time 8.3–8.5 guard. ZTS is untested.

Direct build: GCC 13.3, C11, `-O2 -g -fPIC -shared -Wall -Wextra -Werror`, pthreads; unused-parameter warnings are disabled for Zend/extension entry-point signatures. No new diagnostic was suppressed to pass this audit. phpize/configure supports `--enable-asyncara`, `--with-asyncara-pq`, `--with-asyncara-mysql`. The generated configure script emits the upstream `AC_PROG_LIBTOOL` obsolete-macro notice; native compiler builds were warning-free. This is recorded rather than called an entirely warning-free toolchain.

Required native libraries: libc/pthreads, SQLite 3.45.1, libcurl 8.5.0. Optional linked dependencies used here: libpq 16.15 and MariaDB Connector/C (`libmariadb.so.3`). TLS uses OpenSSL 3.0.13 through PHP/curl/connectors. HTTP explicitly rejects a libcurl build without asynchronous DNS capability. Explicit DNS uses getaddrinfo in workers, not c-ares integration. libuv/libevent/liburing are not linked runtime backends. Composer production dependencies are PSR HTTP client/factory/message interfaces; Nyholm PSR-7, AMP and Revolt are development dependencies recorded in composer.lock.

Licenses: Asyncara/PSR/Nyholm/AMP/Revolt MIT; curl curl license; SQLite public domain; libpq PostgreSQL License; MariaDB Connector/C LGPL; OpenSSL Apache-2.0; libc LGPL; PHP/Zend their respective PHP/Zend licenses. Native dependencies are dynamically linked and not redistributed in the source archive. OS package versions are not locked by bootstrap-local.sh; record `.local/dependencies.sha256` when reproducing a toolchain.

## 2. Distribution verification

The original archive was extracted into an empty temporary directory. Its own bootstrap script downloaded/extracted development packages locally. Its own sources generated phpize/configure/Makefile and built without borrowing generated application files from the working repository. Composer dependencies were installed from its lockfile. Direct and phpize builds loaded successfully with `php --ri asyncara`.

For each PHP version, the extension was compiled with that branch’s headers, placed under a separate `installed/{83,84,85}/lib` prefix and loaded via a separate `php.ini`. No system PHP configuration or extension directory was modified.

| Original archive environment | Compile/load | Functional execution |
|---|---|---|
| PHP 8.3.36 NTS | PASS | 44 core cases; limits, native budget, stable handles; fatal-memory runner also passed |
| PHP 8.4.27 NTS | PASS | Same |
| PHP 8.5.11 NTS | PASS | Same; fresh phpize/configure/make also passed |

These passes do **not** override the new failing tests. The unchanged installed archive independently reproduces root-shutdown, transaction-serialization, ProcessPool-close, cancelled-acquisition and queued-borrower failures. The relative-path and PostgreSQL exhaustion defects were also reproduced against the baseline implementation before repair.

Reconstructing the archive from the extracted source manifest, original file modes, fixed zero timestamps and deterministic gzip produced the exact original SHA-256. This verifies deterministic **source archive** bytes. Native binaries contain build paths/debug information and were not certified bit-reproducible across prefixes/toolchains. Bootstrap downloads current OS package candidates; a reproducible deployment must retain the package checksum inventory/toolchain, not assume apt candidates are immutable.

The original repository and archive had no source differences. The corrected working tree now differs intentionally by the fixes/tests/documentation described here. The original artifact remains preserved for audit provenance. A replacement audit archive is named separately as `build/asyncara-0.1.0-dev-audit1.tar.gz`; its sidecar identifies its own checksum. The replacement snapshot was separately extracted, bootstrapped with fresh local dependency packages, compiled and tested on PHP 8.3, 8.4 and 8.5, including the new independent regressions and delayed-peer suite. Do not confuse either package’s successful compilation with production readiness.

## 3. Defects found and fixed

| ID / severity | Independently observed failure | Repair and executed verification |
|---|---|---|
| A1 HIGH | A root exception with a 60-second delayed callback did not return within a 3-second subprocess deadline | Observe root failure before polling; cancel callback timers while preserving suspension/deadline timers needed by shielded cleanup. Immediate-failure, runtime-restart, shielded sleep/wait and callbacks created during cancellation cleanup regressions pass |
| A2 HIGH | Two SQLite query Futures created by a transaction owner reached native code concurrently: “connection missing, closed or busy” | Separate transaction ownership from a per-connection operation semaphore for SQLite, PostgreSQL and MariaDB; use Task identity instead of recycled numeric IDs. Parallel queries inside transaction scopes now pass on all three drivers |
| A3 HIGH | Closing ProcessPool with pending work called feof on an already closed pipe | Retirement recognizes previously stopped workers; pending-close, crash/replacement and timeout tests pass |
| A4 HIGH | Cancellation during an asynchronous ConnectionPool factory left one opened resource and zero closes | Shield acquisition, check cancellation after ownership transfer, close before returning a slot. Also discard failed resources before invoking a possibly throwing closer and continue closing other idle resources after closer failure |
| A5 HIGH | A submitted relative file write moved to another directory after the application called chdir | Capture PHP’s working directory before native submission for file/SQLite paths and copy/rename destinations. A deliberately delayed open now preserves its submitted destination while unrelated timers advance |
| A6 HIGH | Zend memory exhaustion while converting a libpq result detached PGresult from its owner: 216 direct + 3,072 indirect bytes definitely/indirectly lost; a 12,582,921-byte buffer possibly lost | Keep in-flight PGresult/PGnotify attached to the Zend resource until conversion finishes; register connector ownership before acquiring foreign allocations. Fatal conversion now exits 255 with zero Valgrind errors/lost blocks. MariaDB constructor ownership hardened too |
| A7 HIGH | ConnectionPool close saw zero active borrowers after a queue transfer but before the receiving Fiber resumed; it closed the pool with a lease outstanding | Count queued acquisitions as borrowers until their entire scope ends. Deterministic transfer/close regression now rejects premature close and releases the connection exactly once; normal/debug/ASan and Valgrind checks pass |

All seven are repaired in the working implementation. No known unresolved critical/high defect remains **within the executed test scope**. That is not an assertion that unknown defects are absent. The original archive still contains these failures.

A further defensive check rejects waits from manually created, unregistered Fibers nested inside a Task. Before the guard, such a Fiber could register its wait against the enclosing Task. This unsupported-integration hazard was reproduced; rejection and subsequent normal task execution now pass on PHP 8.3/8.4 and instrumented 8.5. It does not add a second scheduler or support nesting independent event loops.

Two test problems were also fixed, with their failures retained: the multiworker HTTP fixture’s backlog of five caused delayed server accepts (timers continued running), and inheriting USE_ZEND_ALLOC=0 prevented PHP memory exhaustion from occurring. The scheduler drain was additionally checked against a child finally block that creates another 60-second callback: the reproducer initially exceeded two seconds; the repaired drain discards newly created callbacks before polling as well. The fixture backlog is now 128; the fatal child explicitly sets USE_ZEND_ALLOC=1. Assertions were retained, not disabled.

## 4. Scheduler and concurrency audit

Task owns a native PHP Fiber object; the callback settles its Future and reports completion in finally. Scheduler owns live tasks, queued resumptions, timers, watcher closures and native Future mappings. Wait registration creates a single-settlement resume closure; cancellation queues a Fiber instead of resuming it from a worker or callback stack. Finally blocks remove subscriptions/watchers/timers. Native workers never resume Fibers.

Ready work is FIFO in SplQueue, capped at 256 dequeues per scheduler turn. Timers use monotonic hrtime and a priority heap; cancelled entries are pruned/compacted. Waiting tasks are represented by task ownership plus Future/semaphore/queue/I/O registrations, not by a second runnable queue. Duplicate enqueue is prevented. Tests cover result reuse, exceptions, unobserved task failures, task groups, nested-run rejection, cleanup, cancellation races and deadlock detection when no registered source can wake a task.

Blocking waits use native epoll or poll plus the worker completion pipe and curl interests/deadlines. An idle 250 ms task produced one `epoll_wait(..., 250)` in strace. Fresh measurements: epoll 253.162 ms wall / 2.875 ms CPU; poll 252.651 ms wall / 2.377 ms CPU. Each required two task steps. This is evidence against idle busy-waiting; continuously ready application tasks can intentionally consume CPU.

Each of 1,000 and 10,000 tasks executed all three yield rounds. No task was starved in those tests. FIFO synchronization transfer/cancellation tests pass. This is not preemptive fairness: a callback can run indefinitely, and creating thousands of tasks in one callback causes visible timer lag. Nested independent schedulers/loops remain unsupported. Root failure now joins cancellation/cleanup promptly, except genuinely uninterruptible native operations or deliberately shielded work can still delay exit.

An independent ProcessPool probe warmed two workers, then measured 1.000519 seconds of combined worker CPU time in a 0.503783-second overlapping wall interval (ratio 1.986, distinct PIDs). This demonstrates simultaneous CPU consumption beyond one core, rather than merely overlapping sleep intervals. The registry accepts trusted jobs and bounded JSON data, not transferred closures.

The native job list also maintains a separate allocation ledger: submitted, collected and not-yet-submitted jobs remain reachable for request-shutdown cleanup after a Zend bailout. Workers are joined before that memory is destroyed. Future values are retained until PHP releases them; cycles require normal PHP GC. An unawaited native Future error is not currently escalated like an unobserved Task error. This documented difference remains a semantic limitation.

### Measurements, not performance guarantees

Measured on the shared six-core VM while other audit workloads were active. Includes creation, timers, queue processing, result collection and teardown, not an isolated Fiber-switch instruction cost. Every sample is shown:

| Tasks × three yields | Total seconds, three samples | Task steps | PHP allocated pages reported at end |
|---|---|---|---|
| 1,000 | 0.173895 / 0.178180 / 0.168144 | 4,003 | 24 MiB |
| 10,000 | 3.582208 / 3.337334 / 2.103002 | 40,003 | 228 MiB |

The 10,000-task samples showed maximum timer lag of 374.5 / 706.2 / 122.2 ms. The large spread and high creation-phase lag prohibit a low-latency guarantee. Tests explicitly use a 1 GiB PHP limit; default task count limits are not a memory admission policy. A separate 1,000-sample, 1 ms timer probe with 64 continuously yielding tasks measured median/p95/p99/max lateness of **0.447 / 0.909 / 2.791 / 15.597 ms**. These are timer lateness measurements, not HTTP latencies. Prior benchmark throughput claims were not recertified by this audit.

## 5. Actual I/O implementation and blocking boundaries

Here “worker” means the syscall/library call blocks a native worker, while the requesting Fiber suspends and the loop waits for a completion notification. It does not mean kernel asynchronous file I/O.

| Public operation(s) | Actual implementation/backend | Suspension, cancellation and limitations |
|---|---|---|
| TcpStream::connect | PHP async-connect numeric socket; DNS worker first; epoll/poll writable wait | Fiber suspends; timeout/cancel closes socket; socket/context setup is synchronous |
| TcpServer construction / accept | PHP bind/listen, nonblocking stream_socket_accept and readability wait | Bind/listen setup synchronous; accept suspends; close wakes waiters |
| TcpStream::read / readExactly | nonblocking fread + readiness | Handles partial reads/EOF; cancellation at waits/checkpoints; exact read rejects short EOF |
| TcpStream::write | bounded nonblocking fwrite chunks + readiness | Partial writes/backpressure tested with 8 MiB to delayed reader; cancellation cannot retract sent bytes |
| UdpSocket bind / send / receive | numeric PHP datagram socket; writable/readable waits | Bind synchronous; send/receive suspend; empty/binary messages tested; delayed receive/cancellation independently tested |
| Dns::resolve | native do_dns/getaddrinfo, four-thread pool | Blocking resolver off loop; cancellation does not forcibly interrupt libc DNS; no TTL/cache |
| Explicit TLS | PHP/OpenSSL stream crypto on nonblocking TCP | Delayed handshake allowed timer progress; trust/hostname failures tested; certificate loading/crypto are synchronous CPU/metadata work; WANT_WRITE/renegotiation/shutdown edge coverage incomplete |
| HttpClient request/get/post | libcurl multi socket API in ext/backend.c; epoll/poll; curl resolver | Slow HTTP requests overlap; cancellation removes easy handle; errors/status/redirect/compression/limits tested. Buffered bodies; no response streaming/multipart |
| Files read/write/append | native do_file open/read/write loops, workers | Fiber awaits completion; cooperative cancellation between chunks; blocked syscall may delay cancellation |
| Files delete/rename/mkdir/rmdir | unlink/rename/mkdir/rmdir in do_file workers | Worker-backed; side effects cannot be rolled back by cancellation |
| Files copy | worker open/read/write loops | Partial destination possible on error/cancel; no atomic replacement contract |
| Files stat/exists/entries | worker stat/opendir/readdir; exists wraps stat | Buffered directory result; exists recognizes English error text, not typed errno |
| Files::open / File::open | worker do_handle open/fstat; request-owned native handle | Acquisition/cleanup shielded; cancelled opens reclaimed; captured path |
| File read/write/seek/stat/sync/close | serialized worker read/write/lseek/fstat/fsync/close | Stable descriptor; bounded chunks; close drained; explicit close/using required for prompt cleanup |
| Files readStream/writeStream | above stable handle operations + bounded producer Queue | Backpressure and early-close cleanup tested; supplied user iterable can itself block |
| SQLite open/query/execute/prepared binds/fetch/transactions/close | SQLite C API in do_sqlite workers | Per-connection serial operations; buffered results; progress callback cancellation; busy timeout may delay it |
| PostgreSQL connect/query/results/notifications/close | PQconnectStart/Poll, PQsendQueryParams, PQflush/PQconsumeInput/PQisBusy/PQgetResult, epoll/poll | Correct readiness order reviewed/tested; cancellation discards connection, not confirmed server cancellation; startup configuration/auth/TLS work can perform synchronous filesystem/CPU operations |
| MariaDB connect/prepared execute/fetch/close | Connector/C start/cont state machine, descriptor/timeouts | Readiness-driven local tests; cancellation shuts down/discards connection; remote auth/TLS unverified |
| Redis commands/pipeline/transaction/PubSub | RESP over TcpStream with connection semaphore | Serial protocol ownership, bounded frames; cancellation closes connection; no server-side undo/replay |
| ProcessPool submit/result | separate PHP processes, nonblocking pipes, JSON length framing, epoll/poll | Genuine overlapping CPU jobs on distinct PIDs; spawn and final proc_close/reap can block; bounded retirement grace is not a hard OS reap guarantee |

Independent slow tests counted timer ticks **while the operation was pending**, rather than waiting for a ticker after completion. This avoids the weakness found in some original heartbeat assertions. Delayed TCP read/write, accept, UDP receive, TLS handshake and DNS produced 26–40 ticks in the retained consolidated run. A delayed worker file open produced 35 ticks and preserved its original directory. Four HTTP operations overlap in each FPM I/O request; at least 27 ticks occurred in the single-worker run and 28 in the multiworker run.

Filesystem argument copying, cwd capture and open_basedir validation occur on the PHP thread; security/path checks may perform synchronous metadata operations. Large native-to-zval conversion, JSON encoding, TLS CPU work and application callbacks also occupy that thread. PSR-18 materializes the supplied PSR-7 stream synchronously. These are blocking boundaries even though subsequent I/O uses an asynchronous backend. No operation is classified as io_uring-backed.

Cancellation tests establish supported checkpoints and cleanup, not forced interruption of arbitrary filesystem, DNS, SQLite busy handlers, PHP C functions or server-side database work. Invalid paths, refusal, DNS failure, broken HTTP, trust failure, malformed/oversize arguments and native allocation limits propagate errors in the executed suites.

## 6. Database audit

| Driver | Executed independent evidence | Unsupported / unverified scope |
|---|---|---|
| SQLite 3.45.1 | Temp databases, prepared binding, transactions/rollback, serialized parallel owner queries, two-connection write contention with ten timer ticks before commit, recursive-query cancellation, handle cleanup, fatal large-result teardown | Buffered fetch; Statement retains SQL rather than persistent sqlite3_stmt; native busy handler can delay cancellation; no automatic rollback of arbitrary manually abandoned transactions |
| PostgreSQL 16.15 | Local isolated Unix socket, parameters, large results, transaction rollback, independent-connection server interval overlap, parallel owner queries, pg_sleep(.2) with timers, notifications, errors, cancellation/close, fatal result conversion under Valgrind | No tested remote credentials/TLS/SCRAM/GSS matrix; no prepared cache, COPY API or acknowledged PQcancel protocol; notifications buffered without an aggregate application queue cap |
| MariaDB 11.8.9 | Local isolated Unix socket, native prepared statements/binary parameters, transactions, independent-connection overlap, parallel owner queries, sleep(.2) with timers, errors/cancellation/close, fatal result teardown | **Oracle MySQL was not run.** MariaDB server tests do not certify MySQL authentication/protocol variants. Remote TLS/authentication and reconnect policies unverified/absent |
| Redis | Local RESP2 and common RESP3; binary data, pipeline, command errors, MULTI/EXEC, Pub/Sub, cancellation, BLPOP with timers; generic scoped ConnectionPool lifecycle separately tested | No automatic Redis pool/cluster/sentinel/reconnect policy; RESP3 attributes/streaming aggregates/arbitrary map keys absent; no comprehensive malicious-peer parser fuzzing |

SQLite affinity means **one active operation per connection**, not a dedicated OS thread per connection. Connections use SQLITE_OPEN_FULLMUTEX and can move between pool threads sequentially. The new operations semaphore is distinct from the transaction lease; this fixes the owner-bypass race. PostgreSQL uses single-row mode internally but exposes buffered arrays. Its loop checks PQisBusy before collecting results and waits for read/write progress instead of blocking PQexec. MariaDB drives wait flags/timeouts from Connector/C. The generic connection pool never transfers these connections to PHP worker processes.

Local SQL errors and cancellation intentionally discard PostgreSQL/MariaDB connections; there is no transparent reconnection or query replay. Redis pipelines retain server errors as values while reading all replies, preserving framing. Transaction consistency and safe connection reuse must not be inferred beyond these semantics.

## 7. Transparent hooks: complete claimed compatibility

Only **sleep** and **usleep** are intercepted. Asyncara suspension requires a registered Task Fiber; a manually nested foreign Fiber is rejected. Both are classified **PARTIALLY_SUPPORTED**, although their supported waiting path is an asynchronous scheduler timer. Neither is a filesystem worker or kernel file I/O operation. No other hook is advertised as implemented.

| Original function | Implementation / PHP versions tested | Actual backend / status | Fallback, errors, resources, cancellation |
|---|---|---|---|
| sleep | execute_hook → hooked_sleep → Async::sleep; 8.3.36 / 8.4.27 / 8.5.11 | monotonic timer + epoll/poll wait; **PARTIALLY_SUPPORTED** | Ordinary nonnegative bounded integers, named argument, return 0, zero/negative/strict type cases tested. Outside enabled runtime original PHP handler runs. No PHP resource produced. Cancellation throws CancelledException. POSIX signal/remaining-time behavior and durations beyond scheduler’s one-year cap differ |
| usleep | same dispatch and microsecond conversion; same versions | same timer; **PARTIALLY_SUPPORTED** | Null return, named argument, negative/strict type errors, progress during 200 ms wait and cancellation tested. Outside runtime original handler. No resource. Signal semantics and full weak-coercion/deprecation matrix not certified |

The following function-by-function matrix prevents “HOOK_ALL” from being mistaken for universal coverage. **UNSUPPORTED** means no Asyncara interception; the fallback is the ordinary PHP implementation and can be blocking. A **BLOCKING_FALLBACK** is a behavior, not an asynchronous guarantee. Error/resource behavior therefore remains PHP’s own; there is no Asyncara cancellation or responsiveness certification for these original calls.

| Original target | Hook status | Original-call fallback |
|---|---|---|
| fopen | UNSUPPORTED | BLOCKING_FALLBACK; stream wrapper dependent |
| fclose | UNSUPPORTED | Original close/flush may block |
| fread | UNSUPPORTED | BLOCKING_FALLBACK unless caller configured nonblocking stream |
| fwrite | UNSUPPORTED | BLOCKING_FALLBACK unless caller configured nonblocking stream |
| file_get_contents | UNSUPPORTED | BLOCKING_FALLBACK |
| file_put_contents | UNSUPPORTED | BLOCKING_FALLBACK |
| stream_get_contents | UNSUPPORTED | BLOCKING_FALLBACK / stream dependent |
| stream_socket_client | UNSUPPORTED | Original flags determine blocking behavior |
| stream_socket_server | UNSUPPORTED | Original bind/listen setup |
| stream_select | UNSUPPORTED | Original timeout/readiness wait |
| fsockopen | UNSUPPORTED | BLOCKING_FALLBACK |
| stream_set_timeout | UNSUPPORTED | Original stream setting; no scheduler deadline |
| unlink | UNSUPPORTED | BLOCKING_FALLBACK |
| rename | UNSUPPORTED | BLOCKING_FALLBACK |
| mkdir | UNSUPPORTED | BLOCKING_FALLBACK |
| rmdir | UNSUPPORTED | BLOCKING_FALLBACK |
| copy | UNSUPPORTED | BLOCKING_FALLBACK |
| stat | UNSUPPORTED | Original metadata operation; may block |
| lstat | UNSUPPORTED | Original metadata operation; may block |
| file_exists | UNSUPPORTED | Original metadata/cache behavior |
| is_file | UNSUPPORTED | Original metadata/cache behavior |
| filesize | UNSUPPORTED | Original metadata/cache behavior |
| filemtime | UNSUPPORTED | Original metadata/cache behavior |
| curl_init | UNSUPPORTED | Original CurlHandle creation; no async interception claimed |
| curl_setopt | UNSUPPORTED | Original option handling |
| curl_exec | UNSUPPORTED | BLOCKING_FALLBACK |
| curl_multi_exec | UNSUPPORTED | Original caller-driven curl multi behavior |
| curl_multi_select | UNSUPPORTED | Original readiness wait |
| PDO::__construct | UNSUPPORTED | Original driver connection behavior |
| PDO::query | UNSUPPORTED | BLOCKING_FALLBACK for ordinary synchronous drivers |
| PDOStatement::execute | UNSUPPORTED | Original driver behavior |
| mysqli_query | UNSUPPORTED | Original flags/driver behavior |
| Redis::command / extension methods | UNSUPPORTED | Original ext-redis behavior |
| pg_query | UNSUPPORTED | Original ext-pgsql behavior |
| SoapClient::__soapCall | UNSUPPORTED | Original SOAP behavior |
| socket_connect | UNSUPPORTED | Original socket mode determines blocking behavior |
| socket_recv | UNSUPPORTED | Original socket mode determines blocking behavior |
| socket_send | UNSUPPORTED | Original socket mode determines blocking behavior |
| proc_open | UNSUPPORTED | Original process spawning behavior |
| proc_close | UNSUPPORTED | Original child reap behavior |
| exec | UNSUPPORTED | Original synchronous process behavior |

Explicit Files/SQLite/DNS APIs are **WORKER_BACKED_ASYNC** in their execution phase. Explicit readiness TCP/UDP/HTTP/SQL drivers have genuinely asynchronous waiting paths; their setup and buffered/unsupported limitations are in section 5. No untested transparent hook receives FULLY_ASYNC or WORKER_BACKED_ASYNC status.

The dispatch chains the previous Zend execute-internal handler and restores it only while still owned. Request flags reset at RINIT/RSHUTDOWN. Hook::ALL is TIME only. STRICT rejects unavailable requested groups; it cannot discover/intercept every blocking call. Long-step diagnostics measure elapsed time after return, not the identity of an unknown native function. Coexistence with the host extension set is tested separately; arbitrary profiler/debugger/hook combinations remain unverified.

## 8. FPM: three distinct experiments

Isolated PHP-FPM 8.5.11 pools, extension loaded with `-n`, loopback FastCGI, no host service/configuration modification. Idle sampling waits until the worker is accepting another request, not merely until the FastCGI response ends.

| Experiment | Workload / evidence | Idle observations |
|---|---|---|
| A: sequential lifecycle | 100 requests through one PID; SQLite, files, hooks/context; intentionally uncollected native file handles. Then one deliberate 8 MiB fatal-memory request and a healthy subsequent request on the same PID | Six descriptors, one thread; RSS 17,448–17,728 KiB across retained samples |
| B: concurrency within request | Ten sequential requests through one PID, each four HTTP operations to a delayed external server; all four server intervals overlap and timers progress while pending | Six descriptors, one thread; RSS 18,548 KiB |
| C: concurrent requests | Twelve requests through four distinct worker PIDs; initial request intervals overlap across all workers; each request also passes B’s four-operation overlap/progress assertions | Six descriptors and one thread per idle worker; RSS 17,996–18,156 KiB |

Each request first asserts default Runtime configuration, empty prior diagnostics, disabled hook flag, empty task context, zero native retained bytes, zero pending operations and zero open native file handles. It changes configuration/context/hooks during execution so a reset check is meaningful. These passed. Native thread count returns to one after request teardown; no persistent worker threads escaped the request. HTTP/native sockets close back to the idle descriptor baseline.

RSS includes PHP and native allocations and is coarse; it is not attribution of every native byte. These tests establish short lifecycle stability and two forms of concurrency, not a multi-day production soak. Other FPM versions, opcache preload/persistent-resource combinations, Apache and ZTS SAPIs remain untested. A healthy subsequent request demonstrates teardown of the failed request; it does not resurrect that request’s Fibers.

## 9. Memory, failure and concurrency safety

| Validation | Result / precise scope |
|---|---|
| PHP 8.5.11 debug source build | Full core/limits/budget/fatal/handle runner passes; exact matching PHP headers |
| Fully instrumented PHP + extension, ASan/UBSan | 44 core cases, limits/budget/handles, fatal teardown, audit regressions and both database suites pass after correcting fatal-test allocator setup; no sanitizer report |
| Standalone native ASan/UBSan with leak detection | Worker lifecycle, files, SQLite, allocator bounds and pending shutdown pass |
| Standalone native TSan | Pass; non-PIE and process-local `setarch -R` avoid this host’s TSan address-map collision; no global ASLR change or race suppression |
| Injected allocation failure | Link-wrapped malloc/calloc/realloc failures in engine bootstrap, payload allocation, growth/shrink, job allocation and readiness scratch allocation; bookkeeping, cleanup and subsequent successful operation pass under ASan/UBSan |
| Valgrind | Core stress, pending request shutdown, network/TLS, native budget, stable files, PostgreSQL/MariaDB/Redis and queued pool lease: zero errors, zero definite/indirect/possible losses |
| Fatal PHP allocation under Valgrind | Large file and SQL results; PostgreSQL defect repaired; PostgreSQL/SQLite/MariaDB exit 255 with zero errors/lost blocks in corrected tests |
| Static analysis / warnings | GCC -fanalyzer no finding; -Wall/-Wextra/-Werror native builds pass; PHP lint/Python compilation pass; configure macro notice retained |
| Repeated stress | 300 cycles/30,000 SQLite inserts plus queue and file work; descriptor baseline stable at seven after lazy initialization; live PHP sample growth ~11.6 KiB including growing samples |

PHP source tarball SHA-256: `d9be75c08e8c316f4c8f4194d8fbe1750a15f6a6d9d4e3fe72082abeeb800360` (`php-8.5.11.tar.xz`). Instrumented source uses PHP’s ASan-aware Fiber switching. Third-party shared libraries (curl/OpenSSL/SQLite/libpq/Connector/C) were not rebuilt with sanitizers. TSan covers the native worker harness, not arbitrary PHP extensions or all SAPI interleavings. No PHP ZTS interpreter was executed.

Valgrind prints stack-switch warnings on stock PHP Fibers; these were retained, not suppressed. Corrected normal runs retain approximately 77.5 KiB reachable library/process state at exit; this is not “zero allocations at exit.” No definite/indirect/possible losses were reported in the listed corrected workloads.

Ordinary PHP/Fiber exceptions, cooperative cancellation, supported timeouts and native budget/injected-allocation rejection can be caught, cleaned up and followed by another runtime operation in the tested cases. Zend fatal memory exhaustion is **unrecoverable for that request**: PHP exits/tears it down, and userland finally/cleanup callbacks are not guaranteed to execute. Native resources must remain owned until Zend/resource/request shutdown. The audit found precisely such an ownership hole and fixed it.

Actual system-wide OOM, arbitrary foreign-library malloc failure, every Zend allocation point, SIGKILL, hung storage/resolver, and all shutdown ordering/extension combinations were not exhaustively injected. No safe-recovery claim is made for them. Native worker code uses copied C values and synchronization; no Zend APIs/zvals/PHP callback execution occur in worker(), do_file/do_handle/do_dns/do_sqlite. Atomics protect cancellation/allocation counters; mutex/condition/completion-pipe transitions publish results before the loop marshals them.

## 10. Remaining concerns and release gates

- **Distribution:** the preserved original archive has known high-severity failures. Use a reviewed corrected snapshot; do not relabel the original artifact.
- **Boundedness:** PHP Fibers/arrays can exhaust memory before taskLimit. The native budget covers Asyncara-owned allocations, not foreign libraries/stacks/processes. PostgreSQL notifications and some aggregate userland result storage lack a total application queue cap. Redis integer edge cases and malformed-peer behavior need comprehensive fuzzing.
- **Shutdown:** libc resolver, blocked storage and some foreign-library cleanup are not forcibly cancellable. No hard wall-clock shutdown guarantee; operational process limits remain necessary.
- **Security:** TLS verification defaults on in implemented clients. SSRF policy is application-owned. open_basedir checks are submission-time checks and are not a symlink-race sandbox. Process registries/bootstrap are trusted code; JSON IPC is bounded and avoids PHP unserialize, but workers are not sandboxes. Exceptions can expose credentials when PHP records arguments.
- **Compatibility:** Oracle MySQL, remote SQL auth/TLS, ZTS, non-Linux backends, IPv6/TLS edge cases, framework lifecycle integration, preload and arbitrary extension coexistence remain unverified or absent. Generic PDO/streams/curl transparency is not implemented.
- **Behavior/performance:** high task creation latency, synchronous result conversion/setup, buffered HTTP/DB APIs, incomplete diagnostics and unobserved native-Future errors limit the contract. There is no sustained multi-day soak, complete fuzz campaign or independent external security review.

Priority before a release candidate: review the audit fixes and rebuild the distribution; expand fault/protocol fuzzing and sustained FPM/load tests; establish bounded notification/result/admission behavior and explicit shutdown budgets; certify intended SQL authentication/TLS and PHP/SAPI/extension combinations; add CI that runs the new adversarial tests, not just the original suite. Feature expansion should not replace these gates.

## 11. Reproduction

Normal development commands (all local to the checkout):

```sh
./scripts/bootstrap-local.sh                 # explicit local .deb extraction; no sudo
./scripts/build-local.sh
# Install locked Composer dependencies using the PHP binary with asyncara loaded,
# or --ignore-platform-req=ext-asyncara for Composer's dependency installation only.
./scripts/test.sh
python3 scripts/audit.py
./scripts/audit-allocations.sh
./scripts/test-databases.py
ASYNCARA_AUDIT_FATAL_PG=1 ./scripts/test-databases.py --test-file tests/audit/databases.php
python3 scripts/audit-fpm.py
./scripts/sanitize.sh
./scripts/thread-sanitize.sh
./scripts/valgrind.sh
# Preserve the original audited artifact when packaging fixes:
python3 scripts/package.py --include-untracked --output build/asyncara-0.1.0-dev-audit1.tar.gz
ASYNCARA_BACKEND=poll ./scripts/test.sh
./scripts/phpize-local.sh
ASYNCARA_EXTENSION=ext/modules/asyncara.so ./scripts/test.sh
```

Database runner requires local PostgreSQL initdb/pg_ctl, MariaDB server/install-db and Redis executables and starts only project-owned fixtures. It cannot run concurrently with another invocation using the same fixture directories. Valgrind requires locally extracted matching Valgrind/libc debug packages as described in SECURITY.md. The audit allocation wrapper is a **test binary only**, not production allocation policy. Slow-I/O LD_PRELOAD injection is limited to explicitly spawned audit processes.

Clean original archive workflow:

```sh
sha256sum build/asyncara-0.1.0-dev.tar.gz
archive=$(realpath build/asyncara-0.1.0-dev.tar.gz)
audit_dir=$(mktemp -d /tmp/asyncara-distribution-XXXXXX)
tar -tzf "$archive"
tar -xzf "$archive" -C "$audit_dir"
cd "$audit_dir/asyncara-0.1.0-dev"
./scripts/bootstrap-local.sh
./scripts/build-local.sh
composer install --no-interaction --ignore-platform-req=ext-asyncara
./scripts/test.sh
./scripts/phpize-local.sh
```

Select another isolated PHP/header pair with `PHP_BIN`, `PHP_INCLUDE_DIR` and `ASYNCARA_OUTPUT=build/asyncara83.so` for build-local.sh; use `PHP_BIN` and `ASYNCARA_EXTENSION` for test.sh/test-databases.py. The 8.3/8.4 API header subdirectories here were 20230831/20240924. Copy the resulting module into a private prefix and load it with an explicit private php.ini; never install a module compiled for another branch.

Debug PHP configure: `--disable-all --enable-cli --disable-cgi --enable-debug --enable-filter --with-openssl --prefix=<isolated-prefix>`, then `make -j3 && make install`. The ASan/UBSan build additionally uses CFLAGS/LDFLAGS `-fsanitize=address,undefined -fno-omit-frame-pointer`, `--disable-phpdbg --disable-opcache-jit`; rebuild Asyncara with the matching installed headers and sanitizer compile/link flags. Normal instrumentation uses USE_ZEND_ALLOC=0; fatal-limit tests deliberately override it to 1. Inspect raw diagnostics and exit status; do not interpret a missing memory-limit trigger as a successful exhaustion test.

Final assessment remains **EXPERIMENTAL**, with substantial verified functionality and a bounded, documented audit scope. Passing these workloads does not establish production readiness.

## 12. Final Git inventory

HEAD remains `f11c892b2a5b9ccae492772565f46211863b2894`. These changes are uncommitted; no merge, commit or publication was performed. Build/archive/toolchain products are ignored.

```text
 M Makefile
 M README.md
 M WORKLOG.md
 M docs/COMPATIBILITY.md
 M docs/LIMITATIONS.md
 M docs/ROADMAP.md
 M docs/SECURITY.md
 M ext/mysql.c
 M ext/php_asyncara.c
 M ext/postgresql.c
 M scripts/package.py
 M scripts/test-databases.py
 M scripts/test-fatal-memory.py
 M src/Asyncara/Async.php
 M src/Asyncara/MySQL/Connection.php
 M src/Asyncara/Pool/ConnectionPool.php
 M src/Asyncara/Pool/ProcessPool.php
 M src/Asyncara/PostgreSQL/Connection.php
 M src/Asyncara/SQLite/Connection.php
 M src/Asyncara/Scheduler.php
?? docs/INDEPENDENT_AUDIT.md
?? docs/audit/archive-manifest.json
?? docs/audit/evidence.json
?? scripts/audit-allocations.sh
?? scripts/audit-fpm.py
?? scripts/audit.py
?? tests/audit/allocations.c
?? tests/audit/cpu_jobs.php
?? tests/audit/databases.php
?? tests/audit/fatal_database.php
?? tests/audit/fatal_pg.php
?? tests/audit/fpm_request.php
?? tests/audit/hooks.php
?? tests/audit/io.php
?? tests/audit/io_server.py
?? tests/audit/process_cpu.php
?? tests/audit/regressions.php
?? tests/audit/slow_files.php
?? tests/audit/slow_io.c
```
