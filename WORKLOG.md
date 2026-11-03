# Engineering work log

## 2026-11-02 — Environment and architecture

Inspected empty `/home/coder/Asyncara`, parent instructions (none found), OS/CPU, PHP versions/modules/phpinfo, compiler/linker, build tools, library headers/runtime libraries and package candidates. `phpize` and `php-config` initially unavailable. No existing repository work to overwrite.

Commands: `uname -a`, `lscpu`, `getconf`, `php -v/-i/-m`, `php --ri swoole`, `pkg-config`, `ldconfig -p`, `apt-cache policy`, header searches; read upstream PHP support table, Fiber docs, concurrency RFC and driver documentation. Downloaded source excerpts for inspection under ignored `.local/research`. Downloaded development packages using `apt download`, extracted with `dpkg-deb -x` into `.local/sysroot`; no global installation.

Created research, architecture and explicit roadmap. Chosen PHP 8.3–8.5 target, test first on installed 8.5.11 NTS; PHP 8.5.12 dev headers have matching module API but patch mismatch is recorded. Native worker threads never enter Zend. Next: compile/load minimal extension, then implement and test core and drivers.

## 2026-11-02 — Native engine, core and first integration milestone

Implemented a single compiled native extension with request-owned bounded pthread workers, epoll/poll readiness, completion pipe, copied native values, SQLite C API binding and cancellation, curl multi socket integration, and chained opt-in execute-internal timer hooks. Added strict-typed Composer/autoload library: Fiber scheduler, FIFO ready batches, monotonic timer heap, Futures, Tasks, cancellation, deadlines, groups, context, semaphore/lock/event/queue, TCP/UDP/DNS/TLS clients, filesystem operations/chunk streams, HTTP client, SQLite connection ordering, async producer iteration and framed process workers with a job registry.

Commands: `./scripts/build-local.sh` (GCC `-Wall -Wextra -Werror`), `php -n ... --ri asyncara`, syntax checks, `./scripts/test.sh`. Result: **39 passed, 0 failed** on PHP 8.5.11 NTS with isolated extensions. First 10,000-Fiber run exceeded PHP's default 128 MiB limit; the stress-capable test runner now explicitly uses 1 GiB (no test removed). Initial failures identified two test closure captures and SQLite interruption classification; fixed and reran. Cancellation race tests verify removed queue/semaphore waiters cannot consume resources. Fixed SQLite connection metadata retention and same-file copy truncation before passing.

Tests include 10,000 timers/tasks, repeated awaits, task/group failure, cleanup, cancellation races, bounded producer/consumer contention, binary/large files, DNS failure, 3 MB TCP partial writes, UDP empty datagrams, deterministic Python HTTP/TLS fixtures, HTTP concurrency/chunking/compression/status/errors/cancellation, trusted and untrusted certificates, SQLite binding/transactions/interruption, worker crashes/replacement and overlapping CPU intervals on distinct PIDs.

A separately compiled liburing NOP probe succeeded (`NOP completion: 0`). This is kernel evidence only, not an implemented Asyncara filesystem backend. Runtime continues reporting ioUring=false. Next: additional driver coverage, reproducible phpize build, native memory instrumentation, stress/leak checks, benchmarks, API and limitation documentation.

## 2026-11-02 — Additional drivers, version matrix and instrumentation

Implemented native nonblocking libpq connection/query polling, parameterized extended-protocol results, notifications, and MariaDB Connector/C start/cont connection and prepared-statement state machines. Added RESP2/RESP3 Redis, pipelines, transactions and dedicated Pub/Sub connections; added bounded scoped connection pools. Local DB fixtures use project directories, private Unix sockets for SQL servers and loopback Redis; host services are untouched. PostgreSQL/MariaDB errors and cancellation discard the connection to prevent unsafe protocol reuse; no automatic query replay. MariaDB TLS currently requires a numeric address matching the certificate SAN and is not yet integration certified.

Executed `scripts/test-databases.py`: PostgreSQL/MariaDB large values, bindings, transaction rollback, server-timestamp overlap on independent connections, cancellation, errors and repeated close; PostgreSQL notifications; Redis RESP2/RESP3 binary values, pipelines, errors, transactions, Pub/Sub and cancellation passed.

Downloaded/extracted PHP CLI and dev packages for 8.3.36 and 8.4.27 into separate local prefixes. Both compiled with warnings-as-errors and passed all 39 core/integration cases. Installed 8.5.11 also passed. No global PHP alternative or configuration changes.

Created and executed standalone native ASan/UBSan lifecycle harness: PASS, including shutdown with queued native work. Ran 100 mixed SQLite/filesystem/queue runtime cycles: descriptors stabilized at 7 (5 before lazy engine initialization); PHP live memory samples about 720–723 KiB. Core PHP/native Valgrind run with Zend allocator disabled: zero errors, zero definite/indirect/possible lost bytes. Extracted matching glibc debug symbols locally to permit Valgrind startup; no system debug installation.

Database Valgrind revealed missing MariaDB statement disposal on interrupted/error operations and missing library teardown. Inspected actual connector source: mysql_close invalidates but does not free statement handles. Added explicit statement disposal after connection invalidation and paired library initialization/shutdown. Native losses resolved; remaining PHP-object cycle losses are under investigation (not suppressed). Timing tests were changed to compare overlapping server execution intervals after pool warmup, so instrumentation overhead is not confused with serialization.

Relocated extracted autoconf/phpize tooling; `scripts/phpize-local.sh` now configures, compiles and loads the extension. Optional libpq and MariaDB configure switches added. PSR-18 adapter and Composer PSR interfaces added; adapter validation is pending. Next: resolve remaining lifecycle findings, full regression, benchmarks, examples and release documentation.

## 2026-11-02 — Regression, request lifecycle and native memory limits

Expanded the core suite to **44 passing cases**, including PSR-18, explicit verified TLS, early async-stream iteration exit, HEAD requests, abandoned native-operation cancellation and simultaneous sockets. Added native queue/buffer/parameter/permission tests and repeated HTTP/TLS/TCP lifecycle tests. Database boolean parameters now preserve connector semantics. The comprehensive local concurrency tour executes successfully, including distinct CPU worker PIDs.

Fixed generator finalization to avoid Fiber suspension from a destructor. Database Valgrind's remaining PHP-object cycle losses disappear with explicit cycle collection at workload boundaries; no suppression was added. Final core, network, request-shutdown and local DB Valgrind runs before the allocation-ledger change reported zero errors and zero definite/indirect/possible lost bytes.

Formatting exposed an include-order dependency: PHP headers must precede Zend headers. Corrected include order and disabled automatic include sorting. A shell sequence initially continued into tests after compiler failure, using an older binary. Those results are excluded as source validation. All dependent verification now uses fail-fast scripts, and `scripts/check-build.py` rejects binaries older than any native source/header. Rebuilt current source with warnings-as-errors for PHP 8.3.36, 8.4.27 and 8.5.11, and via phpize; all passed 44 cases plus native limits. Each CLI version passed the isolated PostgreSQL/MariaDB/Redis suite. epoll, poll and the host's installed extension combination passed. phpize emits the upstream obsolete `AC_PROG_LIBTOOL` macro notice; no C compiler warnings were ignored.

Added `scripts/test-fpm.py`: 100 requests through one isolated PHP-FPM worker, with timers, files, SQLite, context checks, hooks and intentionally undrained native jobs. Worker PID remained constant, native threads returned to one, idle descriptors stayed at six and RSS plateaued. The initial sampler caught a transient descriptor between END_REQUEST and final cleanup; it now samples after the worker returns to accept(), without weakening the descriptor assertion.

Added `ext/memory.c`: atomic per-request accounting for retained Asyncara-owned native allocations, a default 256 MiB aggregate budget configurable before submission, rejection/peak/live counters, overflow checks and explicit ownership. Control-plane wait buffers have an independent fixed upper bound and remain available for draining under budget pressure. Third-party library internals are excluded and documented. Native-limit tests verify rejection, successful draining/recovery and return to zero. Lifetime tracking now also retains unsubmitted/collected jobs until their actual destruction, permitting RSHUTDOWN cleanup after Zend memory-limit bailout; a child-process fatal-memory test passes.

The network Valgrind workload exposed 25.7 GB of cumulative allocation requests from repeatedly resizing a 16 MiB HTTP buffer. Changed receive buffers to geometric growth; validation of the new total is pending. Native standalone ASan/UBSan and TSan pass after the allocation changes. GCC TSan startup conflicts with randomized mappings on this host (PIE and some non-PIE runs); `setarch x86_64 -R` is applied only to the test process, with no global ASLR change. No sanitizer finding is suppressed.

Commands: `scripts/test.sh`, `scripts/test-databases.py`, `scripts/phpize-local.sh`, `scripts/valgrind.sh`, `scripts/sanitize.sh`, `scripts/thread-sanitize.sh`, `scripts/test-fpm.py`, `benchmarks/run.py`, Composer install/validation. Native allocation and HTTP-buffer changes require a final version/phpize/Valgrind regression and fresh benchmark provenance before final documentation.

## 2026-11-02 — Stable files and expanded verification

Implemented request-owned native file handles (128 limit), worker-backed open/read/write/seek/stat/fsync/close, per-handle serialized operations and reclamation on failed/cancelled open, explicit close and request shutdown. Added IO/File.php and Files::open. File read/write streams now keep one descriptor with bounded producer backpressure; rename/replacement/unlink no longer causes subsequent chunks to switch files. Async::using shields acquisition until its result can be released, then observes cancellation before entering the body. PHP handles reject cloning/serialization. Fixed SQLite open-result allocation/cancellation cleanup as part of the same ownership review.

New dedicated tests verify 100 ordered writes, seek/EOF/sync, path replacement/unlink, append/exclusive modes, bad modes/special files, the handle cap, cancelled open, early stream exit, zero retained native allocations, and cancellation during acquisition. The initial acquisition test used a 1 ms timeout that could expire before the task even entered acquisition under Valgrind; replaced that timing assumption with explicit Event barriers. No failure was disabled.

All 44 core cases plus four dedicated suites (native limits, native budget, PHP memory-limit bailout and stable handles) pass on PHP 8.3, 8.4 and 8.5 NTS, the phpize binary and poll fallback. Local DB suites pass on all three CLI versions. The normal host extension configuration passes all 44 core cases. A separate NDEBUG build compiles with warnings-as-errors and passes the complete test runner; the accounting code also compiles without assertion-only unused variables.

