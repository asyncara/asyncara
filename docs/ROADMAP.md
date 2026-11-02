# Implementation status

Updated 2026-11-02. Status describes implemented behavior and executed evidence, not interface declarations. Release assessment after the independent audit: **EXPERIMENTAL**. The original archive is preserved with known defects; the working tree repairs seven high-severity issues. See [INDEPENDENT_AUDIT.md](INDEPENDENT_AUDIT.md) and its durable evidence. Broad production readiness is not complete.

| Area | Status | Evidence / remaining work |
|---|---|---|
| Environment, PHP internals and upstream research | IMPLEMENTED_AND_TESTED | RESEARCH.md, inspected source, RFC status checked |
| Native extension, direct build, phpize/configure | IMPLEMENTED_AND_TESTED | Loaded binaries; warning-free builds on PHP 8.3/8.4/8.5 NTS |
| Fiber scheduler / Tasks / Futures | IMPLEMENTED_AND_TESTED | FIFO batches, lifecycle/errors, 10,000-task workload |
| Timers, cancellation, deadlines | IMPLEMENTED_AND_TESTED | monotonic heap, races, cleanup, repeat/callLater APIs |
| Groups / Lock / Semaphore / Event / Queue | IMPLEMENTED_AND_TESTED | fairness, contention, deadlocks, cancellation handoffs |
| Explicit context / async producer streams | IMPLEMENTED_AND_TESTED | inheritance/isolation, backpressure, failures, early close |
| epoll / poll | IMPLEMENTED_AND_TESTED | complete core suite with each Linux backend |
| TCP / UDP / DNS / explicit TLS | PARTIALLY_IMPLEMENTED | tested local IPv4/TLS/DNS; IPv6 and TLS edge cases need expansion |
| HTTP client | PARTIALLY_IMPLEMENTED | concurrent curl multi, pooling, HTTP(S), JSON/form, chunking/compression tested; streaming and multipart absent |
| Native filesystem | PARTIALLY_IMPLEMENTED | basic operations, stable handles, seek/sync and chunk backpressure tested; atomic replacement and broader permission/race cases absent |
| SQLite | IMPLEMENTED_AND_TESTED | worker C API, binds, ordering, transactions, cancellation and cleanup; buffered scope documented |
| PostgreSQL | PARTIALLY_IMPLEMENTED | native nonblocking libpq, binds, transactions, notifications tested locally; remote auth/TLS, server-side prepare cache, cancellation acknowledgment incomplete |
| MySQL / MariaDB | PARTIALLY_IMPLEMENTED | Connector/C nonblocking prepared state machine tested on MariaDB 11.8.9; MySQL variants/auth/TLS matrix incomplete |
| Redis | PARTIALLY_IMPLEMENTED | RESP2/RESP3 common types, pipeline, transactions, Pub/Sub tested; attributes/streaming aggregates/cluster support absent |
| Transparent hooks | PARTIALLY_IMPLEMENTED | sleep/usleep opt-in tested; signal semantics partial; all other groups explicitly unsupported |
| ProcessPool | IMPLEMENTED_AND_TESTED | registry, bounded framing/queue, distinct overlapping CPU processes, timeout/crash/replacement and close |
| Userland ThreadPool | BLOCKED | tested interpreters are NTS; no safe executor implementation |
| io_uring filesystem | NOT_IMPLEMENTED | separate kernel NOP probe succeeded; no Asyncara operations integrated |
| kqueue / IOCP / non-Linux ports | NOT_IMPLEMENTED | fallback architecture exists; no platform certification |
| WebSocket | NOT_IMPLEMENTED | full framing/handshake/fragmentation implementation required |
| Composer / PSR-18 and PSR-17 adapter | IMPLEMENTED_AND_TESTED | Composer validation; Nyholm PSR-7 integration case |
| Laravel / Symfony / WordPress / PSR-3 / alternate loops | NOT_IMPLEMENTED | integration guidance only, no framework lifecycle certification |
| Diagnostics | PARTIALLY_IMPLEMENTED | measured max task step/timer lag, live counts/memory; native aggregate allocation accounting; full traces and third-party memory accounting incomplete |
| Memory hardening | PARTIALLY_IMPLEMENTED | independent native and fully instrumented PHP ASan/UBSan, native TSan, debug PHP, allocation injection and fatal file/SQL cleanup; Valgrind clean in tested paths; ZTS/fuzzing/foreign allocator coverage incomplete |
| Stress | PARTIALLY_IMPLEMENTED | 300 independent mixed cycles; FPM sequential, within-request and four-worker concurrency; 1,000/10,000 tasks and latency samples; extended soak incomplete |
| Benchmarks | PARTIALLY_IMPLEMENTED | raw three-sample HTTP/FPM/Swoole/native, AMP timers, file, SQLite, queue, CPU results; remaining workload matrix incomplete |
| Developer/API/installation/security documentation | IMPLEMENTED_AND_TESTED | executable examples and documented commands; docs distinguish unsupported features |

