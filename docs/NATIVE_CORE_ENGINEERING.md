# Native core engineering: accepted-close ownership

Historical snapshot at `6ee73d8`: 2026-11-07 UTC.
Subsequent isolated build, baseline negative controls, PHP/backend matrix and
memory checks are recorded in [FAST_RELIABILITY.md](FAST_RELIABILITY.md).
The unbuilt statements below describe the original protected-campaign checkpoint.

Snapshot: 2026-11-07 UTC. Release classification: **EXPERIMENTAL**.
This is the isolated `phase5-native-core` development track. The C patch and
new regression are **NOT_YET_BUILT / NOT_YET_TESTED**. Source inspection is not
a runtime reproduction, compiler check, sanitizer pass, or endurance result.

## Checkpoint and isolation

| Identity | Value |
|---|---|
| Frozen base | `d74f3b3d4e36a3280aa57d3b4c3bb8c1d9765e15` |
| New branch | `phase5-native-core` |
| Independent worktree | `/home/coder/Asyncara-native-core-20261107T013453Z` |
| Initial worktree status | Clean |
| Protected main checkout | `/home/coder/Asyncara`, `phase4-endurance` |
| Main HEAD at entry | `6a7e0f45cb64577b3ba0cf30e160bd98901e4d90` |
| Private build directory | `build/` in this worktree; only `native-plan.json`, no binary |
| Planned module | `build/asyncara-native-core.so` in this worktree |
| Frozen campaign module SHA-256 | `ed8a74a382094a210c14e2cffe60c51c0051d8b1b5a14a719fa3cceea221ae0c` |

[native-baseline.json](phase5/native-baseline.json) records the 382-file base
manifest, all 11 `ext/` paths and hashes, initial status, protected-file hashes,
and campaign state. The SHA-256 of each manifest encoded with Python
`json.dumps(manifest, sort_keys=True, separators=(',', ':')).encode()` is:

* Entire base: `b66c2805c4e7ca459aceb10101303e28fcd4fe8153ded7f86a1442954b163cc5`.
* Native base: `3248be27f29c0e36075ec306c163e29c45954ec800c70de5300f586aaa39558d`.

The linked worktree shares Git object storage and references, but has its own
index, source files and build path. No `.local`, `vendor`, generated module,
build output or mutable test artifact was copied or symlinked from Phase 4.
The three untracked reviewer-repair files already in the main checkout were
preserved byte-for-byte. No active branch switch or campaign change was made.

## A. Native source architecture map

This map follows implementations, including the PHP-owned parts of the runtime.
There is no separate C Fiber scheduler hidden behind the public API.