ASan/UBSan and TSan harnesses pass with allocator overflow/reallocation failure and stable-handle lifecycle cases. Final Valgrind workloads for core stress, pending request shutdown, HTTP/TLS/TCP, memory-budget exhaustion, stable files and PostgreSQL/MariaDB/Redis all report zero errors and zero definite/indirect/possible lost bytes, no suppressions. Native network cumulative allocation requests fell from 25,675,053,334 to 150,226,313 bytes after geometric buffer growth; this is not peak RSS or a wall-time speedup claim.

Repeated 100 mixed runtime cycles: descriptors stayed at seven after lazy initialization; live PHP memory samples grew about 3.8 KiB including the sampling array. Repeated 100 FPM requests through one worker (including deliberately uncollected open files/databases): six idle descriptors, one idle thread and stable RSS. Complete tour, 1,000-task example and four-process CPU example execute successfully. Seventy PHP files passed syntax checks; Python fixtures/scripts compile; Composer strict validation passes with the development root version specified.

Updated API/reference, driver, backend, security, compatibility, architecture, installation and roadmap docs. Rechecked scheduler RFC status (still Under Discussion), and found/inspected actual OpenSwoole runtime source at revision 748bdcbf2ccb960ad06bd94d099d9ab353a165ec after the earlier incorrect source path. No upstream source was copied into the implementation.

Fresh benchmarks retain three samples per workload, binary/source hashes, dependencies and host facts in benchmarks/results/latest.json. docs/PERFORMANCE.md reports every sample and the slower cached-file results, including missing instrumentation and comparison limits. The benchmark matrix remains incomplete; no universal performance or production-readiness claim is made.

Continuation: follow docs/ROADMAP.md. Highest priorities are debug/instrumented PHP and prolonged soak/fuzzing, typed filesystem errors and atomic replacement, HTTP streaming/multipart/WebSocket, remote SQL authentication/TLS/cancellation matrices, individual hook expansion and framework integration. Run `make verify`, `make fpm`, `make thread-sanitize`, `make valgrind`, `ASYNCARA_BACKEND=poll ./scripts/test.sh` and `./scripts/phpize-local.sh` after native changes. For benchmarks run `./benchmarks/run.py && python3 benchmarks/report.py`. Dependencies remain under .local; build/test fixtures remain under build; nothing was installed or enabled system-wide.

Final source review: `git diff --cached --check` passes; documentation links resolve; current extension and every benchmark source-manifest hash match the recorded benchmark. Re-ran all three CLI runners and the phpize runner after the final nonfunctional NDEBUG warning fix: 44 core cases plus all four dedicated suites pass. Final standalone ASan/UBSan/TSan runs pass. No fixture servers, FPM pools or process workers remain running. Created and content-verified a deterministic 127-file source archive with `scripts/package.py`; repeated generation produced identical bytes. The archive and its SHA-256 sidecar live under build/ and exclude development dependencies, binaries, server data and TLS keys. Sources are staged for review, with no publication or system-wide installation.

## 2026-11-02 — Independent audit: preserved baseline and reproduced defects

Audit baseline: commit `f11c892b2a5b9ccae492772565f46211863b2894`, initially clean working tree. Independently enumerated all 127 source archive entries, checked safe paths and per-file bytes against Git, and preserved `build/asyncara-0.1.0-dev.tar.gz` unchanged (SHA-256 `a26ccf06960ed1ed5eeab4c2e16eb3144e5d85058a5d2adfd47106f89101031f`). Clean extraction, newly downloaded local dev packages, Composer dependencies, direct compilation, phpize/configure/make and isolated installed extension/configuration tests succeeded on PHP 8.3.36, 8.4.27 and 8.5.11 NTS. Reconstructed the archive independently with identical bytes. This proves the source package can build; it does not validate its behavior. Original core suites passed despite independently reproduced failures.

Reproduced and fixed seven high-severity issues: root failure waited on an unrelated long timer; transaction-owner SQL Futures bypassed connection serialization; ProcessPool close touched already-closed IPC; cancelled pool acquisition leaked the acquired connection; pool close overlooked a lease assigned to a queued borrower; worker relative paths followed subsequent chdir; PostgreSQL result conversion lost native PGresult ownership during a Zend memory bailout. Added per-connection operation gates, Task-object transaction ownership, shutdown timer distinction, pool acquisition/closure accounting, captured paths and registered connector ownership. Constructor bailout ownership and closer-error cleanup were hardened in the same paths. No major features were added.

Evidence includes deterministic barriers and slow syscall injection, unchanged-archive failure reproductions, and Valgrind before/after logs. PostgreSQL exhaustion initially leaked a detached PGresult; after repair PostgreSQL, SQLite and MariaDB deliberately exceed an 8 MiB PHP limit and exit 255 with zero Valgrind errors and zero definite/indirect/possible losses. This is fatal request teardown, not recovery of that request. The fatal test harness now explicitly enables Zend allocation because USE_ZEND_ALLOC=0 disables PHP memory_limit; its initial instrumentation-run failure was a missing test trigger, not a suppressed sanitizer finding.

## 2026-11-02 — Independent audit: I/O, SAPI and instrumentation

Added tests/audit and scripts/audit.py, audit-fpm.py and audit-allocations.sh. Slow external TCP, UDP and TLS peers plus worker DNS/file delay injection confirm timers execute while operations remain pending. All four database drivers pass independent slow-query/contention tests; MariaDB is tested against MariaDB, not an Oracle MySQL server. Existing and independent database tests pass on PHP 8.3/8.4/8.5. sleep/usleep responsiveness, named arguments, strict type/value errors and cancellation pass across these versions; signal behavior remains partial.

FPM tests are separated: A=100 sequential requests/one PID; B=10 requests, each four overlapping HTTP operations; C=12 requests/four workers, with both within-request and cross-request server timestamps overlapping. All passed; each idle worker has six descriptors and one thread. A's deliberate fatal memory request is followed by a healthy request on the same PID. The first C run failed the overlap assertion because Python's default listen backlog delayed accepts by about one second; logs showed timers progressing. Increased only the isolated fixture backlog to 128 and reran all three assertions successfully. No runtime assertion was weakened.

Built PHP 8.5.11 itself in isolated debug and ASan+UBSan debug prefixes from the official source tarball. Debug core runner passed. Fully instrumented PHP/extension passed all 44 core cases, limits/budget/handle suites, deliberate fatal teardown, audit regressions, and PostgreSQL/MariaDB/Redis tests. Standalone native ASan/UBSan/TSan and injected malloc/calloc/realloc failure recovery pass. GCC -fanalyzer produced no findings; native builds use warnings-as-errors. phpize still emits the upstream obsolete AC_PROG_LIBTOOL notice. Fresh Valgrind core, network, pending shutdown, memory, file, database and queued-pool workloads have zero errors and zero definite/indirect/possible lost bytes, without suppressions. Third-party libraries are not rebuilt with sanitizers; ZTS remains untested.

300 mixed runtime cycles passed: descriptors 5 before lazy engine creation and 7 thereafter; live PHP samples 799,760–811,360 bytes including the growing sample array. Independent 1,000/10,000-task checks pass; raw timing samples are retained, including substantial loaded-host outliers. Idle strace shows one 250 ms epoll_wait, not a polling spin. A 1,000-sample timer test with 64 yielding tasks measured median/p95/p99 lateness 0.447/0.909/2.791 ms; no latency guarantee is inferred.

Raw evidence lives in the directory recorded by build/audit-path. Next: finish durable audit evidence/report, reconcile compatibility/security/roadmap documentation, verify final changes and record an honest release decision. The preserved original archive contains the reproduced defects and must not be published as the corrected implementation.

## 2026-11-02 — Audit report and corrected source snapshot

Created docs/INDEPENDENT_AUDIT.md, docs/audit/archive-manifest.json and docs/audit/evidence.json; reconciled README, compatibility, security, limitations and roadmap. Assessment: **EXPERIMENTAL**. Added make audit / audit-fpm and reproducible fault/slow-peer fixtures. The documented seven high-severity defects are repaired; remaining limitations and missing verification are explicit.

An independent two-worker CPU probe records 1.000519 seconds of worker CPU consumption in 0.503783 seconds wall time, ratio 1.986 on distinct PIDs: actual multi-core execution, not just concurrent timers. A separately executed fatal file-result conversion under Valgrind also exits 255 with no memory errors/lost blocks. Normal host extension coexistence passes 44 cases. Fixed-source phpize/configure/make and the full core runner pass.

Extended package.py with an explicit output path and opt-in inclusion of reviewed, non-ignored new sources. Created build/asyncara-0.1.0-dev-audit1.tar.gz without changing the original artifact; repeated deterministic packaging matched byte for byte. Fresh extraction, newly downloaded dependency headers, Composer install, native build, 44 core cases/four dedicated suites and the independent regression/slow-I/O runner all pass from that corrected snapshot on PHP 8.5. Additional package-version checks and final archive/report consistency verification are being completed. Generated binaries and toolchains remain local; nothing is installed system-wide or published.

Final review additionally reproduced unsafe wait attribution when an application manually starts an unrelated Fiber inside an Asyncara Task. Added a defensive identity check at Scheduler::suspend so unsupported foreign Fibers fail explicitly before registering another Task's wait. The new regression passes on PHP 8.3/8.4 and instrumented 8.5; the full runner and FPM checks are repeated because this guard touches every suspension path. This is a safety check for an unsupported integration, not a new scheduling feature. The corrected archive's clean extraction also passes 8.3/8.4 builds, 44 core cases/four dedicated suites and its audit regressions.

The shutdown regression was extended to a cancelled child's finally block that schedules another 60-second callback. Before the final repair it exceeded a two-second subprocess deadline; shutdown now discards callbacks created during draining before polling, while retaining shielded suspension timers. This is additional coverage/repair of A1, not a newly claimed feature. The case passes on PHP 8.3/8.4 and instrumented 8.5. Final independent tests, normal core runner, FPM A/B/C and the complete instrumented runner pass. Durable evidence now includes the final follow-up runs, failed reproducers, toolchain configuration and linked libraries. No failures or sanitizer diagnostics were suppressed.

