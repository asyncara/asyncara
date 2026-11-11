# Release readiness

## Current priority scope (2026-11-12)

The [priority capability contracts](PRIORITY_CAPABILITIES.md) distinguish tested
APIs from broader deployment/protocol certification. New Basic proxy credentials,
sensitive trace protections and pre-runtime lazy pool configuration are tested
in real Native Server handlers. Historical sections below keep their source
identities and failures. Neither these additions nor short passing gates promote
the release: it remains **EXPERIMENTAL**.

## Current completion work (2026-11-10)

Server HTTPS/mTLS, bounded input/output streaming and the optional Hyper client's
verified HTTPS plus bounded methods/headers/bodies are implemented and tested in
their documented scopes. The request continuation passes95 matrix commands,
Gate A252.652s, full PHP85 epoll/poll suites,74 HTTP checks and short four-worker
POST lifecycle controls. Unsuppressed DEBUG Valgrind reports zero errors/loss
with5306bytes reachable. Separate C-only build/HTTP checks pass. The old aggregate
integration failure (a compile watchdog) remains preserved beside the successful
separate compilation and runtime checks. See [request evidence](HYPER_REQUESTS.md)
and [current inventory](COMPLETION_STATUS.md).

These are functional and scoped memory results, not universal performance,
endurance or foreign-allocation certification. Independent package identities
are recorded separately. Historical archives and failure reports are unchanged.
Hyper streaming is covered by the later continuation below. It still lacks
decompression, redirects/proxies, client mTLS and
HTTP/2/3. Release remains EXPERIMENTAL.

The dated checkpoints below retain their original source scope.

## Historical server-r1 checkpoint (2026-11-10)

The isolated `phase7-native-server-20261109T231953Z` branch adds
`Asyncara\App` with Hyper HTTP/1 transport, bounded bodies, routed Fiber handlers,
groups, middleware and optional prefork workers. Native checkpoint `195f088`
passes the focused PHP83/84/85, DEBUG and scoped GCC sanitizer matrix on epoll
and poll, plus Gate A. See [SERVER.md](SERVER.md) for the API, exact namespace
compatibility decision, build commands, ownership boundaries and evidence.
This is **PARTIALLY_IMPLEMENTED / EXPERIMENTAL**, not a production server or a
claim of outperforming Go. At that checkpoint TLS, HTTP/2/3 and server streaming were unavailable.
Historical HTTP-client and Phase4 failures remain separate and preserved.
Final scoped validation and full PHP85 suites pass. The matched server
[benchmark](LATEST_SERVER_BENCHMARK.md) does not meet the all-cases Go target;
large-body median wins do not establish universal superiority.

Server source archive: `build/asyncara-0.1.0-dev-server-r1.tar.gz`,557 files,
packaged commit `b1dc5b21bd5b175ddb33077a267b73f2684ad553`, native `195f088`.
SHA-256: `6839e7cac6ef6e6526389fca00db7640e17c816335850d1826bea25c8cec7dbb`.
Byte-reproducible and Git-tree matched; a fresh extracted Rust/C PHP85 build passes
server protocol, body lifetime and API tests on epoll/poll.
[Independent build attestation](phase7/server-r1-package.json). Not published;
performance acceptance remains NOT_MET and release remains EXPERIMENTAL.

## Previous HTTP-client checkpoint

See [HYPER_DNS.md](HYPER_DNS.md) for runtime922a024 and the isolated curl repair.
DNS tests are scoped; HTTPS/decompression and speed targets remain incomplete.
System libcurl is unchanged, prior FAIL remains, and final broad acceptance is
INCOMPLETE (curl TLS-proxy progress failure). Release remains **EXPERIMENTAL**.

## Previous safety candidate

Previous isolated review: [HYPER_SAFETY_REVIEW.md](HYPER_SAFETY_REVIEW.md).
Native dd63396 fixes Hyper FFI panic containment and repeated content codings;
ordinary PHP83/84/85 HTTP gates, scoped Hyper memory checks, Gate A and short FPM
pass. **System libcurl8.5.0 trailer memory gate remains FAIL**, independently
reproduced without Asyncara. Timing failures remain unresolved. Hyper has no
DNS/TLS/decompression; speed targets remain NOT_MET. The521-file hyper-safety-r1
candidate is reproducible and independently builds, but is not a release promotion
or general memory/endurance certification. Historical package records follow.