| Subsystem | Native source files | Main functions and PHP boundary | Current behavior | Limits / remaining questions |
|---|---|---|---|---|
| Module/request lifecycle | `ext/php_asyncara.c` | `PHP_MINIT/MSHUTDOWN/RINIT/RSHUTDOWN_FUNCTION(asyncara)`, `get_engine()` | Global curl/optional Connector initialization; request-local `ZEND_TLS` engine and hook flag; lazy engine allocation; joins workers before releasing engine | Native joins can wait for an executing blocking filesystem/resolver call; no arbitrary hard shutdown deadline |
| Zend values and resources | `ext/php_asyncara.c`, `php_asyncara.h`, `driver_arginfo.h` | `copy_value()`, `export_value()`, `ar_php_string_*()`; PG/MySQL resource destructors | Copies bounded zvals into C-owned data before submission; converts results on interpreter thread; lifetime allocation list survives bailout; version-aware fallible string conversion | No Zend calls in worker execution; no custom native PHP object handlers or native Fiber creation. Not a universal OOM recovery guarantee |
| Scheduler, Tasks, Futures | `ext/php_asyncara.c`, `backend.c` provide poll/completion boundary | PHP `Scheduler::spawn/turn/suspend/poll/run`, `Task::step`, `Future::settle/await`, `TaskGroup::finish` | PHP native Fibers; FIFO `SplQueue`, up to 256 ready steps/turn; one-shot resumption closure; exceptions retained in Futures; groups cancel/join siblings | Cooperative scheduling cannot preempt a CPU-bound PHP callback; nested `Async::run()` rejected; C does not own Task/Fiber references |
| Timers and wait deadlines | `ext/backend.c` | `ar_wait()`, PHP `Scheduler::delay/timers/poll` | PHP monotonic `hrtime()` heap, millisecond ceiling for wait; combines curl timer with scheduler deadline; completion/buffered data forces zero wait | No C timer heap. EINTR returns to PHP so its deadline is recalculated, rather than restarting the full wait |
| epoll / poll | `ext/backend.c` | `wait_fds()`, `ar_wait()` | Transient epoll instance per wait or poll; merges PHP read/write watches by fd; maps HUP/ERR; pipe wakes completion; frees temporary wait buffers | Descriptors borrowed, not owned; invalid-fd behavior differs between backends; no kqueue/IOCP/io_uring; no performance bottleneck measured here |
| TCP / explicit TLS | `ext/php_asyncara.c`, `backend.c`; PHP stream implementation | `asyncara_poll()` casts stream descriptor and observes buffered bytes; PHP `TcpStream::connect/read/write/close` | PHP nonblocking streams and async connect; numeric addresses after worker DNS; explicit crypto handshake; readiness suspends Fiber; short reads/partial writes handled in PHP | TLS waits use stream API, not an Asyncara-owned OpenSSL state machine. Trust-store loading and certificate/CPU work are not guaranteed asynchronous |
| UDP | `ext/php_asyncara.c`, `backend.c` | `asyncara_udp_bind()`, PHP `UdpSocket` | Registers PHP stream owner before socket acquisition; numeric IPv4/6 bind; CLOEXEC/nonblocking fd; readiness before send/receive | PHP stream owns final close; one bounded datagram per call; no dedicated C UDP scheduler |
| DNS | `ext/workers.c` | `do_dns()` | `getaddrinfo()` in bounded native worker pool, IPv4/IPv6 copied into native array | WORKER_BACKED_ASYNC, not event-driven DNS; executing resolver call cannot be forcibly interrupted; no TTL-aware cache |
| HTTP | `ext/backend.c`, `php_asyncara.c` | `ar_http_start/progress`, `socket_changed`, `receive_buffer`, `send_body`, `asyncara_http_head/read/write` | Request-local curl multi; readiness + curl timers; callback buffers and pause/resume; client permit held through completion/drain; TLS verification enabled | EVENT_DRIVEN_LIBRARY network waits; streaming/upload forced HTTP/1.1; 64 KiB native stream buffers, 64 KiB headers, 16 MiB buffered body; some CURLM return codes unchecked |
| File operations | `ext/workers.c`, `php_asyncara.c` | `capture_path`, `asyncara_submit`, `do_file/do_handle/do_atomic_write` | Worker syscalls; stable file IDs; per-handle busy state; reads return up to requested bytes; writes loop over short writes/EINTR; same-directory atomic replacement and optional fsync | Regular files only; `O_NONBLOCK` is not async disk I/O. Four shared workers, not four per capability. Path policy checks occur on PHP thread; path races remain |
| SQLite | `ext/workers.c`, `php_asyncara.c` | `do_sqlite`, `ar_db_find`; PHP `SQLite\Connection` | FULLMUTEX connection; native busy gate plus PHP serialization/transaction ownership; prepared/bound statement per query; progress callback reads atomic cancellation flag | WORKER_BACKED_ASYNC; jobs may use different workers sequentially, not a dedicated thread per connection; one-second busy handler; no prepared-statement cache |
| PostgreSQL | `ext/postgresql.c` included by PHP bridge | `asyncara_pg_open/poll/query/read/close`, `pg_close_handle/destroy` | `PQconnectStartParams/PQconnectPoll`, `PQsetnonblocking`, `PQsendQueryParams/PQflush/PQconsumeInput/PQisBusy`; single-row results; resource retains result/notification before PHP conversion | Native nonblocking network state machine; max 256 rows per collection turn; local close is not remote cancellation acknowledgement; libpq foreign allocations outside ledger |
| MySQL / MariaDB | `ext/mysql.c`, `compat/mariadb/ma_hash.h` | `asyncara_mysql_open/query/step/close`, `my_release_query`, `my_close`, `my_verify_tls` | Connector/C start/cont for connect, prepare, execute, fetch, control and close; resource-owned buffers; strict supported OpenSSL3 callback verifies numeric IP identity before auth | Native nonblocking network state machine; max 128 rows/turn; no statement cache; optional feature/API requirements; Oracle/MariaDB evidence must remain separate |
| Redis / WebSocket | No dedicated Asyncara C protocol parser; shared stream readiness | PHP `Redis\Connection`, `WebSocket` classes over `TcpStream` | Protocol parsing, limits and serialization in PHP; native readiness via stream bridge | Do not describe these as standalone C drivers; unsupported protocol features remain unsupported |
| Native workers | `ext/workers.c`, `native.h` | `ar_submit`, `worker`, `ar_done`, `ar_collect`, `ar_engine_free` | Lazy four-pthread pool; mutex/cond FIFO; copied data only; asynchronous signals masked at creation; done list + nonblocking pipe; join before final release | 1,024 pending jobs; cooperative cancellation; no arbitrary PHP callback execution; accepted-close cancellation gap addressed by this untested patch |
| ProcessPool | No separate C processpool module; shared readiness boundary | PHP `Pool\ProcessPool`, `Internal\IpcFrame`, `bin/worker.php` | `proc_open` argument array, framed IPC, explicit job descriptors, queue/byte bounds, separate processes | MULTIPROCESS_PARALLEL. Process spawning/reaping/destruction may block briefly; not userland threads |
| Native memory | `ext/memory.c`, `native.h`, `workers.c` | `reserve`, `ar_alloc/calloc/realloc/free`, `ar_value_free`, `ar_job_free` | Atomic aggregate accounting; small malloc/realloc, >=1 MiB dedicated mmap; overflow guards; failed resize preserves old ownership; result/job allocation list | Default 256 MiB ledger tracks Asyncara payload allocations, not all curl/SQLite/libpq/Connector/OpenSSL memory, stacks or bounded wait-control calloc buffers |
| Optional hooks | `ext/php_asyncara.c` | `execute_hook`, `hooked_sleep`, `asyncara_hooks` | `zend_execute_internal` chaining; request opt-in `sleep/usleep` dispatch inside Fiber | No stream/file/curl/PDO/MySQLi/Redis hooks. Native API First remains unchanged |