Final continuation: review docs/INDEPENDENT_AUDIT.md and the uncommitted changes; use make audit / make audit-fpm and the documented instrumentation commands. The priority remains fuzzing, prolonged soak and deployment-matrix certification. Do not publish the preserved original archive. The separately named audit1 archive is still EXPERIMENTAL. No further feature work was performed during the audit.

## Phase 3 — preserved baseline checkpoint

Starting commit: 4d90dbe6aba57b1ffc35558aca83618a2e903da6, clean working tree. Created phase3-hardening. The corrected audit1 archive SHA-256 remains 78e94d7caa72a43802f677c5f7f54d7ec1bec97d0caa6eb736faea3c286a567d; all 146 source files match. Historical build outputs (including archives/logs), phpize modules and benchmark results were copied before running commands. Prior benchmark latest.json was restored after retaining new measurements separately. Evidence location: build/phase3-path.

Executed the documented baseline commands unchanged: make audit, make audit-fpm, scripts/audit-allocations.sh, make verify, poll test runner, phpize-local.sh and benchmarks/run.py. All pass on this host. Inspected the scheduler, seven audit fixes/tests, native ownership, drivers, queues/pools, build and FPM harnesses. Fresh compilation of the pre-audit archive provides safe negative controls; six behavioral regressions fail as expected, while corrected PHP 8.3/8.4 regressions pass. Corrected native PostgreSQL/SQLite/MariaDB fatal result conversion again passes Valgrind cleanup. A concurrent fixture-start attempt was rejected because the existing database fixture was still running; this is recorded and the negative control is rerun serially, not counted as a driver failure.

Order remains P0 regressions/CI, P1 explicit resource bounds, P2 fuzzing, P3/P4 progressive soak and FPM lifecycle, then database TLS/auth and ZTS investigation before feature completion. No architecture replacement is planned. Long-duration tests will report actual elapsed durations and source provenance; six-/24-hour runs will not be called complete without evidence.

## Phase 3 — P0/P1 resource boundaries

All seven pre-audit negative controls are now confirmed, including PostgreSQL's 216-byte definite leak on fatal result conversion (Valgrind exit 99). The corrected implementation exits 255 for the deliberate PHP fatal with zero memory errors. Added PHP 8.3/8.4/8.5 core/audit/allocation CI and a database/Valgrind/FPM job. Hosted CI is configured but has not been executed remotely. Fixed audit.py to resolve an explicitly relative evidence directory before the delayed-open fixture changes cwd; tested the exact CI invocation locally.

Implemented request-local weak pending-Future accounting; admission before native submission; bounded retained unobserved task failures; atomic configuration validation; bounded ConnectionPool acquisition and ProcessPool aggregate request bytes; worker PHP memory/JSON shape limits; queue/pool metrics. Extended Redis pipeline byte/element accounting across all replies and checked request framing before concatenation. PostgreSQL notification conversion and persistent queues now fail and disconnect explicitly at count/byte limits; result conversion charges cell metadata. SQLite sets per-connection value/SQL/parameter limits before work executes. Native worker and event-loop architecture preserved.

New tests/resource_bounds.php and tests/database/resource_bounds.php verify rejection, recovery, cancellation, startup failure cleanup, queued leases, large SQLite values, PostgreSQL notifications and Redis aggregate replies. The SQLite test demonstrably failed before the fix. Commands: build-local.sh; test.sh on 8.3, 8.4 and fully ASan/UBSan instrumented 8.5; make audit; audit-allocations.sh; make audit-fpm; database resource limits under Valgrind; database fatal-conversion audit. All pass. The database limit Valgrind run reports zero errors and zero definite/indirect/possible lost bytes. Logs are retained beneath build/phase3-path. RESOURCE_LIMITS.md defines overflow policies and explicitly excludes third-party, kernel and arbitrary application memory from the native ledger.

Next: P2 reproducible protocol/allocation fuzzing, then freeze a tested snapshot for progressive soak while continuing the later priorities. EXPERIMENTAL remains the release classification.

## Phase 3 — P2 protocol and allocator fuzzing

Created tests/fuzz with committed RESP/native seed corpora and deterministic regression/mutation harnesses. The production Redis parser failed 39 of 100 prefix/input combinations before fixes: signed minimum/positive sign, saturating overflow, oversized lines/control characters, malformed/overflowed doubles and verbatim prefixes. Fixed each without replacing the parser; all 100 now pass. Added shared production IPC framing/JSON admission checks; real malformed/truncated three-byte worker writes and worker replacement pass. Error messages sent by workers are bounded. The JSON admission scan is used before both encoding admission and decoding arrays; PHP remains the grammar parser.

Clang18 libFuzzer with ASan/UBSan ran 326,134 native allocation/value/job ownership inputs in 61 seconds (295 MiB peak RSS, no finding). Fully instrumented PHP8.5.11 mutation test ran 12,737 inputs in 60.007 seconds across RESP, IPC and owned header processing, with no diagnostics. Correctly distinguished deterministic PHP mutations from native coverage-guided fuzzing. Core/dedicated tests, database integration and native TSan pass. Added regression seeds and fuzz smoke commands to CI. docs/FUZZING.md records scope, commands and parser ownership; no external HTTP/TLS/database parser coverage or WebSocket support is claimed.

Next: short mixed soak validation, freeze source/binary provenance and run a real one-hour baseline while continuing FPM lifecycle and database TLS work. Six-/24-hour soak remain unexecuted until actual results exist.

## Phase 3 — P3 progressive soak harness

Added tests/soak workload/runner with immutable source/binary manifests, isolated databases, live resource JSONL samples and warmup-excluded growth/slope reports. The CLI workload holds one scheduler open while repeatedly exercising 1,000-task bursts, timers, TCP, HTTP, files, all four database drivers, Queue, cancellation, timeouts and ProcessPool jobs/crash recovery. Added native queue/SQLite/curl-readiness counts and ProcessPool restart counts for measurement. Native queue traversal takes its mutex; no Zend access is introduced in workers.

Short verification: 20.173 workload seconds, 126 mixed cycles, one injected worker crash, no stderr, no pending resources/Futures after shutdown. Wall time including database setup was 28.479 seconds. Maximum recorded timer lag was 221.8 ms; this loaded-host/burst measurement is retained without a low-latency claim. Added a separate scheduled one-hour workflow and exact 1h/6h/24h local commands. Long-duration results remain pending; no extrapolated completion is claimed. Next action is a frozen one-hour run while continuing P4 FPM lifecycle tests.

## Phase 3 — P4 signal isolation defect and FPM investigation

The extended reload fixture first failed because the default FPM process_control_timeout=0 escalated SIGQUIT to SIGTERM immediately. A traced reproducer shows the master sending both signals within milliseconds. With an explicit five-second grace period, active requests complete. This is a test/deployment configuration finding, not an Asyncara reload fix. A deterministic HTTP barrier replaces timing assumptions while retaining the actual overlap/timer assertions.

Separately reproduced a native worker isolation defect: workers inherited unblocked interpreter/SAPI signals and ran an installed signal handler when targeted with pthread_kill. The regression aborts against the baseline. Worker creation now blocks asynchronous signals before pthread_create (no startup window), restores the caller's original mask, and leaves synchronous fault signals available. scripts/test-worker-signals.sh passes ASan/UBSan; native TSan and the full instrumented PHP core/dedicated/protocol suites also pass. No Zend operations were added to workers.

FPM RSS investigation remains in progress. Repeated fatal 12 MiB result conversions can retain freed libc arenas even with zero native ledger allocations. A standalone 40-cycle native probe ends with zero tracked allocations each cycle; RSS 33,028 KiB falls to 20,744 KiB after diagnostic malloc_trim (initial 8,024 KiB). No production allocator change or global malloc setting was made. The expanded fixture records every warmup/mixed sample and warms all worker arenas with deliberately concurrent fatal buffers. Steady-state checks distinguish positive RSS growth from downward release; the old 100-request audit assertions remain intact. Prior failed runs and traces are preserved beneath build/phase3-path.

The one-hour CLI soak continues from frozen commit cad761e; it therefore predates this signal-mask fix. Its result must be labeled with that provenance, not presented as a one-hour test of a later binary.

## Phase 3 — P4 FPM lifecycle verification complete for the short workload

Preserved A/B/C and added recycling, active graceful reload, abnormal kill/replacement, request timeout, fatal allocation bursts, pending I/O shutdown and cross-request state probes. Five-minute run passed: 300.157 mixed seconds, 4,380 main requests, one reload, one killed worker, plus A100/B10/C12 and 25 recycling requests. Idle workers returned to six descriptors and one thread; peak RSS growth over worst-case warmup was at most 2,476 KiB. Documented all earlier fixture failures, allocator-cache evidence and Linux post-join thread-reaping transients. No production allocator behavior changed. docs/FPM_LIFECYCLE.md and docs/phase3/fpm-summary.json contain scope and results. Added a shorter expanded command to CI; hosted CI remains unexecuted.

P5 private-CA tests then reproduced a MariaDB certificate identity bypass in Connector/C's local-address verification policy. Investigation/fix is in progress; do not rely on the old TLS verification claim.

## Phase 3 — P5 TLS, Oracle MySQL and lost stream readiness

Private-CA PostgreSQL/MariaDB/Redis tests reproduced MariaDB accepting a trusted wrong-name certificate. Inspected Connector/C 3.4.10 actual source: its local/private-address policy omits HOST verification. Added documented per-connection verification callback using installed public headers and OpenSSL 3; validates chain status/IP SAN before authentication, forbids password fallback, fails closed on unsupported builds, requires negotiated TLS. The small ma_hash.h shim only addresses an unused missing packaging include; it contains no copied ABI declarations. Added an isolated pinned Connector/C build for CI and tested the same build/integration commands locally. phpize initially missed the shim because ext_srcdir was unset at that configure stage; corrected to srcdir and rebuilt successfully.

Extracted Oracle MySQL 8.4.13 from official HTTPS package metadata with matching SHA-256. Separate tests exposed START TRANSACTION rejection in the prepared protocol. Exact parameterless transaction controls now use Connector/C nonblocking text start/cont; parameterized queries stay prepared. Private-CA TLS, caching_sha2_password and sha256_password, bound writes/rollback, pool concurrency, deadlines, cancellation/reconnect pass. MariaDB is still tested independently. No host database service was changed.