Latest native source `84e49f1` repairs unsupported HEAD upload framing. Its
PHP83/84/85, DEBUG/GCC, Gate A and scoped memory checks pass; a dedicated short
HEAD FPM run also passes. Archive `http-r4-head`, source `b04aa98`, independently
builds and passes HTTP suites on83/84/85 plus optional Hyper85 and short HEAD FPM.
See [package attestation](phase6/http-r4-head-package.json). The predecessor
`http-r3-head` remains preserved with its Git-dependent report-writing failure.
These are correctness/build results, not acceptance of the speed goals or B2.

The inherited `09b4767` epoll-cache checkpoint retains **EXPERIMENTAL** classification.
Its wait-cost reduction is not a public HTTP speed acceptance result. The
13,000/s,4x curl_multi and3x Swoole targets remain NOT_MET; see
[HTTP_EPOLL_REUSE.md](HTTP_EPOLL_REUSE.md). Historical failure evidence remains
unchanged. Its B2 result is FAIL (ticks=1 after5,236 recorded responses);
the candidate is not accepted. Later independent builds do not erase that failed
acceptance. Diagnostic results cannot substitute for acceptance.

**Current assessment: EXPERIMENTAL.** The HTTP continuation adds bounded multi-file
MIME, atomic downloads and curl lifecycle repairs; see
[HTTP_ENGINEERING.md](HTTP_ENGINEERING.md). Performance targets are not achieved;
Hyper also has an explicitly opt-in numeric-HTTP GET backend prototype; it is
not a certified replacement for curl. Matching-reference repetitions do not
establish a13,000 requests/sec floor; older higher samples remain historical
evidence. Relative4x/3x targets remain unmet; see [HYPER_EXPERIMENT.md](HYPER_EXPERIMENT.md). Final-source validation/package
identities are recorded separately from historical releases.

The later matching-SDK d57d088 FPM B2 passes (8,092 responses / 600.095 seconds),
with original resource/ticker checks and independent review. The earlier failed
FPM attempt remains preserved. Native-Future and hook-cost experiments showed no
measured benefit and were not adopted. Historical relative benchmarks loaded
foreign patch builds of the reference PHP extensions; those observations are
retained. Fresh matching PHP8.5.12 reference builds now pass load and complete
comparison checks; their measurements still fail the requested relative targets.

The inherited independent Phase5 resource-safety
continuation implements guarded resource identities, opt-in bounded provenance
and measured native watcher setup improvements. Its current evidence and remaining
limits are in [RESOURCE_PROVENANCE_PROGRAM.md](RESOURCE_PROVENANCE_PROGRAM.md).
Historical six-hour FPM remains FAIL; zero-tick attribution and distribution-PHP
retention differences remain unresolved. New short gates are not long-duration
certification. No production release or GitHub publication is authorized here.

## Historical Phase 4 assessment

**Assessment at that checkpoint: EXPERIMENTAL.** Corrected `f2abb55` / phase4-r4 independently
builds/installs/tests on PHP 8.3/8.4/8.5 and has passed frozen one-, six- and
24-hour mixed workloads on Linux PHP 8.5.12 NTS/epoll. The source archive and
native fingerprints remain unchanged. Hosted r4 mixed and FPM each passed one
hour; the FPM run completed 67,824 main requests plus lifecycle/fault checks.

The simultaneous local FPM attempt failed responsiveness after 364.506 sampled
seconds. That failure is retained and its exact waiting site remains unproven.
A separate FPM-only attempt also failed, after 998.680 sampled seconds and
12,568 recorded main responses, with unchanged assertions and no concurrent
local mixed workload. Its failed request had zero ticks and 267.219ms timer lag.
Standalone epoll/poll/nanosleep controls reproduce large timing delays without
PHP, but do not establish this request's exact waiting site. A reproduced observer
zombie-exit error is repaired and tested; that does not repair or dismiss the FPM
failure. Six-/24-hour FPM has not run. Mixed
resource stability does not remove observed timer lag above one second or certify
other PHP/platform/remote configurations. No release promotion follows.

The later `67e4530` checkpoint passed all four hosted verification jobs and another
separate mixed/FPM hour (27,694 cycles / 67,740 main requests). Native/public
runtime remains identical to r4. Direct-probe diagnostics passed 180s and 900s,
plus a full 60s lifecycle smoke. A matched 5ms wait took 659.389ms; instrumentation
narrows its measured interval but does not identify the original failed request's
exact cause. These results improve evidence coverage without closing the remaining
FPM endurance or remote-compatibility gates.

[PHASE4_DELIVERY](PHASE4_DELIVERY.md) contains complete source/archive identities,
resource trends, hosted artifact provenance, failed controls and continuation.

## Historical Phase 3 checkpoint