The `ext/` inventory consists of the eleven files in the baseline manifest.
`config.m4` compiles `php_asyncara.c`, `workers.c`, `backend.c`, `memory.c`;
optional PG/MySQL implementations are included by the PHP bridge. The MariaDB
shim only fills an unused missing header include, not a copied struct layout.

### PHP/build requirements

The C bridge explicitly accepts `80300 <= PHP_VERSION_ID < 80600`; Composer
requires PHP `>=8.3 <8.6`. Build each module against its matching PHP API, build
flags and NTS SDK. The frozen campaign uses distribution PHP 8.5.12 NTS; prior
8.3/8.4/debug results do **not** validate this patch. ZTS interpreter concurrency
and userland ThreadPool remain uncertified/blocked.

Build requirements in `config.m4` and `scripts/build-local.sh`: C11 compiler,
pthreads, SQLite and libcurl development headers/libraries; optional libpq >=12,
MariaDB Connector/C >=3.1 and OpenSSL >=3.0. Verified Connector TLS additionally
requires the callback/status feature API. curl async DNS is checked at runtime;
HTTP uses APIs such as `CURLOPT_PROTOCOLS_STR`, so a configure symbol probe alone
does not certify every library version. Flags include `-Wall -Wextra -Werror`.
No SDK was installed and no compiler was invoked in this track yet.