Repeated Redis TLS tests exposed intermittent HELLO timeouts. Trace showed read suspension with decrypted data already buffered by PHP's TLS cast. Deterministic socket-pair readiness test fails before the fix. Native waits now treat buffered/EOF streams as ready while still polling other descriptors with zero timeout; both epoll/poll tests pass. Oracle/Redis 200 TLS cycles, PHP8.3/8.4 50-cycle matrices and fully instrumented PHP8.5 200-cycle matrix pass after repair. Earlier timeouts remain recorded; they were not dismissed as load flakiness.

ASan found 109 bytes in libpq's process-global TLS BIO_METHOD after module unload. A native-only dlopen/connect/finish/dlclose reproducer confirms identical two-block loss after 20 connections without PHP/Asyncara. Public libpq offers no teardown for this global. Finding remains unresolved and documented; no suppression/private-pointer free added. Additional ZEND_DONT_UNLOAD_MODULES=1 instrumentation keeps globals reachable to inspect runtime accesses; it is not reported as fixing the leak. Native ASan/UBSan/TSan and instrumented core tests pass.

## Phase 3 — P6 isolated ZTS investigation

Built PHP8.5.11 ZTS DEBUG in scratch from the verified official source tarball, using --enable-zts --enable-debug --disable-all --enable-filter --with-openssl. Extension loads without source changes; all core/dedicated/protocol tests pass on epoll and poll, and PostgreSQL/MariaDB/Redis integration passes. Compiler warnings scan is empty. This is single-interpreter CLI evidence only. Reviewed native thread inputs, ZEND_TLS runtime state and process-wide hook/library initialization. Concurrent interpreter lifecycle/third-party thread entry/exit remain unverified; Userland ThreadPool stays BLOCKED. Commands and boundaries are in ZTS_COMPATIBILITY.md.

## Phase 3 — filesystem errno and atomic replacement

Extended the existing worker engine with Files::replaceAtomic, numeric syscall errno reporting and FilesystemException. Files::exists no longer parses English messages. Atomic writes pin the parent directory, use random exclusive temporary files, publish with same-directory rename and optionally fsync file then directory. The result records whether publication already occurred when durability fails. Cleanup failures remain visible even when cancellation races. Existing handles/streams/backend are preserved; io_uring filesystem support remains NOT_IMPLEMENTED.

Tests pass on PHP8.3/8.4/8.5 and fully instrumented8.5: binary/empty files, explicit modes, symlink replacement, 120 competing writes/200 reads, 100 cancellations, permission/missing-path errors and temp cleanup. Native ASan/UBSan injects allocation, file fsync, rename EXDEV and directory fsync failures; checks content/publication state and zero managed memory. Native allocation suite, ASan/UBSan/TSan and independent audit pass. Valgrind atomic workload: zero errors and zero definite/indirect/possible losses, 77,515 reachable bytes, no suppression. Initial compilation required adding stdbool.h; warning-as-error failure was resolved before tests. Permission fixture runs as an ordinary user. Physical crash durability and hostile mount/ACL cases are not certified.

The frozen one-hour baseline at cad761e completed: 3,600.149 workload seconds, 20,855 cycles / 20.855m burst Tasks, 208 intentional process crashes/recoveries, no unexpected errors, zero final pending runtime objects/native bytes. Steady descriptors20/threads5/sockets7; RSS endpoint medians +296 KiB, linear slope389.7 KiB/hour. Peak timer lag586.8 ms under concurrent engineering load is retained. Source/binary provenance and all706 samples are preserved; docs/phase3/soak-1h.json contains the summary. This predates later fixes, so a shorter final snapshot will be validated separately. Six-/24-hour tests remain unexecuted. Package generation now refuses to overwrite prior artifacts or checksum sidecars.

## Phase 3 — final runtime verification and delivery preparation

Final runtime checkpoint8af2604 passed a frozen 300.106-second mixed run:1,894cycles/1.894m burst Tasks,18 injected worker restarts, zero unexpected errors/final pending objects. Sampled fds20/threads5/sockets7; RSS endpoint medians+444KiB. Short-window slope8,838.7KiB/hour and live PHP+16,168bytes are retained, not extrapolated to leak-free long-term behavior.

Rebuilt phpize module and ran complete tests, final FPM A/B/C, database integration/fatal audit, final ZTS DEBUG CLI tests, poll tests, native60s fuzz(294,043inputs/61s/no finding), GCC-fanalyzer(no diagnostics) and full Valgrind core/network/memory/files/shutdown/database suite(zero errors/definite/indirect/possible losses). Preserved final raw audit/Valgrind directories separately. Atomic fault harness additionally verifies one-byte partial writes/EINTR and ENOSPC under ASan/UBSan.

Reran every existing benchmark case with three samples, preserving old latest.json/report and writing phase3.json. Added ordinary/atomic/durable64KiB file measurements with all300operation latencies per mode retained. Added truthful HTTP_STREAMING, WEBSOCKET, HOOK_COMPATIBILITY and RELEASE_READINESS reports; unresolved/missing features remain explicit. Phase3 remains PARTIALLY_COMPLETED and EXPERIMENTAL. Next delivery step: deterministic new archive, clean extraction builds/tests and package attestation. No six-/24-hour or hosted CI completion is claimed.

## Phase 3 — clean-checkout archive reproducibility defect

The initial phase3 candidate archive was byte-identical across repeated local runs, but a separate detached Git checkout created under umask077 produced a different hash despite identical source bytes. All228 tar member permission modes differed. This exposed a packaging reproducibility defect. package.py now normalizes ordinary/executable files to0644/0755 while preserving executable intent; owner/time/gzip metadata was already fixed. Added a real clean-checkout/private-umask comparison to CI. The initial candidate remains preserved; the corrected delivery will use a separately named phase3-r1 artifact. Native/PHP source behavior is unchanged.

## Phase 3 continuation — verified HTTP streaming

Preserved the hardening r1 archive from0e8de58, SHA256af203fef69cddd599c6c4c7b11351aa7ffc30bc09d38c4b978d673e69cc6d7a7. Clean extraction, own phpize/configure/install-root, direct8.3/8.4 builds and full NTS tests passed. First test attempt omitted Composer dependencies and failed PSR integration; composer install fixed the fixture omission. A separate private-umask checkout produced identical archive bytes. Attestation: docs/phase3/package-hardening-checkpoint.json. This archive intentionally predates HTTP expansion.

Extended the existing curl multi owner, preserving admission/pooling. Added bounded64KiB receive/upload buffers, pause/resume, ResponseStream/ResponseHead, header readiness, worker-backed download/upload, iterable bodies and curl MIME one-file multipart. No Zend callbacks or blocking file reads run in curl callbacks. Streaming forces HTTP/1.1 and leaves content encoding intact to avoid hidden decompression/multiplexing amplification. Downloads can leave partial files; multi-file MIME/replay remain unavailable.

New tests exposed and fixed a paused-readable-socket busy-spin and a MIME pause propagation race. Inspected actual curl8.5 easy.c/mime.c: MIME can emit boundary bytes before passing callback PAUSE to the transfer; direct CONT can be a no-op. Setting then clearing the public SEND pause state resumes the MIME tree without touching private memory. Three consecutive timer-free multipart runs pass. Earlier failures, debug traces, failed Expect/multi-perform experiments are preserved, and those ineffective workarounds were removed. A single native application-data wakeup drives fresh state without periodic polling.

Dedicated tests pass on PHP8.3/8.4/8.5 NTS and fully instrumented8.5; core/dedicated suites and both backends pass. The original poll run exposed a CPU-overlap fixture startup race after worker replacement. Added a two-worker ready barrier, preserving the original computation-only overlap assertion. Header metadata uses a deterministic gated body instead of a wall-clock threshold that failed under Valgrind overhead.

Streaming integration exercises32MiB binary response/download/upload/MIME, empty/partial/fixed-length bodies, source/consumer failures, TLS trust/name rejection, cancellation, early-close and early server rejection, native memory bounds and non-spinning slow consumers. Paused native transfers survive normal/fatal request cleanup tests without crashes. FPM A100/B10/C12 plus new D12/four streams per request across four workers pass.

Native ASan/UBSan and allocation injection pass. HTTP callback libFuzzer campaigns:704,679/61s and602,547/61s, no findings, peaks373/369MiB. Valgrind initially reported PHP-object cycles retained at process shutdown; explicit collection reclaimed114 cycles and weak stream owners became null. Final workload: zero errors and zero definite/indirect/possible losses,77,515 reachable bytes, no suppressions. Native ledger already returned to zero. This documents PHP-cycle lifetime, not a native allocator fix.

Added three independent benchmark samples per buffered8MiB, streamed8/32MiB, slow-consumer32MiB, download32MiB and upload32MiB workload, with raw PHP/native/RSS peaks. docs/HTTP_STREAMING.md, RESOURCE_LIMITS, SECURITY, ROADMAP and API_REFERENCE now describe the tested scope. docs/phase3/http-streaming.json preserves log hashes. Next: frozen mixed soak including streams, re-run complete archive/build verification, then protocol expansion after lifecycle evidence. Release remains EXPERIMENTAL; old long-run evidence is not relabeled as testing this binary.

## Phase 3 continuation — bounded WebSocket implementation

Added explicit ws/wss client, raw TCP server upgrade, incremental role-masked
framing, fragmentation/UTF-8/control/close state handling and bounded message
queues over existing TcpStream. Default browser Origin deny without allowlist;
optional authorization; no compression/extensions or FPM upgrade claim. Separate
message/frame locks permit Pong between64KiB data fragments. A preserved copied
old-lock negative control fails the independent peer's8MiB interleaving assertion.

41 framing cases and Python websockets17.4 bidirectional interop pass on8.3/.4/.5,
poll, fully instrumented8.5 and ZTS DEBUG single-interpreter CLI. Includes WSS
CA/name failures, malformed handshakes/frames, close timeout, slow-consumer
capacity, idle timeout, cancellation and cleanup. Final Valgrind zero errors/
definite/indirect/possible losses,77,515 reachable. No suppressions. Mutation
campaigns24,933/60.001s and47,262/60.002s, no findings; finalRSS322,620KiB. The
framing mutation target is not network state-machine coverage-guided fuzzing.

