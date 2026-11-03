# Implementation status

Updated 2026-11-03 (Europe/Berlin). Status describes implemented behavior and executed evidence, not interface declarations. Release assessment after the independent audit: **EXPERIMENTAL**. The original archive is preserved with known defects; the working tree repairs seven high-severity issues. See [INDEPENDENT_AUDIT.md](INDEPENDENT_AUDIT.md) and its durable evidence. Broad production readiness is not complete.

| Area | Status | Evidence / remaining work |
|---|---|---|
| Environment, PHP internals and upstream research | IMPLEMENTED_AND_TESTED | RESEARCH.md, inspected source, RFC status checked |
| Native extension, direct build, phpize/configure | IMPLEMENTED_AND_TESTED | Loaded binaries; warning-free builds on PHP 8.3/8.4/8.5 NTS |
| Fiber scheduler / Tasks / Futures | IMPLEMENTED_AND_TESTED | FIFO batches, lifecycle/errors, 10,000-task workload |
| Timers, cancellation, deadlines | IMPLEMENTED_AND_TESTED | monotonic heap, races, cleanup, repeat/callLater APIs |
| Groups / Lock / Semaphore / Event / Queue | IMPLEMENTED_AND_TESTED | fairness, contention, deadlocks, cancellation handoffs |
| Explicit context / async producer streams | IMPLEMENTED_AND_TESTED | inheritance/isolation, backpressure, failures, early close |
| epoll / poll | IMPLEMENTED_AND_TESTED | complete core suite with each Linux backend |
| TCP / UDP / DNS / explicit TLS | PARTIALLY_IMPLEMENTED | tested local IPv4/IPv6 TCP/UDP, dual-address DNS, TLS1.2/1.3 SNI/ALPN, partial I/O and cancellation; WAN/TLS state edge cases remain |
| HTTP client | PARTIALLY_IMPLEMENTED | concurrent curl multi, pooling, HTTP(S), JSON/form, chunking/compression tested; bounded HTTP/1.1 response/file/iterable streaming and one-file curl MIME tested; broader protocol/upload matrix incomplete |
| Native filesystem | PARTIALLY_IMPLEMENTED | basic operations, stable handles, seek/sync, chunk backpressure, typed errno and atomic replacement tested; crash durability / broader filesystem certification incomplete |
| SQLite | IMPLEMENTED_AND_TESTED | worker C API, binds, ordering, transactions, cancellation and cleanup; buffered scope documented |
| PostgreSQL | PARTIALLY_IMPLEMENTED | native nonblocking libpq, binds, transactions, notifications tested locally; private-CA SCRAM/verify-full tested; server-side prepare cache and cancellation acknowledgment incomplete |
| MySQL / MariaDB | PARTIALLY_IMPLEMENTED | Connector/C tested separately on MariaDB 11.8.9 and Oracle MySQL 8.4.13; strict IP SAN TLS/auth; broader variants unverified |
| Redis | PARTIALLY_IMPLEMENTED | RESP2/RESP3 common types, pipeline, transactions, Pub/Sub tested; attributes/streaming aggregates/cluster support absent |
| Transparent hooks | PARTIALLY_IMPLEMENTED | sleep/usleep opt-in tested; signal semantics partial; all other groups explicitly unsupported |
| ProcessPool | IMPLEMENTED_AND_TESTED | registry, bounded framing/queue, distinct overlapping CPU processes, timeout/crash/replacement and close |
| Userland ThreadPool | BLOCKED | NTS and single-interpreter ZTS CLI tested; no safe interpreter-thread executor |
| io_uring filesystem | NOT_IMPLEMENTED | separate kernel NOP probe succeeded; no Asyncara operations integrated |
| kqueue / IOCP / non-Linux ports | NOT_IMPLEMENTED | fallback architecture exists; no platform certification |
| WebSocket | PARTIALLY_IMPLEMENTED | ws/wss client, raw TCP server upgrade, bounded framing/messages, controls, cancellation and independent Python17.4 interop tested; extensions/server-WSS/browser/long-run certification incomplete |
| Composer / PSR-18 and PSR-17 adapter | IMPLEMENTED_AND_TESTED | Composer validation; Nyholm PSR-7 integration case |
| Laravel / Symfony / WordPress / PSR-3 / alternate loops | NOT_IMPLEMENTED | integration guidance only, no framework lifecycle certification |
| Diagnostics | PARTIALLY_IMPLEMENTED | measured max task step/timer lag, live counts/memory; native aggregate allocation accounting; full traces and third-party memory accounting incomplete |
| Memory hardening | PARTIALLY_IMPLEMENTED | independent native and fully instrumented PHP ASan/UBSan, native TSan, debug PHP, allocation injection and fatal file/SQL cleanup; Valgrind clean in tested paths; bounded fuzzing and ZTS CLI tested; foreign allocator coverage incomplete; 109-byte upstream libpq TLS unload leak documented |
| Stress | PARTIALLY_IMPLEMENTED | 300 independent mixed cycles; FPM sequential, within-request and four-worker concurrency; 1,000/10,000 tasks and latency samples; extended soak incomplete |
| Benchmarks | PARTIALLY_IMPLEMENTED | raw three-sample HTTP/FPM/Swoole/native, AMP timers, file, SQLite, queue, CPU results; remaining workload matrix incomplete |
| Developer/API/installation/security documentation | IMPLEMENTED_AND_TESTED | executable examples and documented commands; docs distinguish unsupported features |