## B. Native API execution traces

### Scheduled Fiber and timer

`Async::run` → `Scheduler::spawn` → `new Task` → `new Fiber` → ready FIFO →
`Task::step` → `Fiber::start` (later `resume`). `Async::sleep` registers a PHP
monotonic timer through `Scheduler::suspend`; `Fiber::suspend` yields only the
Task. `Scheduler::poll` calls `asyncara_poll` → `ar_wait` → epoll/poll with the
nearest timer deadline. Back in PHP, `timers()` invokes the guarded resume
closure, enqueues the Task once, and a later step resumes it. Task-body exceptions
reject its Future. No worker or curl callback enters a PHP Fiber.

### Native network operation

`TcpStream::read` first attempts nonblocking `fread`. No bytes and no EOF →
`Scheduler::readable` → watcher closure + `Fiber::suspend`. `asyncara_poll`
extracts the stream fd, records buffered/eof state and calls `ar_wait`.
`wait_fds` maps read/error/hangup readiness back to the watcher key. PHP removes
that watcher and enqueues its Task, which retries `fread`. Close first calls
`Scheduler::closed` to wake/remove its waiters, then PHP closes the stream.

For HTTP, `HttpClient::request` instead schedules a Task and obtains a permit →
`Scheduler::native('http')` → `asyncara_submit` copies input into `ar_job` →
`ar_http_start` attaches curl easy to multi. `ar_wait` drives socket actions and
curl timers → `ar_http_progress` detaches a completed transfer → `ar_done` →
`ar_collect` → `export_value` → PHP Future resolution. Client cancellation drains
completion before releasing the permit. Files used by HTTP remain worker-backed.

### Worker-backed file operation

`File::write` schedules a Task, takes its semaphore, then submits
`Scheduler::native('file_write')`. `asyncara_submit` validates the ID/busy state
and copies bytes. `ar_submit` marks the file busy, links the job and queues it.
The awaiting Fiber suspends. A worker calls `do_handle` → `write_all`; it uses
only C memory and syscalls. `ar_done` publishes under the mutex and writes the
wake pipe. `ar_wait` wakes; `ar_collect` detaches completion and clears busy;
`export_value` copies the result into a PHP value and `ar_job_free` releases the
native buffers. `Scheduler::poll` resolves/rejects the Future, enqueues the Task,
and its shielded join releases the semaphore only after native completion.

SQLite follows the same job/completion path, with `do_sqlite` and its connection
busy gate. Transaction ownership/ordering also depends on the existing PHP gates.

## C. Ranked engineering opportunities

| Rank | Source-backed issue | User-visible benefit / expected impact | Complexity / risk | Required evidence | Isolated development |
|---|---|---|---|---|---|
| 1, selected | `worker()` skips every cancelled job, including `OP_FILE_CLOSE/OP_DB_CLOSE`, and overwrites actual close outcomes if cancellation arrives during execution | Release accepted cleanup deterministically; retain genuine close errors; prevent successful close being reported merely as cancellation | Small C dispatch change; semantic risk limited to already accepted closes | Gate workers before dequeue; cancel real queued job; check fd/connection release, failure retention, duplicate completion, allocation rejection, ordinary-write negative control; PHP lifecycle regressions later | Yes; source-only now |
| 2, deferred | `ar_http_start/ar_wait` discard several `curl_multi_setopt/socket_action/timeout` return codes, unlike checked easy options and resume calls | Explicitly propagate multi-state failures and avoid stranded transfers when curl reports a multi error | Medium; detachment/completion of multiple easy handles must not duplicate/free live jobs | Link-time fault injection, simultaneous transfers, cancellation, foreign allocation failure; establish which errors require whole-multi recovery | Yes for design; no runtime experiment performed |
| 3, deferred | `wait_fds` returns failure for epoll registration of an invalid fd; poll returns `POLLNVAL`, which `ar_wait` marks ready | Consistent diagnosis and cleanup when a borrowed descriptor is invalidated; avoid backend-dependent failure handling | Medium; decide API/error policy before implementation; cannot transparently detect numeric fd reuse | Closed-fd controls, fd reuse, simultaneous reader/writer, EOF/HUP, cancellation, remaining native completion drain in both backends | Yes for design; behavior inferred from branches, not reproduced in this track |