Added pinned local peer installer, CI commands, corpus and frozen WebSocket soak
mode. Ten-second16-connection smoke passed1,140cycles and finalzero pending work.
The one-hour mixed HTTP streaming soak is still running at this checkpoint and
will be attested separately. API/reference/security/resource/status docs now
state the tested WebSocket scope and remaining interoperability/endurance gaps.
Release remains EXPERIMENTAL. No universal WebSocket or ZTS certification claim.

## Phase 3 continuation — IPv6/TLS edge tests and measured WebSocket overhead

Added deterministic IPv4/IPv6 TLS1.2/1.3 peer and assertions for TCP/UDP,
localhost address fallback, SNI/ALPN, trust/name rejection, slow2MiB upload,
partial reads, EOF/refusal and handshake cancellation. Tests pass on8.3/.4/.5,
poll, ASan/UBSan and Valgrind (zero errors/losses,77,515 reachable). First test
had a Runtime information object-vs-array assertion error; corrected the test.
DNS delay fixture initially used LD_PRELOAD and did not interpose on8.3; replaced
with a test-only linker wrapper rather than altering production resolution.

Measured three fresh WebSocket client samples at1/16/128connections against the
same independent server and Python17.4 client. Asyncara median throughput904/
2215/2428msg/s versus1551/3775/6611; all raw latencies retained. Asyncara is slower
in this loaded-host workload; no selective fastest-sample claim or speculative
rewrite. Added the network edge suite to CI and extended FPM soak mixed batches
with one streamed request alongside buffered I/O/pending shutdown, preserving
all original assertions and recording code/binary provenance. Longer results
will be recorded only when complete. Release remains EXPERIMENTAL.

## Phase 3 continuation — PHP toolchain selection defect

Found that bootstrap accepted a PHP8.3/8.4 development package but relocate-tools
hardcoded phpize8.5/php-config8.5. This would fail those hosted CI entries before
compilation. An isolated8.3-only SDK negative control reproduces the original
failure; selecting PHP_BIN/PHP_TOOL_VERSION now succeeds. Default header API and
bootstrap package follow the selected interpreter; phpize-local loads with it.
No runtime implementation changed. Added actual phpize builds to each CI matrix
entry and a clean-archive/own-SDK/install-root/full-test harness. Full three-version
archive execution is the next validation step; hosted CI is still unexecuted.

WebSocket checkpoint648c076 completed300.026s/24,686cycles/394,976echo jobs,
246reconnect/cancel injections. FlatRSS26,008KiB/fds25/threads1/sockets16; final
pending0. PHP endpoint+19,728bytes and task/timer outliers retained rather than
claiming long-term stability. Evidence: docs/phase3/soak-websocket-5m.json.

## Phase 3 continuation — expanded FPM RSS failure investigated

The five-minute streamed/buffered/fatal FPM soak failed the existing8MiB RSS-over-
warmup criterion: one worker exceeded it by27,256KiB. fd6/thread1 and request
state checks passed. Preserved failure/raw ranges; did not raise/remove the
threshold or label the run passed. Added separate test-only glibc allocator
probe and100-cycle diagnostic: in-use2.14–2.23MiB versus up to113.64MiB free
arena bytes, substantial RSS reclamation after diagnostic trim, native ledger0.
Evidence supports cache/fragmentation effects; the operational RSS criterion
remains open rather than claiming proven long-term bounds. No production trim
or allocator settings changed. Added failure.json preservation to the FPM runner.

## Hosted CI first execution

Pushed phase3-hardening normally at4cdb4f5. GitHub run41539565366 actually ran and
failed all four jobs during dependency provisioning with exit100, before any
runtime tests. Public job annotations expose the failed steps; log download
requires GitHub authentication unavailable to this session (403). Added fresh
apt indexes on disposable regression runners and preserved provisioning logs/
error annotations so the exact package failure can be diagnosed. This does not
claim the hosted tests passed. Local archive PHP8.3/.4/.5 builds/tests remain
separate evidence. No host-wide package settings were changed.

Hosted run41540310966 revealed the provisioning causes after single multiline
annotations avoided GitHub's per-step annotation cap: cached setup-php builds
had no PHP8.4/8.5 SDK/FPM apt source, and preinstalled libpq-dev18.6 had no current
download source. Inspected upstream setup-php linux.sh: use_builds_cache=false
selects its supported packaged-PHP/PPA path. CI now requests that mode; local
bootstrap selects an actually downloadable apt-cache madison version instead
of an unavailable installed candidate. No tests were disabled. Hosted rerun
must establish the result; external source availability remains a dependency.

Expanded FPM repeat again failed unchangedRSS criterion (0/27,340/12,600/
14,356KiB beyond warmup);4,692 idle samples preserved. This is an open capacity/
allocator investigation, not a passing long-run claim. The extracted r2 package
independently passed ownSDK phpize/configure/install and all NTS suites on8.3/
8.4/8.5, both backends, WS/network tests, externalDB integration/bounds, fatal
DB cleanup, shortFPM A/B/C/D and Oracle/privateCA TLS. First fatalDB audit lacked
its optional Valgrind tooling; after fresh tool/debug-package extraction all
expected fatal255 exits passed. Original failed fixture log remains preserved.

The next hosted run confirmed download-version selection fixed libpq, then
exposed a missing libmariadb runtime on the regression image. It also showed
setup-php reused an already installed PHP without entering its package path;
upstream setup_php checks update separately. CI now requests update=true and
installs runtime C libraries on the disposable runner. Isolated local bootstrap
with explicit downloadable versions completed successfully. Hosted proof remains
pending; the previous failed jobs are preserved, not counted as tests passing.

Preserved r2 archive4390f19e8444701e829554d34e3f0086f2ab8da29e58150c27173dd9a67587ca,
285files at4cdb4f5. Clean private-umask bytes match. Own phpize/install-root/full
suites pass across8.3/8.4/8.5; additional installed8.5DB/fatal/TLS/FPM audit passes.
Attestation: docs/phase3/package-streaming-checkpoint.json. Later CI-only changes
are distinct; archive remains unchanged. API reference generator now omits
classes explicitly marked internal, preserving the public WebSocket API boundary.

Hosted8.3 reached compilation and exposed a real Connector/C compatibility
mistake. Reproduced with freshly extracted Ubuntu10.11.14 SDK (Connector/C3.3.17):
version gating incorrectly assumed the TLS callback/status fields were backported.
Replaced inferred version threshold with the actual public feature macro. Builds
without that API now reject TLS before any connection instead of failing compile
or attempting insecure fallback. Added explicit unsupported-TLS regression and
CI header-capability selection. Supported3.4.10 TLS path remains unchanged and
requires rerunning its private-CA matrix. Original compiler errors preserved.

## Phase 3 continuation — HTTP mixed one-hour result

Frozen1f792ef completed3,600.110s/19,761cycles/19.761m burst Tasks/197worker
crash-recoveries. All706samples retained; fds20/threads5/sockets7 flat; tracked
native0 at boundaries/final pending0. RSS endpoint+380KiB/slope472.37KiB/h;
livePHP+16,168bytes; maxstep655.7ms/timerlag502.7ms on loadedhost. Separate evidence
for this checkpoint, not a claim about later WebSocket/Connector binaries.
Six-/24-hour tests remain unexecuted and FPM RSS failures remain open.

Connector3.3.17 follow-up: its verification option itself forcesTLS, so the Unix
socket attempt first failed on the server's automatic self-signed certificate,
then on a TLS-disabled server. Inspected exact upstream3.3.17 auth code. Unsupported
TLS/defaultTCP/CA options are rejected before connection; only the explicit
non-TLS path leaves the TLS-enabling verification option unset. No unverified
TLS is allowed. Plain fixture now states --skip-ssl and asserts empty cipher;
separate verifiedTLS fixture unchanged. OldSDK/runtime plain query/bind/large/
transaction/cancellation/lifecycle tests and fail-closed matrix now pass. Earlier
failures preserved. Native3.4.10 Oracle/privateCA100-repeat matrix also passed
following the feature-gate fix; further final-source instrumentation follows.

## Hosted audit portability correction and final Connector validation

Hosted8.5 regression job passed at8122946. Hosted8.3/8.4 reached slow-file audit
then timed out: PHP loaded the selected module with RTLD_DEEPBIND, bypassing the
existing LD_PRELOAD fault injector. Added test-only dlopen interposition clearing
that flag only for the exact explicitly selected audit module. The actual
compiled artifact stays under test; no production loader/engine setting changes.
Added a two-second injector-reached assertion rather than an unbounded marker
wait. Negative control without the audit target reproduces the failure; complete
8.3/.4 audits now pass slow-path and delayed I/O while retaining all assertions.

Connector3.3.17 final query and fail-closed suites also pass under fully
ASan/UBSan-instrumented PHP8.5.11 with its actual older runtime library. Strict
Connector3.4.10 Oracle/privateCA100-repeat tests pass after capability-gate
correction. Evidence: docs/phase3/connector-and-audit-regressions.json. No TLS
verification was disabled for a TLS connection; non-TLS Unix tests assert an
empty session cipher. Next: corrected r3 source archive and isolated installs.

The explicit plaintext fixture additionally exposed that3.4.10 also treats the
verification flag as enablingTLS. Corrected transport selection for both series:
TLSrequested => verification/enforcement true plus strict callback; noTLS => both
TLS-enabling flags false, CA options rejected. Added a native parameter regression.
Default TCP remains verifiedTLS. Full local make verify and Oracle/privateCA100
repeat tests pass. Older3.3.17 Valgrind database integration has zero errors and
zero definite/indirect/possible losses,77,515 reachable; no suppressions.

Hosted run41543460857 now passes all PHP8.3/8.4/8.5 regression jobs. Its database
job passes functional cases but exits99 under Valgrind; the runner now emits that
native diagnostic on failure for investigation. Do not label the whole workflow
passed. No test or Valgrind error check was disabled.

## PHP security-release delayed OOM correction

