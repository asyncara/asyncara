# Implementation status

## Fast reliability track (2026-11-07)

Native API First remains unchanged. [Fast reliability](FAST_RELIABILITY.md)
supersedes repeated multi-hour campaigns as the developer feedback strategy.
Original hour PASS and six-hour FAIL are preserved; historical zero-tick cause
remains unresolved. No 24-hour FPM result exists for that campaign.

| Item | Status | Evidence / limits |
|---|---|---|
| Accepted close cleanup (`6ee73d8`) | IMPLEMENTED_AND_TESTED | Native file/SQLite controls fail on `d74f3b3`, pass on candidate; ordinary cancellation retained. This supersedes the historical unbuilt entry below for this isolated branch only |
| Interrupted worker completion notification | IMPLEMENTED_AND_TESTED | Minimal C EINTR retry; 10,000 injected completion transitions/backend pass. Both prior sources fail. Not attribution of historical FPM zero ticks |
| Deterministic kernel/virtual-time framework | IMPLEMENTED_AND_TESTED | PHP 8.3/8.4/8.5 × epoll/poll × three source variants, 10,000 iterations; separate real kernel and virtual scheduler scope |
| Gate A expanded seed/resource suite | IMPLEMENTED_AND_TESTED | 190.920 seconds, 10,000 iterations/path, three seeds, epoll/poll; 140,000 total worker jobs and zero tracked owners at checkpoints |
| Gate B 200,000 original FPM main responses | IMPLEMENTED_BUT_NOT_FULLY_TESTED | Count runner prepared; target cannot fit 30 minutes with unchanged four-worker/200ms workload. Partial execution must not become PASS |
| Gate C / extended observation | NOT_IMPLEMENTED | Execution deferred until A/B gates justify it; no automatic six-hour/24-hour run |
| Original zero-tick attribution / distribution-PHP Valgrind discrepancy | BLOCKED | Unresolved evidence; no claim that new notification fault injection explains either |

## Isolated native track (2026-11-07)

This branch starts from frozen `d74f3b3`; the Phase 4 tables below are inherited
checkpoint records, not a live report of the separate running campaign.

| Item | Status | Evidence / limits |
|---|---|---|
| Accepted file/SQLite close ownership under cancellation | IMPLEMENTED_BUT_NOT_FULLY_TESTED | `ext/workers.c` dispatch correction and deterministic native regression written; NOT_YET_BUILT / NOT_YET_TESTED while Phase 4 FPM is active. Static source analysis only; [engineering report](NATIVE_CORE_ENGINEERING.md) |
| Native close build, PHP 8.3/8.4/8.5 regressions and sanitizers | BLOCKED | Intentionally deferred by campaign resource/isolation policy, not an environment/compiler failure |

No Phase 4 result certifies this new native patch. No new hooks or backend are added.

Updated 2026-11-07 (Europe/Berlin). Status describes implemented behavior and executed evidence, not interface declarations. Release assessment after the independent audit: **EXPERIMENTAL**. The original archive is preserved with known defects; the working tree repairs seven high-severity issues. See [INDEPENDENT_AUDIT.md](INDEPENDENT_AUDIT.md) and its durable evidence. Broad production readiness is not complete.

## Native API lifecycle continuation

The official direction is [Native Async API First — Optional Transparent
Compatibility](NATIVE_API_FIRST.md). No new hooks, driver replacements or backend
claims are part of this continuation. [IO_LIFECYCLE_CONTRACT.md](IO_LIFECYCLE_CONTRACT.md)
documents eager submission, shared waiting versus owned cancellation, draining,
partial results, backpressure and request shutdown against existing source.

| Item | Status | Evidence / remaining work |
|---|---|---|
| Buffered HTTP cancellation ownership | IMPLEMENTED_AND_TESTED | `tests/io_lifecycle.php`: public Task and client permit now remain owned until native cancellation collection; prior implementation failed the completion-listener regression |
| SQLite connection copy protection | IMPLEMENTED_AND_TESTED | Same regression rejects cloning/serialization/unserialization of request-local native identity; original implementation allowed copies |
| Shared-waiter/file/SQLite lifecycle regressions | IMPLEMENTED_AND_TESTED | Six cases in `tests/io_lifecycle.php`, included in `scripts/test.sh`; epoll/poll PHP 8.5.12 and GCC ASan/UBSan pass |
| Local uninstrumented FPM cause and endurance | PARTIALLY_IMPLEMENTED | Original zero-tick failures remain unresolved. Controlled worker stops reproduce fewer-than-two ticks, not attribution of original failures. Original acceptance workload and thresholds remain unchanged |
| Comprehensive lifecycle certification | PARTIALLY_IMPLEMENTED | Existing functional suites pass; shared operation-wide cancellation authority, arbitrary shutdown deadlines, wider database/platform coverage and long local FPM acceptance remain limited |

Current executions and frozen-source acceptance state are recorded in
[PHASE4_DELIVERY.md](PHASE4_DELIVERY.md). Historical sections below retain the
source identities and scope of their original measurements.

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

## Phase 4 verified endurance checkpoint