## Minimum meaningful release evidence

A compiled native extension; B Fiber scheduler; C TCP; D concurrent HTTP; E worker filesystem; F worker SQLite; G Tasks/Futures/cancellation/deadlines; H synchronization; I structured groups; J process CPU parallelism; K verified limited timer hooks; M automated integration tests; N reproducible local/phpize builds; O accurate documentation are present and tested. L is bounded evidence: no known critical invalid-memory defect in the tested paths, with clean instrumentation runs described in SECURITY.md; this is not a universal memory-safety guarantee.

## Phase 3 hardening checkpoints

| Work | Status | Evidence / boundary |
|---|---|---|
| P0 preserved baseline and seven audit negative controls | IMPLEMENTED_AND_TESTED | audit1 source match; original archive reproduces all seven failures; baseline commands pass; checkpoint b0dfa41 |
| CI regression and database lifecycle workflows | IMPLEMENTED_AND_TESTED | all four hosted jobs passed at final native source e5d94fd: PHP 8.3/8.4/8.5 regression and database lifecycle; [run 41549782614](https://github.com/asyncara/asyncara/actions/runs/41549782614); scheduled endurance is separate |
| P1 admission and buffer limits | IMPLEMENTED_AND_TESTED | resource_bounds.php, database/resource_bounds.php; 8.3/8.4/8.5 and instrumented core; notification overflow/fatal cleanup Valgrind; see RESOURCE_LIMITS.md |
| P2 protocol/allocation fuzzing | IMPLEMENTED_AND_TESTED | committed seeds, 100 RESP cases, real IPC failures, 326,134 native ASan/UBSan fuzz inputs and 12,737 instrumented PHP mutations; bounded coverage in FUZZING.md |
| P3 frozen mixed soak harness | PARTIALLY_IMPLEMENTED | earlier baseline 20,855 cycles and HTTP-streaming checkpoint 19,761 cycles each ran one hour; final e5d94fd passed 300.073s / 1,331 cycles, RSS endpoint +200KiB, flat fds/threads; Phase 4 1h attempt failed at an unattributed timeout; 6h/24h not started; SOAK_TESTING.md |
| P4 expanded FPM lifecycle | PARTIALLY_IMPLEMENTED | sequential/within-request/multi-worker/streaming concurrency, recycling/reload/kill/timeout/fatal/pending isolation; historical RSS failures repaired with dedicated mappings, three unchanged five-minute runs passed; final e5d94fd: 3,756 requests / 300.106s, maximum 2,888KiB beyond warmup; endurance incomplete; FPM_LIFECYCLE.md |
| P5 database TLS/auth and protocol repairs | PARTIALLY_IMPLEMENTED | Private-CA matrix, Oracle 8.4.13, certificate identity fix, nonblocking transaction control and buffered TLS wakeups; WAN/mTLS/cancel acknowledgement remain unverified |
| P6 ZTS investigation | PARTIALLY_IMPLEMENTED | PHP 8.5.11 ZTS DEBUG CLI full tests and database suite pass; concurrent interpreter threads unverified, ThreadPool BLOCKED |
| Third-party aggregate memory accounting | PARTIALLY_IMPLEMENTED | SQLite value limits; other foreign allocators remain outside native ledger |

## Next engineering work, in order

Phase 4 starts from verified pushed commit `179aa54`. The preserved r5 archive
matches `f3d63ac` and includes final native source `e5d94fd`. Fresh three-version
archive installs and baseline regression commands pass. First one-hour mixed/FPM
attempts were interrupted after about 20 minutes; neither passed. Test collectors
now use bounded memory and explicit process-resource observations. The expanded
60-second epoll/poll mixed smokes passed, including existing DNS/UDP/TLS/HTTPS/ws/wss
and a separate Oracle fixture. FPM observer smoke and failed-barrier regression
passed. Frozen 2e2f84f one-hour attempts then failed: FPM ticker responsiveness
after 651s, an unattributed mixed timeout after 5,517 sampled cycles. No six-/24-hour
stage started. A 600s FPM diagnostic passed; syscall and standalone C controls
show host epoll oversleep, without resolving the original failure attribution.
Standalone dependency probes
reproduce libpq unload and PHP callback-type findings (DEPENDENCY_FINDINGS.md).
Runtime source was unchanged through `624cbe9`; the UDP repair below establishes
a new native checkpoint. See
[PHASE4_DELIVERY.md](PHASE4_DELIVERY.md) and its durable evidence.

Checkpoint `3587740` adds tested connection-limit cases and failure diagnostics.
Its r2 archive is reproducible and independently installs/tests on all three PHP
branches; all four hosted CI jobs pass. A detached retry started2026-11-03T14:53Z
with the same runtime, unchanged thresholds and no concurrent local builds/fuzzing.
Consult the live campaign state and delivery report; starting a run is not a pass.

The3587740 FPM retry also failed after303.482s with535ms timer lag and one ticker
step; CPU and runnable-wait diagnostics are preserved. Mixed retry was deliberately
interrupted after 2,761.879s / 6,524 cycles when the independent hosted failure
led to a reproducible UDP ownership defect.
Six-/24-hour promotion is prevented. Diagnosis continues without lowering limits.

Hosted source `624cbe9` passes all four fast regression/database jobs. Its mixed
one-hour job failed at IPv4 UDP receive; the independent FPM job passed
3,600.036 seconds / 67,520 main requests. This is historical pre-repair evidence. A separate
600.256-second poll profile passed 5,312 requests and recorded native wait delays
overlapping those in a PHP-free epoll control. This is timing evidence, not a
replacement for the failed endurance tests. Current test matrix and limitations:
[PHASE4_DELIVERY.md](PHASE4_DELIVERY.md).

UDP exclusive endpoint ownership is now repaired and regression-tested on
8.3/8.4/8.5, epoll/poll, DEBUG, GCC ASan/UBSan and Valgrind. Child-only `EMFILE`
tests verify error/cleanup dependency loading and admission recovery. Core/audit,
network-edge and FPM lifecycle checks pass. This is a new native source boundary:
earlier endurance and archives cannot certify it. Corrected `76e6557` r3 archive
independently builds/installs/tests on all three PHP branches and reproduces its
checksum; all four hosted CI jobs pass. The subsequent local one-hour attempts
were interrupted at last samples 972.031s mixed / 997.320s FPM after independently
reproducing destination port wrap and a synchronous resolver fallback. Strict
literal-IP/port validation and its regressions now pass all three PHP branches,
epoll/poll, DEBUG, GCC ASan/UBSan and Valgrind; full verify/audit/FPM and a 60.119s
expanded mixed smoke pass. Native C is unchanged from 76e6557. A new archive and
frozen campaign are required. Existing timing failures and thresholds remain.
See phase4/udp-destinations-fix.json and phase4/hosted-fpm-1h.json.

1. Extend the repaired FPM RSS/lifecycle coverage without weakening checks through one-/six-/24-hour frozen final-source soaks. The four hosted regression/database jobs are passing; scheduled endurance is separate. Repeated libpq unloads accumulate allocations; resident connection controls do not. Follow up the unresolved upstream lifetime issue. Expand remote SQL auth/TLS and acknowledged cancellation tests; keep native and fully instrumented PHP regressions. Review remaining third-party memory/process quotas.
2. Broaden atomic-file ACL/mount/race and crash-durability testing. io_uring file operations remain optional and require complete completion/cancellation/fallback tests; preserve the worker fallback.
3. Extend HTTP streaming/multipart certification and multiple-file uploads, WebSocket endurance/interoperability coverage, IPv6/TLS edge cases, and database remote auth/TLS/cancellation matrices.
4. Extend individual transparent hooks with exact parameter/warning/resource/return/signal behavior tests; keep unsupported groups explicit.
5. Add common DB manual transaction APIs where missing, server-side prepared caches, richer Redis types and safe notification/pipeline policies.
6. Add framework/PSR-3/loop adapters and production telemetry, then certify supported OS/PHP/SAPI combinations. Only after a stable boundary consider ZTS interpreter-thread execution.

Continuation commands: `make audit`, `make audit-fpm`, `./scripts/audit-allocations.sh`, `make verify`, `ASYNCARA_BACKEND=poll ./scripts/test.sh`, `./scripts/phpize-local.sh`, `./benchmarks/run.py`. Consult WORKLOG.md and build logs before changing a status. All source and scripts are in the repository; external dependencies/build products are isolated and ignored.