The hosted fatal-burst reset was reproduced locally with isolated PHP8.5.12:
its error log reports the16MiB hard memory limit and exit137, not a segfault.
The October security release defers ordinary OOM until a safe engine boundary;
Asyncara kept converting native results in one call. The old binary negative
control in fatal_batch.php exits137. Bulk file/SQLite/HTTP/SQL conversion now
uses the public fallible string API when available and stops on pending OOM,
retaining native ownership until request teardown. Legacy SDK fallback remains.
PHP8.3.36/8.4.27 independent audits and core suites pass;8.5.12 core/audit,
fatal PostgreSQL/SQLite/MariaDB Valgrind children, and60s expandedFPM pass.
The later300s FPM RSS criterion still fails (14,792KiB beyond warmup on onePID).
It remains a separate finding; an allocator change is now under investigation.

Latest hosted run also exposes nondeterministic Valgrind stack-registration
errors and libFuzzer512MiB RSS-budget exhaustion. Preserve these failures;
independent annotated dependency builds and sanitizer-budget analysis follow.
No error suppression or RSS assertion change has been applied.

PHP upstream reference: https://www.php.net/manual/en/ini.core.php#ini.memory-hard-limit-percent.
Use matching PHP SDK/runtime builds; final validation moves to patched8.5.12.
The older8.5.11 sanitizer/ZTS evidence is historical, not a deployment recommendation.

## Bounded native page ownership and repeated FPM check

Three expanded FPM runs exceeded the unchanged8192KiB RSS-over-warmup bound;
the latest8.5.12 run exceeded it by14,792KiB. Allocation probes showed freed
thread arenas retaining large buffers. Asyncara allocations >=1MiB now use
page-rounded mappings; freeing releases pages, with no global malloc settings.
Resize counts both temporary owners and preserves old memory on quota/OS failure.
ASan mapped tails are poisoned, verified by a deliberate overrun. New mapping
tests cover quota, relocation, injected mmap failure, unmap and four workers.
NativeASan/UBSan/TSan, allocation injection and make verify pass.

The unchanged300s FPM mix now passes3,912requests, lifecycle/recycling/reload/kill/
fatal/timeout assertions, six idleFDs/one thread, RSS16,968–19,420KiB and at most
2,968KiB beyond warmup. Historical failures remain in evidence. The follow-up
soak runner snapshots its module before any worker/reload. A frozen rerun follows.
ASan fuzz quarantine is explicitly64MiB to keep instrumentation within the same
512MiB RSS ceiling:306,396native and803,874HTTP inputs/61s each, peaks198/147MiB,
no findings. See docs/phase3/deferred-oom-and-mappings.json.

## Correct stack-aware memory tooling and patched SDK selection

StockPHP8.5.12 plus distributionConnector reproduced Valgrind stack-related
invalid reads; annotating Connector alone still left unregistered PHP Fibers.
Pinned PHP8.5.12 DEBUG --with-valgrind plus Connector3.4.10 HAVE_VALGRIND passes
the identical database suite:zero errors/losses,3,888bytes reachable. CI now
builds these supported annotations, retaining stock functional DB tests and all
Valgrind error checks. Added pinned source build script; no suppression or
max-stackframe workaround. Exact officialPHPsourceSHA verified from releasesAPI.

Clang18 full-PHP UBSan exposes upstream incompatible callback function-pointer
types with no Asyncara loaded. Retained logs; this configuration is not clean.
A separate GCCASan/UBSan build is underway, preserving the earlier tooling model.
Both native build runners now verify exact SDK/interpreter patch versions to
avoid accidentally loading the new fallible-allocation API into olderPHP.
HostPHP8.5.11 was not replaced; tests explicitly select isolated8.5.12.

## File-result ownership and deterministic HTTP verification

Dedicated mappings fixed the measured FPM retention but12MiB read benchmarks
showed a copying cost. The worker already owned a correctly sized native buffer;
its result now adopts that buffer instead of allocating/copying it again. A
12MiB-content/empty-read/zero-retention regression fits a14MiB native budget; the
previous binary fails it. make verify passes. Raw three-sample comparisons retain
all data: mapped-copy median1.699s versus owned-buffer1.396s for24reads, with
p95latency worse under shared compiler load; no universal speedup claim.

Frozen mapped checkpoint df7d061 separately passed3,716FPM requests/300.492s
(max2,904KiB beyond warmup), and a300.276s mixedSQL/HTTP/file/process soak with
1,296cycles/1.296m burstTasks, flat descriptors/threads andRSS endpoint+372KiB.
No pending Tasks/Futures/timers/native jobs remained. Evidence records the exact
checkpoint before the later copy removal.

The r4 archive builds from clean sources and is byte-reproducible. Its8.3/.4
full/poll/network/WS runs pass;8.5epoll passes but the old HTTP300ms total-wall
assertion failed during concurrent compiler load. Preserve that failed archive
report. Strengthened the test to require six server-barrier arrivals and ten
timer ticks while responses remain gated, then explicit release. A serial
negative control gets503 after the barrier deadline; complete8.5poll now passes.
No wall threshold was enlarged and no responsiveness check was removed.

Hosted mapping test initially failed because ripgrep was absent despite the
expected ASan diagnostic. Switched its string assertion to standard grep; the
intentional overrun and required failure status remain unchanged.

Final native source e5d94fd passes fully instrumented GCCASan/UBSan PHP8.5.12
core/HTTP streaming/file-ownership/database/WebSocket/network-edge suites.
Private-CA MariaDB and separate Oracle8.4.13 suites each pass with100Redis TLS
repeat cycles. The Clang full-PHP callback-type diagnostics remain explicitly
unclean upstream evidence. Final snapshot soaks and clean source distribution
verification are in progress; no longer-soak or release-candidate claim is made.

## Final Phase 3 package and evidence checkpoint — 2026-11-03

Final native/test source e5d94fd8dd959b88474332a05aaa1c9eb61f318f is preserved.
Documentation source f3d63ac9abf528af0cd5b24086976912c28e4a75 produced
build/asyncara-0.1.0-dev-phase3-r5.tar.gz (304 regular source files), SHA-256:
b0f544b3da61789a9cc649a8bc24869308787c9c63ca37358afd69e39ef1ec38.
All source bytes match that Git commit; packaging a clean private-umask checkout
is byte-identical. Earlier archives and failed reports remain untouched.

Executed tests/audit/archive_install.py from independent extractions with matching
8.3.36, 8.4.27 and 8.5.12 SDKs: phpize/configure, isolated install, load,
Composer, full runner, poll, independent WebSocket peer and network-edge suites
all PASS. Installed independent audits also PASS on all three versions, including
1,000/10,000 Tasks and deferred fatal-batch cleanup. Installed 8.5.12 additionally
passes ordinary databases, database bounds/audit, FPM A/B/C/D and separate
private-CA Oracle TLS tests with 50 Redis TLS repeat cycles. Raw logs/manifests
are in /tmp/asyncara-phase3-cQBA3H/package-r5-installed; durable checksums and
step statuses are in docs/phase3/package-final.json.

Final native validation also passes make verify, allocation/mapping failures,
native ASan/UBSan/TSan, GCC -fanalyzer/-Wall/-Wextra/-Werror for memory/workers/
backend, full GCC ASan/UBSan PHP 8.5.12 DEBUG core/database/WebSocket/network
tests, annotated PHP/Connector Valgrind core/file/network/pending/database and
fatal SQL teardown. Valgrind reports zero errors and definite/indirect/possible
losses without suppressions. Separate MariaDB and Oracle private-CA suites each
pass with 100 Redis TLS repeats. The separately reproduced libpq TLS unload leak
and Clang full-PHP callback-type diagnostics remain documented unresolved findings.

Frozen final-source mixed soak: 300.073 workload seconds, 1,331 cycles / 1.331m
burst Tasks, 13 worker crash recoveries, no unexpected errors or final pending
Tasks/Futures/timers/native jobs. Steady fds20/threads5/sockets7, native bytes0 at
cycle boundaries, RSS endpoint medians +200 KiB. Frozen final-source FPM:
3,756 main requests / 300.106 seconds, A/B/C/D, recycling/reload/kill/fatal/pending/
timeout assertions pass, maximum 2,888 KiB growth beyond warmup, idle fds6/thread1.
This is the third unchanged five-minute FPM pass after mapped-buffer repair.
Final-source one-hour, six-hour and 24-hour endurance have not been run.

Hosted run https://github.com/asyncara/asyncara/actions/runs/41549782614 at e5d94fd
completed all four jobs successfully: regression 8.3/8.4/8.5 and database-lifecycle.
Final evidence/provenance is in docs/phase3/final-native-validation.json. Updated
README, ROADMAP, RELEASE_READINESS, FPM_LIFECYCLE and SOAK_TESTING to distinguish
historical failures from the reproduced repair and tested checkpoint. Added
docs/PHASE3_DELIVERY.md with all requested delivery fields and exact continuation
commands. These final evidence/docs follow the archive; no native source changed.

Release remains EXPERIMENTAL; Phase 3 is partial. Next: frozen final-source
one-/six-/24-hour mixed and FPM workloads, upstream memory/tool follow-up, remote
database TLS/auth and cancellation acknowledgement, prepared caches/transactions,
broader HTTP/WebSocket certification, then individual hooks/framework adapters.
Keep io_uring filesystem NOT_IMPLEMENTED and userland ThreadPool BLOCKED.

## Phase 4 baseline verification — 2026-11-03

Verified clean local/remote phase3-hardening at179aa54. Git ancestry proves native
e5d94fd is included in archive source f3d63ac, and later ranges contain only docs/
evidence. Compared every one of304archive files with Git and reproduced r5 SHA
b0f544b3da61789a9cc649a8bc24869308787c9c63ca37358afd69e39ef1ec38.
No duplicate ROUDMAP was found. Created phase4-endurance branch without rewriting
history or changing the preserved archive.

Before code changes, make verify/audit/audit-fpm, audit-allocations.sh, poll runner
and native mapping checks PASS on isolated8.5.12. Clean archive extraction/build/
install/core/poll/WebSocket/network checks PASS on8.3.36/8.4.27/8.5.12. Raw evidence
is /tmp/asyncara-phase4-nrtjh3t1; durable baseline in docs/phase4/baseline.json.

