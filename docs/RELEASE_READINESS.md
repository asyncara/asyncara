# Phase 3 release readiness

**EXPERIMENTAL. Phase 3 is partially completed.** This checkpoint substantially
improves verified reliability; it is not a release candidate or completion of the
universal-runtime directive. The earlier hardening runtime checkpoint was `8af2604`. A subsequent HTTP
streaming checkpoint adds bounded response/upload buffers and curl MIME; see
HTTP_STREAMING.md for its separate verification scope. The final delivery commit and archive
SHA-256 are recorded by the separate package verification attestation; an archive
cannot contain its own hash.

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
- Added numeric filesystem errors and same-directory atomic replacement with
  optional file/directory synchronization, secure temporary creation, explicit
  post-publication failure reporting and cancellation cleanup.

## Executed verification

PHP 8.3.36 / 8.4.27 / 8.5.11 NTS: native builds, 44 core cases and dedicated
resource, file, parser, IPC and audit regressions pass. Both epoll and poll pass.
Fresh phpize/configure builds load and pass the runner. PHP 8.5.11 fully
ASan/UBSan-instrumented debug tests, native ASan/UBSan/TSan, allocation injection,
GCC static analysis and Valgrind workloads pass in their documented scopes.
No compiler warning was ignored; phpize's upstream obsolete libtool macro notice
remains visible. Hosted CI is configured but **not executed remotely**.

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
+296 KiB, flat descriptors/threads, zero final pending runtime objects. Final
runtime `8af2604` passed a separate 300.106-second run, 1,894 cycles / 1.894m Tasks,
18 injected restarts; RSS endpoint medians +444 KiB. The earlier hour does not
certify the later binary. Six-/24-hour tests are **not executed**. Short-window
slopes and latency outliers remain in the evidence; no leak-free/low-latency
production guarantee is inferred.

FPM: A=100 sequential / one worker; B=10 requests with four overlapping operations
each; C=12 concurrent requests / four workers. Expanded 300.157s workload handled
4,380 main requests plus recycling, active reload, killed-worker replacement,
fatal memory requests, timeout and pending-I/O teardown. Idle workers returned
to six descriptors / one thread. FPM process_control_timeout must allow active
requests to drain; the fixture uses five seconds. Final A/B/C rerun passes.
Long-duration FPM/framework and all process-tree failure modes remain unverified.

## Database, networking and filesystem matrix

| Area | Verified scope | Remaining |
|---|---|---|
| SQLite | Native workers, ordering/binds/transactions/limits/cancellation | Foreign allocator totals and deployment contention matrix |
| PostgreSQL 16.15 | Nonblocking libpq; private-CA verify-full/SCRAM; pooling/deadlines/cleanup | Prepared cache, acknowledged cancellation, WAN/mTLS/rotation |
| MariaDB 11.8.9 | Connector/C3.4.10 + OpenSSL3; strict IP SAN, credentials, bound writes/rollback | Other connectors/servers, TLS DNS names, acknowledged cancellation |
| Oracle MySQL 8.4.13 | Separate server; caching_sha2_password and legacy sha256_password over verified TLS | Additional Oracle versions and auth variants |
| Redis 7.0.15 | RESP2/3, ACL/TLS, pipeline/transaction/PubSub, 200 reconnect/cancel cycles | Attributes/streaming aggregates/arbitrary pushes/cluster/sentinel |
| Filesystem | pthread workers, stable streams/handles, errno, atomic visibility and sync calls | io_uring operations NOT_IMPLEMENTED; power-loss/ACL/mount certification |
| HTTP | Concurrent curl multi, bounded buffered and HTTP/1.1 streamed responses, file/iterable uploads/downloads, one-file MIME | Long streaming soak, multi-file MIME and broader curl/proxy certification |
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
No critical invalid-memory access is known in the executed configurations;
this is not a universal memory-safety guarantee. SQL cancellation discards the
connection and does not acknowledge server-side termination or undo side effects.

## Performance

All three samples remain in PERFORMANCE_PHASE3.md and the raw JSON. For 24 HTTP
requests, median wall seconds: sequential PHP cURL .73731, Asyncara/four active
.18435, native curl multi .16016, FPM/four workers .17557, Swoole .16766. This
loaded shared-host sample does not establish a stable ranking. Atomic-file
median latencies span 0.728–1.141 ms; durable replacement 7.323–8.999 ms. Durability
has extra semantics and syscall costs. No WebSocket/io_uring performance claim exists; HTTP streaming measurements
are recorded separately when executed.

## Reproduction and exact continuation

Build/install instructions are in INSTALLATION.md. Preserve historical archives;
package.py requires a new output name. A clean extraction must build from its
own ext sources/config.m4, using independently available PHP/dependency headers.
The final attestation records archive-source comparisons and test commands.

1. Execute the committed hosted CI; repeat frozen final-binary 1h/6h/24h runs and
   longer FPM fault/recycling workloads using tests/soak. Retain provenance/trends.
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