| Work | Status | Executed scope / remaining work |
|---|---|---|
| UDP endpoint ownership, descriptor exhaustion and destination validation repairs | IMPLEMENTED_AND_TESTED | f2abb55; 8.3/8.4/8.5, epoll/poll, DEBUG, GCC ASan/UBSan and Valgrind regressions |
| Reproducible r4 distribution | IMPLEMENTED_AND_TESTED | 355 Git-matching source files; isolated three-version installations/tests; package-r4.json |
| Frozen 1h / 6h / 24h mixed endurance | IMPLEMENTED_AND_TESTED | same f2abb55 source/binary, Linux PHP8.5.12 NTS epoll; 8,488 / 69,836 / 294,899 cycles; explicit local database/network scope in endurance-r4.json |
| Frozen hosted 1h mixed and FPM | IMPLEMENTED_AND_TESTED | r4 archive byte identity and official artifact digests; FPM 67,824 requests plus lifecycle/fault cases; hosted-r4.json |
| Local FPM and 6h / 24h FPM endurance | PARTIALLY_IMPLEMENTED | local attempts failed responsiveness at364.506s and998.680s; six-/24-hour stages unstarted; all original assertions retained |
| Scheduling observer and independent wait controls | IMPLEMENTED_AND_TESTED | reproduced zombie-exit ownership error repaired; live identity/cgroup ancestry tests; epoll/poll/nanosleep controls and opt-in combined native-call/syscall diagnostics; these do not certify FPM endurance |
| Direct libc wait probe and bounded interval analysis | IMPLEMENTED_AND_TESTED | 180s/900s FPM diagnostics and 60s full lifecycle smoke; native forwarding/thread/errno/fd controls, scoped ASan/UBSan/TSan/static analysis; instrumentation only, not a production hook |
| Diagnostic logging failure propagation | IMPLEMENTED_AND_TESTED | negative controls reproduce ignored PHP write failures and accepted instrumentation errors; PHP8.3/8.4/8.5 and seven analyzer tests pass; historical logs rechecked; changed profiler not yet run in FPM |

Mixed 24-hour steady RSS endpoint change is +260KiB, with zero final scheduled
work. Recorded maximum timer lag exceeds one second; this is no latency SLA.
The independent review recalculates trends from raw samples and preserves all
historical failures. The native/public source and r4 archive remain unchanged.
No new protocol, database TLS, hook, platform or framework capability follows
from these scoped endurance passes. Overall release remains **EXPERIMENTAL**.

## Next engineering work, in order

Diagnose the failed FPM-only campaign at
`/tmp/asyncara-phase4-nrtjh3t1/campaign-f2abb55-fpm-solo-r1/state.json` using the
preserved failure and bounded diagnostics. Native/public runtime source is
unchanged; a host wait-control delay alone does not establish a runtime defect
or exonerate the failed request. The observer repair and combined tracing do not
weaken any workload assertion. Actual source identities, measurements, dependency
findings and prior attempts are in [PHASE4_DELIVERY.md](PHASE4_DELIVERY.md) and
WORKLOG.md. The latest67e4530 hosted mixed/FPM hour and all four CI jobs also pass;
native/public runtime is unchanged from r4. No uninstrumented local retry is
currently running. Use the verified direct probe for the next full lifecycle
diagnostic; keep its instrumentation scope separate from endurance certification.
That one-hour diagnostic started November6 at17:49:09Z on frozen f2abb55 with
tools7e104c0. Observe `fpm-full-wait-probe-r2/state.json` under the existing evidence
root; its launch attestation is in phase4/fpm-wait-hour-launch.json. No completed
hour or automatic six-/24-hour advancement is claimed.
The later profiler-write repair does not modify that campaign's private tools;
check its completed logs with the hardened analyzer before accepting measurement
evidence. See phase4/profile-log-errors.json for the reproduced tool failures.
The detached `fpm-full-wait-probe-r2-review/state.json` checker waits for that
terminal result, verifies the old warning paths, and queues one60s full lifecycle
smoke of the repaired profiler only after an instrumented-hour PASS. Its exact
code is retained in phase4/profile-log-review-launch.json. No longer stage starts.

1. Complete the frozen FPM-only one-/six-/24-hour chain without weakening checks; hosted one-hour success and mixed 24-hour success remain separate evidence. The four hosted regression/database jobs are passing; scheduled endurance is separate. Repeated libpq unloads accumulate allocations; resident connection controls do not. Follow up the unresolved upstream lifetime issue. Expand remote SQL auth/TLS and acknowledged cancellation tests; keep native and fully instrumented PHP regressions. Review remaining third-party memory/process quotas.
2. Broaden atomic-file ACL/mount/race and crash-durability testing. io_uring file operations remain optional and require complete completion/cancellation/fallback tests; preserve the worker fallback.
3. Extend HTTP streaming/multipart certification and multiple-file uploads, WebSocket endurance/interoperability coverage, IPv6/TLS edge cases, and database remote auth/TLS/cancellation matrices.
4. Extend individual transparent hooks with exact parameter/warning/resource/return/signal behavior tests; keep unsupported groups explicit.
5. Add common DB manual transaction APIs where missing, server-side prepared caches, richer Redis types and safe notification/pipeline policies.
6. Add framework/PSR-3/loop adapters and production telemetry, then certify supported OS/PHP/SAPI combinations. Only after a stable boundary consider ZTS interpreter-thread execution.

Continuation commands: `make audit`, `make audit-fpm`, `./scripts/audit-allocations.sh`, `make verify`, `ASYNCARA_BACKEND=poll ./scripts/test.sh`, `./scripts/phpize-local.sh`, `./benchmarks/run.py`. Consult WORKLOG.md and build logs before changing a status. All source and scripts are in the repository; external dependencies/build products are isolated and ignored.