Started unchanged frozen-baseline3600s mixed and FPM campaigns. FPM's initial
copied module was rejected by the mtime guard before workload execution; retained
preflight-failure.json, rebuilt the frozen checkout, used a new output path.
Do not infer completion from launch. Native/public runtime source stays frozen.
Inspection found unbounded Python response/sample retention in the FPM harness;
bound the harness before six-/24-hour campaigns while retaining all raw samples
and original RSS/resource assertions. Existing mixed coverage lacks UDP/TLS/DNS/
WebSocket/Oracle; expansion must be explicit. Dependency investigations follow.

## Phase 4 bounded endurance observation

Replaced only test-harness retention, leaving ext/ and src/ unchanged. FPM writes
every mixed response and sample incrementally; O(worker-count) aggregation
preserves all historical RSS/fd/thread/replacement assertions. Mixed-run analysis
uses two streaming JSONL passes with unchanged warmup and endpoint-median growth
checks. Whole-series mean replaces the descriptive median; raw data is retained.
Workload deadline and malformed-output failures now receive explicit reports.

Six Python regression cases PASS, including 200 random trace comparisons,
threshold equality rejection, fd/thread/worker failures, 200,000 samples within
64KiB collector memory, mixed warmup windows and malformed/duplicate completion.
Added them to fast CI. Real60s FPM smoke PASS:780main requests,772samples,maximum
2,788KiB beyond warmup plus all lifecycle faults.20s mixed/database smoke PASS.
Commands: python3 tests/soak/test_metrics.py; tests/soak/fpm.py --seconds60;
tests/soak/run.py --seconds20 --databases (new evidence directories each time).
The separate3600s baseline campaigns remain running, not marked passed.


## Phase 4 interruption recovery and expanded workload — 2026-11-03

On resumption, neither baseline3600s runner existed. Preserved234mixed samples
(last1195.491s) and15,036FPM samples(last1166.910s) as INTERRUPTED, not success.
Verified and stopped only the two surviving owned PostgreSQL/FPM fixtures.
Durable evidence: docs/phase4/interrupted-campaigns.json. Historical archives and
all raw evidence remain intact; ext/ and src/ remain byte-identical to baseline.

Added bounded Linux process observations, request PHP/native diagnostics, optional
DNS/dual-stack UDP/TLS1.2+1.3/HTTPS/ws+wss mixed work and independent Oracle fixture.
All existing workload cases/thresholds remain. Two expanded smoke attempts failed
the observer on /proc permission races despite completed PHP work. Added a
regression test; missing descriptor observations are explicit nulls with reasons.
Four observer tests and six collector tests PASS. Expanded epoll rerun PASS:
60.260s,115cycles,all five database implementations,ProcessPool/WebSocket recovery.
Poll and final FPM observer reruns remain in progress; no endurance is inferred.
Standalone libpq and matching-Clang PHP investigation continues separately.


Expanded corrected poll smoke PASS60.156s/136cycles; final FPM observer smoke
PASS60.597s/416main requests plus all lifecycle cases. The HTTP test peer retained
barrier bookkeeping after a deliberate incomplete concurrent request: independent
negative test failed with {'incomplete':1}. Moved cleanup to guaranteed finally;
regression and A/B/C/D audit PASS. Added observer/peer regressions to fast CI.
New fixture directories use0700; all original resource thresholds stay unchanged.

Dependency controls complete: standalone C without PHP/Asyncara, verified TLS,
libpq16.15/17.11/18.6; ASan/UBSan+LSan and Valgrind, zero suppressions. Resident500
connections show no lost allocations; unload after1or500 gives109bytes;20reloads
accumulate2180BIO bytes, plus2907GSS bytes for system16.15. Retain all failed
fixture attempts; pg_isready fixes startup race, negative Valgrind3s deadline
reproduces timeout, instrumented fixture now30s. Matching Clang18/PHP8.5.12/extension
probes still report PHP callback-type UB without or with Asyncara. No fix/clean
Clang pass claimed. docs/DEPENDENCY_FINDINGS.md and phase4/dependency-findings.json
contain commands, versions, stacks and hashes. No ext/ or src/ changes.


## Phase 4 frozen package and staged launch

Committed2e2f84f4c07dde3f1412fd40fcd905916611ea56. Source distribution
build/asyncara-0.1.0-dev-phase4-r1.tar.gz,323files,SHA256
bcaa6b88f0438ac797840b70a16ca4b43b2c92e2e270d24a0fd8b9a7a90ed898.
All bytes match Git. Fresh phpize/build/install/Composer/core/poll/WebSocket/network
PASS8.3.36/8.4.27/8.5.12; docs/phase4/package-r1.json retains complete steps.
make verify also PASS after harness changes. Historical r5 remains untouched.

First supervisor preflight rejected an untracked .local build symlink; no test
ran. Preserved failure; replaced only the owned link with an ignored dependency
directory. Detached supervisor2787353 launched at2026-11-03T13:50:42Z from clean
frozen2e2f84f. Live state: /tmp/asyncara-phase4-nrtjh3t1/campaign-2e2f84f-r2/state.json.
Runs3600s mixed+FPM, then21600s, then86400s only if both preceding reports pass.
Current stages remain RUNNING; launch is not completion. Runtime sources stay
frozen. Each stage records source/archive/module hashes and original assertions.

Added independent admission regression tests for existing SQLite128 and native
curl8-per-host/64-total bounds. All queued HTTP work completes; rejected SQLite
open recovers capacity after close.8.3/8.4/8.5 epoll+poll and GCC full ASan/UBSan
PASS. Added the test to the regular runner. Documented fixed versus configurable
limits and lack of a request-global cap for direct PostgreSQL/MySQL/Redis handles.
No runtime implementation was altered. Corrected a stale historical benchmark
sentence that contradicted later streaming/WebSocket sections.

New native fuzz runs PASS427893inputs/121s and678148inputs/61s; instrumented PHP
mutation runs PASS10107RESP/IPC/header inputs/60.116s and11631WebSocket inputs/
60.187s. No suppressions or threshold changes. Logs/corpora/hash attestation in
phase4/validation-r1.json. Existing three-sample benchmark suite reran on frozen
runtime with isolated8.5.12 CLI/FPM, all14cases recorded in
benchmarks/results/phase4-r1.json. Concurrent soak/fuzz load precludes attributing
performance differences to code; CPU pool was slower than sequential in its
median sample. Preserve that outcome along with favorable results.


## Phase 4 endurance failure, investigation open

Frozen2e2f84f FPM campaign FAILED after the last651.064s steady sample, around
14:01:54UTC. Four upstream operations overlapped, but the unrelated ticker advanced
once (existing requirement>=2). All raw logs/failure.json preserved; no threshold
changed. The preceding low-tick successful request had345.9ms timer lag and
0.412ms maximum Task step. Concurrent instrumented mutation is a correlation,
not proven cause. No native defect is asserted or speculative runtime fix made.
The mixed campaign continues; the stage gate cannot advance after this failure.
Added bounded request CPU usage, Linux runnable-wait/scheduled time and last-four
tick diagnostics to distinguish descheduling from blocking in a subsequent run.
Native/public runtime source remains frozen. docs/phase4/fpm-endurance-failure.json
retains the failed request evidence. EXPERIMENTAL classification is unchanged.

## Phase 4 failed stage and diagnostic checkpoint — 2026-11-03

The mixed run subsequently failed too: exit 255, unhandled network timeout,
2,023.583 wall seconds including fixture lifecycle, last sampled workload
1,940.227 seconds / 5,517 cycles / 55 process restarts. No completion record.
RSS endpoint medians +380 KiB, native bytes zero at sampled boundaries, fds23–24,
threads5. Preserve the failure despite bounded sampled resources. Supervisor
finished FAIL at14:24:29Z and did not start six-/24-hour stages. Durable report:
docs/phase4/campaign-failure.json; previous failure reports remain untouched.

Added only test instrumentation: bounded mixed network stages on exception;
request CPU/runqueue/tick observations; owned FPM syscall tracing; standalone C
epoll latency control. Original deadline/ticker/resource thresholds unchanged.
Deliberately stalled TLS handshake confirms original timeout propagation, stage
attribution and cleanup on8.3.36/8.4.27/8.5.12 with epoll/poll; added to CI.

Diagnostic outcomes: FPM600.145s/7,520main requests PASS with all lifecycle faults;
traced FPM300.074s/693requests PASS but5ms epoll_wait reached341.487ms. Standalone
C control300.004s/42,164waits records197waits>50ms and maximum489.310ms without
PHP/Asyncara. Host-level timing effects are demonstrated, but original failure
attribution remains unresolved. Mixed600.175s/2,179cycles PASS, final pending
work/native bytes zero; RSS endpoint+2,472KiB, short-window slope23,740KiB/hour
not extrapolated as a leak rate. Evidence: phase4/timing-diagnostics.json.

Commands: tests/soak/fpm.py --seconds600; tests/soak/fpm_trace.py --seconds300;
tests/soak/run.py --seconds600 --databases --extended-network --oracle-prefix;
tests/soak/test_network_failure.py on six version/backend combinations;
cc -std=c11 -D_POSIX_C_SOURCE=200809L -Wall -Wextra -Werror -O2
tests/soak/epoll_latency.c; standalone control300s. Every run used a new output
directory under /tmp/asyncara-phase4-nrtjh3t1; exact commands/manifests retained.

Also completed: installed r1 audit suites8.3/8.4/8.5; final connection-limit
six-version/backend cases; Valgrind connection regression zero errors/lost bytes
(3,888reachable); native TSan PASS; separate private-CA MariaDB and Oracle TLS
fixtures with100Redis reconnect cycles each PASS. Updated validation-r1.json,
ROADMAP, delivery, security, resource and lifecycle documents. No ext/ or src/
changes; release remains EXPERIMENTAL. Next: new immutable test checkpoint,
independent archive installation, then one-hour retry without concurrent builds
or fuzzing. Short diagnostic passes do not erase the two endurance failures.

## Phase 4 r2 attestation and retry — 2026-11-03

Committed and pushed35877408ced8de47a2c270d8f73e3bf076d5dca4 onphase4-endurance.
New archive build/asyncara-0.1.0-dev-phase4-r2.tar.gz contains336files, SHA256
3ef8e94b612daf4707bd85b979d63fd4dfae5186824a81b8e0ca61dfe5e4667b.
Every path/byte matches3587740; private-umask clean checkout reproduces the hash.
Fresh independent phpize/build/install/load/Composer/core/poll/WebSocket/network
checks PASS8.3.36/8.4.27/8.5.12. Local make verify PASS. All four hosted jobs at
https://github.com/asyncara/asyncara/actions/runs/41657094869 PASS. Public API
verified the results; gh lacks API authentication, while Git SSH push succeeds.
No force push or global configuration changes. Attestation:phase4/package-r2.json.