**EXPERIMENTAL. Phase 3 is partially completed.** This checkpoint substantially
improves verified reliability; it is not a release candidate or completion of the
universal-runtime directive. Final native/test source is `e5d94fd`; archive source
is `f3d63ac`. The [delivery report](PHASE3_DELIVERY.md) records the r5 archive,
SHA-256, compatibility matrix and exact remaining work. Its
[package attestation](phase3/package-final.json) follows the archive and records
independent clean installations; an archive cannot contain its own final hash.

## Implemented and repaired

- Preserved the corrected audit1 archive and reproduced all seven historical
  high-severity failures against the original archive. All corrected regressions
  pass. The starting source commit was `4d90dbe`.
- Added bounded pending Futures, retained task failures, pool borrowers, IPC
  request bytes and JSON shapes, Redis aggregate pipelines, PostgreSQL
  notifications/results and SQLite value/SQL limits. Policies and untracked
  foreign allocations are in RESOURCE_LIMITS.md.
- Hardened RESP integer/double/line/verbatim validation and shared IPC framing.
  Added committed corpora, mutation tests and native allocation fuzzing.
- Fixed native workers inheriting interpreter asynchronous signal handlers.
- Fixed Connector/C local-address certificate identity bypass before credentials
  are sent; unsupported strict-TLS configurations fail closed.
- Fixed Oracle MySQL START TRANSACTION compatibility through nonblocking control
  commands, preserving native parameter binding for data queries.
- Fixed lost readiness after PHP/TLS buffers data before the kernel wait. A
  deterministic negative control fails against the old binary; repeated real
  Redis TLS connections pass after repair.
- Corrected result conversion for PHP’s deferred soft-OOM API, preserving
  ordinary request teardown instead of continuing to the hard process limit.
- Released large native buffers through dedicated mappings without changing
  global allocator settings; retained page-rounded accounting and ASan bounds.
- Added numeric filesystem errors and same-directory atomic replacement with
  optional file/directory synchronization, secure temporary creation, explicit
  post-publication failure reporting and cancellation cleanup.

## Executed verification