These are correctness opportunities, not claimed measured performance bottlenecks.
The unhandled wake-pipe `write` EINTR case was considered but not selected:
workers already block asynchronous signals, so a real trigger needs investigation.
No candidate is attributed to the historical FPM zero-ticker failures.

## D. Selected C implementation

Only production native file changed: [ext/workers.c](../ext/workers.c), `worker()`.
No PHP API, scheduler, hook, backend or acceptance fixture changes.

At the base, a queued close cancelled before dequeue takes `stopped(j)` and
never enters `do_handle/do_sqlite`. Collection clears busy but retains its open
handle. Request teardown eventually closes it, so this is not a proven permanent
process leak. It is a timely-release and observable-result gap: `File::close`
marks its PHP object closed in `finally`, and exceptional `Scheduler::run` drain
can call `cancel()` on native Futures even when their Task is shielded.
Cancellation after a successful close can also replace success with a cancelled
result, leaving SQLite's PHP `closed` state unset. An actual close error can be
overwritten by cancellation. These control-flow observations still require the
prepared dynamic reproduction before calling the correction verified.

The patch recognizes file/SQLite closes as mandatory cleanup after acceptance:
it executes them despite the cancellation flag and returns the actual close
outcome without cancellation substitution. Task cancellation before submission
is unchanged. A caller cannot use operation cancellation to retain a handle
after its close has been submitted. Successful cleanup may win cancellation.

Preserved invariants:

1. The validated handle stays busy from submission through collection; no early
   release or concurrent operation is admitted by the PHP bridge.
2. Existing worker dispatch, queue locks, one completion owner and wake mechanism
   are unchanged. No Zend access or new worker context is introduced.
3. File close invalidates its fd even on a late close error, as before. Collection
   removes/frees only invalidated file owners; it does not close a reused fd.
4. Failed SQLite close retains its connection for retry/request cleanup. Successful
   close nulls it; collection removes/frees that owner exactly once.
5. Result allocation failure after close retains the existing error path; closed
   native handles still leave the registry and no cancelled result hides it.
6. Reads, writes, opens, DNS and queries keep their cancellation behavior. Neither
   cancellation nor this patch rolls back writes or forcibly interrupts syscalls.

Regression: [tests/audit/close_cancellation.c](../tests/audit/close_cancellation.c).
A test-only linker wrapper gates pthread entry; the real `ar_submit/ar_cancel`
run before the gate releases real workers. No production instrumentation, sleeps
to manufacture a race, or FPM interaction is involved. Six scenarios per backend:
successful cancelled file close; EBADF preservation; result-allocation rejection;
successful cancelled SQLite close; SQLITE_BUSY preservation and retry; ordinary
cancelled write leaves contents intact. All assert single collection and native
allocation/registry cleanup. File and SQLite owners are constructed by the C test;
this does not substitute for later PHP wrapper/lifecycle validation.

[scripts/test-native-close.sh](../scripts/test-native-close.sh) prepares a unique
build directory and ASan/UBSan execution. CI has one additional isolated test
step; existing assertions and steps are unchanged. The script and CI step have
not executed. No remote build was triggered.

## E. Validation state and exact next commands