Detached supervisor2928653 started2026-11-03T14:53:29Z from clean frozen3587740.
State:/tmp/asyncara-phase4-nrtjh3t1/campaign-3587740-r1/state.json. Same native/public
runtime, additional test-only diagnostics, original thresholds. No concurrent
local builds/benchmarks/fuzzing scheduled. Both3600s jobs must pass before21600s,
and both21600s before86400s. Launch is not completion. Initial state and exact
supervisor hash are in phase4/campaign-3587740-launch.json. Earlier failures remain.

Precision correction: the original mixed exception trace identifies the timer
callback, not the waiting operation or deadline. Network work is a hypothesis;
do not treat it as established attribution. New per-operation diagnostics should
narrow a recurrence. Resource/HTTP/WebSocket/hook documentation keeps the current
limits and unsupported features explicit. Release assessment stays EXPERIMENTAL.

## Phase 4 FPM retry reproduced — 2026-11-03

At14:58:45Z the frozen3587740 FPM retry failed the unchanged ticker assertion
again. Last steady sample303.482s;2,676 recorded main responses. Failing HTTP work
overlapped, but ticker advanced once. New measurements:547.966ms wall,2.729ms CPU,
57.048ms runnable wait,535.189ms timer lag, maximum Task step3.869ms. There were
no concurrent local builds/fuzz/benchmarks. Preserve phase4/fpm-retry-failure.json;
no root-cause proof or speculative runtime fix. The original mixed exception's
network attribution also remains only a hypothesis. Mixed retry is still active;
the stage cannot advance to six-/24-hour work after this FPM failure.

Diagnostic full FPM run under strace failed its existing four-request reload
barrier before reaching steady-state work; retain fpm-four-worker-trace-600/
and fpm-four-worker-traces/. No barrier timeout was increased. A separate
targeted four-worker buffered/streamed HTTP trace now isolates the timing symptom,
using a test-only shutdown record with PID/request/time and original assertions.
Its script/prepend file and raw traces are private under the Phase4 evidence root;
no runtime source, host scheduler configuration or frozen campaign file changed.

The targeted trace reproduced the assertion at request10. A seccomp-filtered
trace reproduced it at request440 and recorded a requested5ms epoll_wait lasting
202.361ms in that failed worker. Timer slack was50,000ns. Both failed diagnostic
runs, exact script sources, request/PID evidence and syscall excerpts are retained
in phase4/fpm-syscall-investigation.json. Tracing affects timing; no runtime repair
or absence-of-defects claim follows. A separate PHP namespace wrapper measures
global native poll calls without strace; it already records5ms calls taking
535–717ms with113–352microseconds CPU. It is test-only instrumentation outside
the repository runtime. A concurrent standalone C control investigates shared
host delay. No pointer patching or host scheduling changes are used.

Extend the existing separate soak workflow to independent mixed/FPM one-hour
jobs on separate disposable hosted runners. Preserve weekly/manual triggers;
Phase4 runtime/harness pushes also trigger it, with no cancellation of active
campaigns. Keep every original harness assertion and retain reports/artifacts,
source archive/hash, PHP/FPM/compiler/OS/CPU/dependency versions. Mixed hosted
scope adds existing extended network cases, but does not include the local Oracle
fixture. Longer hosted campaigns are not claimed. YAML parse/diff checks pass;
hosted execution must provide the remaining validation. No system dependency was
installed on this shared development host.

## Phase 4 independent host and UDP diagnosis — 2026-11-03

Hosted624cbe9 fast CI passed all four jobs. Its mixed one-hour job failed after
445.844 wall seconds at IPv4 UDP receive; all other network stages completed,
maximum timer lag9.486ms. This narrows the symptom independently of the local
host timing delays. Downloaded public artifact13244506530; its SHA256 matches
GitHub's artifact digest. Preserve docs/phase4/hosted-mixed-failure.json and all
raw files. Hosted FPM remains running; no completion claim.

A local50,000-cycle UDP churn control stopped on duplicate live IPv4 endpoints.
The new deterministic tests/udp_ownership.php fails on the unchanged implementation:
a second bind to the first socket's address/port succeeds. PHP network.c sets
SO_REUSEADDR unconditionally during stream server bind. This can redirect a
single datagram into the newer endpoint. Exact endpoint addresses were absent
from the hosted failure, so that event's collision is not directly proven.
The local frozen mixed retry is deliberately interrupted before changing runtime
source; its prior samples are historical, not a one-hour pass. Original timing,
resource and protocol assertions stay unchanged. A new source checkpoint and
fresh endurance are required after the ownership fix.

Before this finding, the repository poll-profile diagnostic passed10.376s/84
four-worker requests; the default syscall mode passed10.555s/15requests. Three
PHP-version forwarding controls verify return values, original exceptions,
global function preservation and fatal records. These smoke controls are added
to CI. The earlier external600.256s/5,312request profile and overlapping bare
C epoll control are preserved independently, with no retrospective source claim.

## Phase 4 UDP ownership repair — 2026-11-03

Changed ext/php_asyncara.c and src/Asyncara/IO/UdpSocket.php: create an exclusive,
nonblocking close-on-exec UDP descriptor without PHP stream-server SO_REUSEADDR.
Allocate/register PHP stream ownership first, before socket(), so PHP allocation
bailout cannot orphan an unregistered fd. Use existing exported PHP socket-stream
operations; no Zend calls on workers and no event-loop rewrite. Bind errors retain
errno and close their temporary stream. Load exception/cleanup dependencies before
acquisition so EMFILE cannot cause failed first autoload during recovery.

Evidence: old frozen3587740 deterministically delivers a datagram to a newer
same-endpoint socket while the original times out, on IPv4 and IPv6. New ownership
regression and50,000-cycle churn pass. Ownership tests pass8.3.36/8.4.27/8.5.12,
epoll/poll, GCC ASan/UBSan and DEBUG. Valgrind zero errors, definite/indirect/possible
loss0, reachable3,888bytes, suppressions0. New scripts/test-udp-limits.py limits a
child to128fds: native EMFILE, release/reopen and cleanup pass all versions and
GCC instrumentation. Parent/host limits are untouched.

Development failures retained: first resource arginfo type triggered DEBUG's
resource-is-not-a-zend_type assertion; corrected to the same untyped-return
convention as PHP resource-returning functions, rebuilt all variants. Initial
Valgrind omitted existing isolated loader symbols and could not start; corrected
command uses .local/debug, no host install. Preliminary verify passed but audit
rejected its stale binary after the arginfo correction; final rebuild followed by
make verify/audit/audit-fpm passes. No warning/assertion/test was suppressed.

Existing network-edge suite passes epoll/poll. FPM now retains a UDP socket through
ordinary, pending, timeout and fatal request teardown. Expanded60.491s lifecycle
passed300mainrequests plus A/B/C/D, recycling, reload, forced replacement, fatal
memory and timeout cases; max growth beyond warmup2,788KiB, idle6fds/1thread.
See docs/phase4/udp-ownership-fix.json. The prior local frozen mixed attempt was
interrupted intentionally at2,761.879s/6,524cycles; full reports retained, no pass
inferred. Historical r2 and prior archives contain the UDP defect. A corrected
archive and new endurance checkpoint are required; classification EXPERIMENTAL.

Corrected expanded mixed smoke passed60.106s/111cycles with separate Oracle8.4.13,
MariaDB11.8.9, PostgreSQL16.15, Redis7.0.15, SQLite and extended network work; no
stderr or final pending work. The final native helper's initial FPM60s pass
preceded the small PHP dependency-preload adjustment; its separate EMFILE tests
pass all versions. Archive validation and a new frozen endurance will test the
complete corrected source together. Do not retroactively rename the earlier run.

## Corrected r3 distribution and new endurance freeze — 2026-11-03

Committed/pushed76e6557bf3925e58e6db3dee07b30aac33a9f9c1. Source archive
build/asyncara-0.1.0-dev-phase4-r3.tar.gz has349files and SHA256
b307ea18d785a74236b236974207878e19fca0c484c62472093eee452408c79a.
All Git paths/bytes match. A private-umask clean checkout produces identical
bytes. Independent extracted phpize/build/install/load, Composer, full epoll/poll,
WebSocket and network tests pass8.3.36/8.4.27/8.5.12, including UDP ownership and
EMFILE. Hosted41668853898 passes all four regression/database jobs at this source.
Final complete-source FPM smoke passed60.063s/432mainrequests, max2,820KiB beyond
warmup, all lifecycle/fault cases and idle6fd/1thread assertions. Attestation:
docs/phase4/package-r3.json. Earlier archives remain unchanged and contain the
UDP ownership defect; no GitHub release is published.

New isolated clean worktree /tmp/asyncara-phase4-nrtjh3t1/frozen-76e6557 was built
separately. At16:03:58Z detached supervisor3030406 began local mixed/FPM one-hour
attempts, raw state /tmp/asyncara-phase4-nrtjh3t1/campaign-76e6557-r1/state.json.
No native/public runtime changes or local builds/fuzz/benchmarks during this
campaign. Each workload now has its own3600/21600/86400second chain; it advances
only after its own exact-duration clean pass and unchanged frozen fingerprint.
A failing FPM chain cannot certify FPM or advance, while independent mixed work
may continue gathering evidence. Overall success requires all six passes. This
changes orchestration coupling, not workload assertions or resource thresholds.
Live state records child failures immediately as RUNNING_WITH_FAILURE. Full
supervisor source/hash and launch snapshot: phase4/campaign-76e6557-launch.json.
Do not infer completion from launch. Hosted41668853738 also runs corrected mixed
work; its FPM job waits behind the still-running historical624cbe9 FPM job.

Read-only database review clarified another boundary: current PostgreSQL options
reject sslcert/sslkey and Connector/C exposes CA verification without explicit
client key/certificate fields. Implicit libpq defaults/environment are not a
certified mTLS API. Updated DATABASE_COMPATIBILITY.md; no driver change or new
mTLS capability claimed. EXPERIMENTAL remains the release assessment.