PHP 8.3.36 / 8.4.27 / 8.5.12 NTS (with matching SDKs): native builds, 44 core cases and dedicated
resource, file, parser, IPC and audit regressions pass. Both epoll and poll pass.
Fresh phpize/configure builds load and pass the runner. PHP 8.5.12 fully
GCC ASan/UBSan-instrumented debug core/database/WebSocket/network tests, native ASan/UBSan/TSan, allocation injection,
GCC static analysis and Valgrind workloads pass in their documented scopes.
No compiler warning was ignored; phpize's upstream obsolete libtool macro notice
remains visible. Hosted CI exposed PHP deferred-OOM handling, missing stack annotations,
fuzzer instrumentation capacity and a missing test utility. Those findings were
reproduced and corrected. All four final native-source jobs passed:
[PHP 8.3/8.4/8.5 regression and database lifecycle](https://github.com/asyncara/asyncara/actions/runs/41549782614).
The r5 archive independently passed clean own-SDK build/install, core/poll,
network/WebSocket and audit suites on all three versions, plus installed 8.5.12
database, bounds, FPM and private-CA Oracle tests.

Final core/network/file/pending-shutdown/database Valgrind workloads have zero
errors and zero definite/indirect/possible losses. This excludes the separately
reported TLS library-unload finding below. Atomic fault tests cover allocation,
file fsync, rename EXDEV, directory fsync, partial writes/EINTR and ENOSPC.
Deliberate PHP memory exhaustion still ends that request; native cleanup tests
are not a claim of recovering an exhausted PHP execution context.

Fuzzing: initial 326,134 native coverage-guided inputs / 61s; final rerun 294,043 /
61s; no findings. Instrumented PHP mutation runs include 12,737 / 60.007s and
11,570 / 60.061s. RESP seed/prefix regression count is 100. These are bounded
campaigns, not exhaustive parser coverage. External HTTP/TLS/SQL parsers were
not claimed as Asyncara fuzz targets. See FUZZING.md.

Soak: frozen baseline `cad761e` passed 3,600.149 workload seconds, 20,855 cycles /
20.855m burst Tasks, 208 worker crash/recovery injections; RSS endpoint medians
+296 KiB, flat descriptors/threads, zero final pending runtime objects. Earlier
runtime `8af2604` passed a separate 300.106-second run, 1,894 cycles / 1.894m Tasks,
18 injected restarts; RSS endpoint medians +444 KiB. The earlier hour does not
certify the later binary. A separate HTTP-streaming checkpoint1f792ef passed3,600.110s/19,761cycles,
19.761m burst Tasks and197worker recoveries, RSS endpoint+380KiB and zero final
pending work. WebSocket checkpoint648c076 passed300.026s/24,686cycles with16
connections. Each report retains its exact source scope; later Connector gate
changes are independently tested. Final native source `e5d94fd` passed a frozen
300.073-second mixed run with 1,331 cycles, 13 worker recoveries, RSS endpoint
medians +200 KiB, flat descriptors/threads and zero final pending runtime objects.
Final-source one-/six-/24-hour tests are **not executed**. Short-window slopes
and latency outliers remain in the evidence; no leak-free/low-latency production
guarantee is inferred.

FPM: A=100 sequential / one worker; B=10 requests with four overlapping operations
each; C=12 concurrent requests / four workers. Expanded 300.157s workload handled
4,380 main requests plus recycling, active reload, killed-worker replacement,
fatal memory requests, timeout and pending-I/O teardown. Idle workers returned
to six descriptors / one thread. FPM process_control_timeout must allow active
requests to drain; the fixture uses five seconds. Final A/B/C rerun passes.
Expanded streaming soaks repeatedly FAILED the unchanged RSS-growth criterion.
Dedicated mappings for large Asyncara buffers repaired the reproduced retention:
three subsequent five-minute runs passed with under 3 MiB growth beyond warmup.
Final native source `e5d94fd` handled 3,756 main requests in 300.106 seconds,
maximum 2,888 KiB beyond warmup, including the later file-result ownership
improvement that avoids a redundant native copy. Historical failures remain
preserved. Exact frozen source scopes and final reruns are in the attestation and
FPM_LIFECYCLE.md; no universal process-memory bound is established.

## Database, networking and filesystem matrix

| Area | Verified scope | Remaining |
|---|---|---|
| SQLite | Native workers, ordering/binds/transactions/limits/cancellation | Foreign allocator totals and deployment contention matrix |
| PostgreSQL 16.15 | Nonblocking libpq; private-CA verify-full/SCRAM; pooling/deadlines/cleanup | Prepared cache, acknowledged cancellation, WAN/mTLS/rotation |
| MariaDB 11.8.9 | Connector/C3.4.10 + OpenSSL3; strict IP SAN, credentials, bound writes/rollback | Other connectors/servers, TLS DNS names, acknowledged cancellation |
| Oracle MySQL 8.4.13 | Separate server; caching_sha2_password and legacy sha256_password over verified TLS | Additional Oracle versions and auth variants |
| Redis 7.0.15 | RESP2/3, ACL/TLS, pipeline/transaction/PubSub, 200 reconnect/cancel cycles | Attributes/streaming aggregates/arbitrary pushes/cluster/sentinel |
| Filesystem | pthread workers, stable streams/handles, errno, atomic visibility and sync calls | io_uring operations NOT_IMPLEMENTED; power-loss/ACL/mount certification |
| HTTP | Concurrent curl multi, bounded buffered and HTTP/1.1 streamed responses, file/iterable uploads/downloads, one-file MIME | Mixed-stream soak recorded separately; multi-file MIME and broader curl/proxy certification |
| WebSocket | ws/wss client, raw TCP server, bounded framing/messages, controls, cancellation, Python17.4 interop | Extensions, server WSS, browser/Autobahn and endurance certification |
| Hooks | sleep/usleep PARTIALLY_SUPPORTED, tested ordinary timer path | All stream/curl/filesystem/database hook groups UNSUPPORTED |
| ZTS | PHP8.5.11 ZTS DEBUG single-interpreter CLI core/poll/database tests | Concurrent interpreters unverified; userland ThreadPool BLOCKED |
| Platforms/frameworks | Tested Linux x86_64; existing Composer/PSR-17/18 preserved | WAN/TLS state edge matrix, other OSes, Laravel/Symfony/WordPress/loop adapters |

TLS tests use local isolated TCP servers and private CA material. Wrong CA,
identity and password rejection are tested; no verification-disable workaround
was used. Trust-store loading/cryptographic setup still perform synchronous
library work. Real remote deployments are not certified merely by loopback TLS.

## Security and unresolved findings

The native ledger does not include all third-party memory, worker heaps or kernel
buffers. A **109-byte libpq process-global TLS BIO_METHOD leak at library unload**
is independently reproduced without PHP/Asyncara and remains unresolved. No
suppression or private pointer manipulation was added. Keeping modules loaded
for a diagnostic sanitizer run preserves that global root, not a repair.
Unmodified PHP8.5.12 under Clang’s function-type UBSan reports upstream callback
type mismatches even without Asyncara; that full-PHP configuration is not marked
passed. Stack-aware GCC/debug Valgrind is a separate configuration with clean
executed workloads. No Asyncara critical invalid-memory access is known in the
executed configurations;
this is not a universal memory-safety guarantee. SQL cancellation discards the
connection and does not acknowledge server-side termination or undo side effects.

## Performance

All three samples remain in PERFORMANCE_PHASE3.md and the raw JSON. For 24 HTTP
requests, median wall seconds: sequential PHP cURL .73731, Asyncara/four active
.18435, native curl multi .16016, FPM/four workers .17557, Swoole .16766. This
loaded shared-host sample does not establish a stable ranking. Atomic-file
median latencies span 0.728–1.141 ms; durable replacement 7.323–8.999 ms. Durability
has extra semantics and syscall costs. HTTP streaming and WebSocket results are separately recorded in
PERFORMANCE_PHASE3.md. WebSocket is slower than Python websockets17.4 in the
measured1/16/128-connection echo workloads; no io_uring performance claim exists.

## Reproduction and exact continuation

Build/install instructions are in INSTALLATION.md. Preserve historical archives;
package.py requires a new output name. A clean extraction must build from its
own ext sources/config.m4, using independently available PHP/dependency headers.
The final attestation records archive-source comparisons and test commands.

1. Extend the repaired FPM memory/lifecycle coverage to frozen final-binary
   one-/six-/24-hour workloads, retaining the same assertions. The four hosted
   regression/database jobs have passed; scheduled endurance remains separate.
2. Follow up the upstream libpq unload allocation; expand server disconnect,
   remote TLS/auth, prepared cache and cancellation acknowledgement coverage.
3. Extend the tested curl multi streaming paths with multi-file MIME and broader
   long-duration/proxy/protocol certification before promoting their support.
4. Extend WebSocket soak, state-machine fuzzing and independent/browser/Autobahn
   interop; preserve its explicit limits and tested scope in WEBSOCKET.md.
5. Broaden filesystem durability/ACL/mount races and IPv6/TLS edge cases. Keep
   io_uring absent until real operation/completion/fallback tests exist.
6. Add individual verified hooks, transaction APIs and framework/loop adapters;
   preserve unsupported reports. Interpreter-thread execution remains a separate
   architecture decision, not a prerequisite for the next experimental release.

## Hyper bounded streaming continuation (2026-11-10)

IMPLEMENTED_AND_TESTED for HTTP/1.1 response streams, fixed/chunked iterable
uploads, worker-backed file upload/download, verified HTTPS, backpressure and
owner cleanup. Native checkpoint `a0e3098`; no fallback to curl. The same public
HttpClient, client permits and C buffer limits are retained. See
[HTTP_STREAMING.md](HTTP_STREAMING.md#hyper-client-streaming-continuation-2026-11-10).
The35-command PHP83/84/85/DEBUG/GCC epoll/poll matrix and10 additional aggregate
memory-bound checks pass. Gate A203.435s and the76-command HTTP gate pass;
complete PHP85 suites and short four-worker FPM controls pass on both backends.
Scoped DEBUG Valgrind ownership controls report zero errors/loss,5306bytes
reachable. These results do not close broad protocol/proxy certification,
historical memory findings, client mTLS, decompression, redirects or HTTP/2/3.
Release remains EXPERIMENTAL; older rows retain their original source scope.


## Bounded Hyper decoding (2026-11-10)

Bounded Hyper gzip/deflate, credential-trace redaction and worker-fed Hyper streaming have source-backed tests. These additions do not close broad HTTP2/3/proxy certification, client mTLS, all database deployments, crash durability or historical memory/endurance findings. See [HYPER_COMPRESSION.md](HYPER_COMPRESSION.md). Release remains EXPERIMENTAL.

## PostgreSQL client TLS verification (2026-11-11)

Explicit PostgreSQL client-certificate configuration is tested on the scoped
16.15 pair, including FPM and PHP83/84/85 epoll/poll. Ordinary libpq unload remains
FAIL109bytes. [Evidence](POSTGRESQL_MTLS.md). This closes one API gap, not broad
remote/memory certification or historical FPM zero-tick attribution. No release
promotion or publication; EXPERIMENTAL remains.

Explicit SQL TLS filename admission and handshake-resume ownership is now verified in [SQL_TLS_PATHS.md](SQL_TLS_PATHS.md). This scoped correction does not broaden the server/version matrix or erase the PostgreSQL ordinary-unload memory failure. Native Server is the primary development mode; existing FPM compatibility remains.
