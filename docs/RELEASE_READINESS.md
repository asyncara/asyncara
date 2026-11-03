# Release readiness

**Current assessment: EXPERIMENTAL.** Phase 4 expanded independent tests,
resource-limit coverage, dependency controls and diagnostics, then repaired a
reproducible UDP endpoint ownership defect and descriptor-exhaustion cleanup.
Corrected source `76e6557` is packaged as phase4-r3, independently installed/tested
on PHP 8.3/8.4/8.5 and passing all four hosted CI jobs. Earlier archives retain
the UDP defect. Earlier one-hour attempts failed or were interrupted; new frozen
one-hour campaigns are running. Six-/24-hour stages have not started.
Shorter diagnostic passes do not supersede those failures. Current evidence and
the packaged/tested source distinction are in [PHASE4_DELIVERY](PHASE4_DELIVERY.md).

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