| Activity | Actual state |
|---|---|
| Native source inspection and execution tracing | Completed for inventory; not universal safety certification |
| C patch and focused regression written | Source changes made; IMPLEMENTED_BUT_NOT_FULLY_TESTED |
| `git diff --check` | Passed for the current worktree diff |
| Shell script static syntax | See final integrity record; no script execution |
| Baseline failure reproduction | NOT_YET_TESTED; gate-based negative controls prepared |
| Native compiler/build | NOT_YET_BUILT |
| PHP 8.3/8.4/8.5, epoll/poll runtime regressions | NOT_YET_TESTED on this patch |
| ASan/UBSan/TSan, DEBUG PHP, Valgrind | NOT_STARTED on this patch |
| Benchmarks / endurance / package | NOT_STARTED; no benefit or safety claim inferred |

After the protected FPM supervisor reaches a verified terminal state, provision
an independent SDK/sysroot inside this worktree (never reuse mutable build
outputs), then run the following from this worktree. These commands are prepared,
not executed:

```bash
# Historical source negative control, unique output; keep every failure log.
case_dir=$(mktemp -d "$PWD/build/close-control-XXXXXXXX")
git show d74f3b3d4e36a3280aa57d3b4c3bb8c1d9765e15:ext/workers.c > "$case_dir/workers-base.c"
cc -std=c11 -D_GNU_SOURCE -g -O1 -Wall -Wextra -Werror -Iext \
  -I.local/sysroot/usr/include -I.local/sysroot/usr/include/x86_64-linux-gnu \
  tests/audit/close_cancellation.c "$case_dir/workers-base.c" ext/backend.c ext/memory.c \
  -pthread -Wl,--wrap=pthread_create -l:libsqlite3.so.0 -l:libcurl.so.4 \
  -o "$case_dir/base-close"
# Execute individually with exit-code capture: file/sqlite must expose the gap;
# control must pass. Do not run under a parent shell configured to exit on error.
"$case_dir/base-close" file > "$case_dir/file.log" 2>&1
"$case_dir/base-close" sqlite > "$case_dir/sqlite.log" 2>&1
"$case_dir/base-close" control > "$case_dir/control.log" 2>&1

# Patched C, both backends, with sanitizers and no suppressions.
bash scripts/test-native-close.sh

# Matching PHP and independent local SDK must be selected for each PHP branch.
ASYNCARA_OUTPUT=build/asyncara-native-core.so ./scripts/build-local.sh
ASYNCARA_EXTENSION=build/asyncara-native-core.so ./scripts/test.sh
ASYNCARA_BACKEND=poll ASYNCARA_EXTENSION=build/asyncara-native-core.so ./scripts/test.sh
```

Record exit codes explicitly for each negative control. Then run the affected
audit/database/shutdown suites, DEBUG PHP, native TSan and appropriate Valgrind
controls on new binaries. Do not run this worktree's FPM suites alongside Track A.
The distribution-PHP Valgrind discrepancy remains a separate unresolved gate.
Do not merge/install/publish this patch based only on static inspection.

## F. Phase 4 integrity and separate reviewer work

Final read-only fingerprints/process checks are recorded in
[native-integrity.json](phase5/native-integrity.json). The active supervisor,
runner start identities, module, acceptance harness and fixture are protected.
The original one-hour supervisor status remains PASS; six-hour was RUNNING
during this work. Twenty-four-hour had not started. Those outcomes never certify
this new C source, which has no built binary.

The earlier reviewer repair completed separately at
`/tmp/asyncara-review-ordering-20261107T013022Z/hour-review-r1/report.json`, SHA-256
`35a158267c6d483f2334441371a554539c3247ec4e404a03496a9c5acc2c38e1`.
It independently rechecked 48,124 main responses, minimum two ticker advances,
48,116 resource samples, 3,600.126033915207 workload seconds and original bounds.
Its measurable acceptance checks PASS, but overall review remains INDETERMINATE
for complete clock chronology: 85 same-worker pairs inside batches lack an
independent ordering field. It does not sort by the clock under test or relabel
the original reviewer failure. Sixteen reviewer unit tests passed before this
native track; they are not tests of the C patch. Original logs, failed review and
reviewer process remain unchanged. No Valgrind, fuzzing, benchmark, build or
additional FPM campaign was started.