## Minimum meaningful release evidence

A compiled native extension; B Fiber scheduler; C TCP; D concurrent HTTP; E worker filesystem; F worker SQLite; G Tasks/Futures/cancellation/deadlines; H synchronization; I structured groups; J process CPU parallelism; K verified limited timer hooks; M automated integration tests; N reproducible local/phpize builds; O accurate documentation are present and tested. L is bounded evidence: no known critical invalid-memory defect in the tested paths, with clean instrumentation runs described in SECURITY.md; this is not a universal memory-safety guarantee.

## Phase 3 hardening checkpoints

| Work | Status | Evidence / boundary |
|---|---|---|
| P0 preserved baseline and seven audit negative controls | IMPLEMENTED_AND_TESTED | audit1 source match; original archive reproduces all seven failures; baseline commands pass; checkpoint b0dfa41 |
| CI regression and database lifecycle workflows | IMPLEMENTED_BUT_NOT_FULLY_TESTED | local commands executed; hosted workflows added but not yet run remotely |
| P1 admission and buffer limits | IMPLEMENTED_AND_TESTED | resource_bounds.php, database/resource_bounds.php; 8.3/8.4/8.5 and instrumented core; notification overflow/fatal cleanup Valgrind; see RESOURCE_LIMITS.md |
| P2 protocol/allocation fuzzing | IMPLEMENTED_AND_TESTED | committed seeds, 100 RESP cases, real IPC failures, 326,134 native ASan/UBSan fuzz inputs and 12,737 instrumented PHP mutations; bounded coverage in FUZZING.md |
| P3 frozen mixed soak harness | PARTIALLY_IMPLEMENTED | 20.173-second smoke passed; one-hour run next; 6h/24h not executed; SOAK_TESTING.md |
| Third-party aggregate memory accounting | PARTIALLY_IMPLEMENTED | SQLite value limits; other foreign allocators remain outside native ledger |

## Next engineering work, in order

1. Review the audit fixes and corrected source archive; add the independent regressions to CI. Expand protocol/allocation fuzzing, sustained multiworker FPM soak, remote SQL auth/TLS and ZTS coverage. Establish third-party memory/process quotas and bounded notification/result queues. Preserve the independently verified debug/instrumented PHP and fatal-teardown tests.
2. Add typed errno reporting, atomic replacement and io_uring file operations only after real completion/cancellation/fallback tests. Preserve the worker fallback.
3. Complete HTTP streaming/backpressure and multipart, WebSocket protocol support, IPv6/TLS edge cases, and database remote auth/TLS/cancellation matrices.
4. Extend individual transparent hooks with exact parameter/warning/resource/return/signal behavior tests; keep unsupported groups explicit.
5. Add common DB manual transaction APIs where missing, server-side prepared caches, richer Redis types and safe notification/pipeline policies.
6. Add framework/PSR-3/loop adapters and production telemetry, then certify supported OS/PHP/SAPI combinations. Only after a stable boundary consider ZTS interpreter-thread execution.

Continuation commands: `make audit`, `make audit-fpm`, `./scripts/audit-allocations.sh`, `make verify`, `ASYNCARA_BACKEND=poll ./scripts/test.sh`, `./scripts/phpize-local.sh`, `./benchmarks/run.py`. Consult WORKLOG.md and build logs before changing a status. All source and scripts are in the repository; external dependencies/build products are isolated and ignored.
