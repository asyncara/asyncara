# Phase 3 delivery — 2026-11-03

**EXPERIMENTAL. Phase 3 is partially completed.** This is a tested hardening and
protocol-expansion checkpoint. It is not production certification.

## Source and distribution

- Archive source commit: `f3d63ac9abf528af0cd5b24086976912c28e4a75`.
- Final native/test change: `e5d94fd8dd959b88474332a05aaa1c9eb61f318f`.
- Branch: `phase3-hardening`. Subsequent delivery commits add evidence/docs only.
- Archive: `build/asyncara-0.1.0-dev-phase3-r5.tar.gz`, 304 regular source files.
- SHA-256: `b0f544b3da61789a9cc649a8bc24869308787c9c63ca37358afd69e39ef1ec38`.

All archive bytes match the source commit. Packaging a clean checkout created
with private umask produces identical bytes. Independent extractions used their
own PHP SDK, phpize/configure and isolated installation root. No generated file
from the working repository was used. External development/runtime dependencies
remain necessary; downloaded development packages are hashed in
[the package attestation](phase3/package-final.json).
Historical archives, including failed validation attempts, remain preserved.
This delivery document and the final attestation follow the archive and are not
inside it; an archive cannot contain its own final checksum.

## Implemented features and fixed defects

The existing Fiber scheduler, synchronization, task groups, context, native
workers, epoll/poll, database drivers and ProcessPool remain intact. Phase 3 adds
bounded admission/result/message policies, allocation and protocol fuzzing,
frozen soak/FPM harnesses, strict SQL TLS verification, typed filesystem errors
and atomic replacement, HTTP streaming/uploads/downloads, and WebSocket support
within the scopes below. Composer and PSR-17/18 integration remain tested.

Repairs include all seven preserved independent-audit regressions, worker signal
inheritance, Connector/C IP certificate identity verification, Oracle transaction
control, buffered TLS readiness, old-Connector capability detection and explicit
plaintext selection, deferred PHP OOM conversion, and native buffer retention
across FPM requests. Large native buffers now use accounted mappings; file results
adopt their existing native buffer. No global allocator setting was changed.

Negative controls reproduce deferred-OOM exit137, duplicate-buffer budget failure,
lost readiness, the seven original audit failures, and mapped-tail overruns.
Ordinary fatal requests can tear down cleanly in the tested cases. Genuine hard
memory-limit exits and OS kills cannot run PHP/native shutdown callbacks.

## Executed verification

| Check | Result and scope |
|---|---|
| PHP 8.3.36 / 8.4.27 / 8.5.12 NTS archive installations | PASS: own SDK/phpize/configure/install, load, Composer, full runner, poll, WebSocket and network-edge tests |
| Independent installed-package audit | PASS on all three versions, including 1,000/10,000 Tasks, slow I/O, timers, historical regressions and deferred OOM |
| Installed 8.5.12 database/FPM/TLS additions | PASS: ordinary drivers, resource bounds, database audit, FPM A/B/C/D, separate private-CA Oracle fixture with 50 Redis TLS repeats |
| Final working-source database TLS | PASS: separate MariaDB and Oracle fixtures, each with 100 Redis TLS repeats |
| GCC ASan/UBSan PHP 8.5.12 DEBUG + extension | PASS: full core/file/HTTP streaming/database/WebSocket/IPv6/TLS-edge suites |
| Annotated PHP/Connector Valgrind builds | PASS: core, network, files, pending shutdown, databases and fatal SQL teardown; no suppressions, zero errors and definite/indirect/possible losses |
| Native instrumentation | PASS: ASan/UBSan/TSan, allocation/mapping failure injection, mapped-tail negative control and GCC static analysis |
| Hosted CI | [All four jobs passed](https://github.com/asyncara/asyncara/actions/runs/41549782614) at final native source e5d94fd: three PHP versions plus database lifecycle |

Stock PHP/Connector coroutine stacks produced nondeterministic Valgrind reports.
CI now uses the upstream stack-annotation facilities in both dependencies, while
retaining stock-library functional tests. No stack-threshold workaround was used.
The separate Clang full-PHP build reports upstream callback-type UBSan findings
without Asyncara loaded and is **not** marked passed. See [SECURITY](SECURITY.md).

## Fuzzing and soak evidence

Recorded bounded campaigns include 326,134 and 294,043 initial native inputs;
the later 64 MiB ASan-quarantine run executed 306,396 inputs in 61 seconds,
peaking at 198 MiB RSS. HTTP buffer fuzzing executed 803,874 inputs in 61 seconds,
peaking at 147 MiB. WebSocket mutation runs include 47,262 inputs in 60 seconds.
No application crash was found in those campaigns. Hosted instrumentation
exhausted its original memory budget; the failure and explicit quarantine/window
tradeoff are retained in [FUZZING](FUZZING.md). External library parsers are not
claimed as Asyncara-owned fuzz targets.

Two earlier source checkpoints completed separate one-hour mixed runs. They do
not certify the final binary. Final clean native source e5d94fd completed:

- Mixed workload: 300.073 seconds, 1,331 cycles, 1.331 million burst Tasks,
  13 ProcessPool crash recoveries, flat descriptors/threads and RSS endpoint
  median +200 KiB. No pending Tasks/Futures/timers/native jobs remained.
- FPM: A=100 sequential requests; B=10 requests with four overlapping operations;
  C=12 concurrent requests across four workers; D=12 streaming requests. The
  separate 300.106-second mixed run handled 3,756 main requests plus recycling,
  graceful reload, forced worker replacement, fatal requests and timeout recovery.
  Maximum RSS growth beyond warmup was 2,888 KiB, below the unchanged 8,192 KiB
  criterion. Idle workers returned to six descriptors and one thread.

Earlier FPM RSS failures remain historical evidence. Dedicated mappings passed
three successive five-minute runs; this establishes the reproduced repair, not
an indefinite memory bound. Final-source one-hour, six-hour and 24-hour runs are
**not executed**. Loaded-host timer-lag outliers remain in the raw reports.
See [final native evidence](phase3/final-native-validation.json),
[SOAK_TESTING](SOAK_TESTING.md) and [FPM_LIFECYCLE](FPM_LIFECYCLE.md).

## Database, TLS and backend compatibility

| Component | Verified capability | Remaining limitation |
|---|---|---|
| SQLite | Connection-affine native workers; bindings, transactions, contention, cancellation and cleanup | Buffered results and foreign allocation boundaries |
| PostgreSQL 16.15 | Nonblocking libpq; SCRAM, private-CA verify-full, transactions, notifications, cancellation/cleanup | Prepared cache, acknowledged server cancellation, WAN/mTLS/rotation |
| MariaDB 11.8.9 / Connector 3.4.10 | Nonblocking state machine; verified IP SAN TLS, credentials, prepared binds and transactions | More server/authentication variants and remote certification |
| Oracle MySQL 8.4.13 | Separate server tests; caching_sha2_password and sha256_password over verified TLS | Not a blanket MySQL-version certification |
| Connector 3.3.17 | Plain Unix socket operations tested; TLS requests fail closed without required public callback API | TLS unsupported on this tested older package |
| Redis 7.0.15 | RESP2/3, ACL/TLS, pipeline, Pub/Sub, transactions and reconnect/cancellation tests | Attributes, streaming aggregates, arbitrary pushes, cluster/sentinel |
| Filesystem | pthread workers, stable handles, typed errno, same-directory atomic replacement and explicit sync calls | io_uring filesystem **NOT_IMPLEMENTED**; power-loss/mount certification incomplete |
| Network | Linux epoll/poll, IPv4/IPv6 TCP/UDP, worker DNS, TLS1.2/1.3, SNI/ALPN and partial I/O | Other operating systems and broader TLS/WAN cases unverified |

Private CA tests reject wrong trust, identity and credentials. Verification was
not disabled to obtain a passing TLS result. Explicit non-TLS connections reject
CA options and are separately asserted to have an empty cipher. Trust-store and
cryptographic setup still involve synchronous library work. Cancellation cannot
undo committed external effects or promise server acknowledgement.

## HTTP, WebSocket, hooks and ZTS

HTTP streaming is **PARTIALLY_IMPLEMENTED** at the overall protocol-certification
level: tested HTTP/1.1 bounded responses, backpressure, cancellation, downloads,
file/iterable uploads and one-file multipart use the existing curl multi pool.
Native response/upload buffers are 64 KiB each; the PHP response queue holds two
chunks. Streamed responses use identity encoding. Downloads can leave partial
files and are not implicitly atomic. Multi-file MIME and broader proxy/protocol
certification remain incomplete. See [HTTP_STREAMING](HTTP_STREAMING.md).

WebSocket is **PARTIALLY_IMPLEMENTED**: ws/wss client, raw TCP server handshake,
bounded RFC6455 framing, masking, fragmentation, UTF-8, controls, close,
cancellation and slow-consumer behavior pass independent Python websockets17.4
interop. Server WSS, extensions, browser/Autobahn and endurance certification are
not advertised. See [WEBSOCKET](WEBSOCKET.md).

| Transparent function group | Status |
|---|---|
| sleep / usleep, opt-in inside Async::run | PARTIALLY_SUPPORTED; normal timers tested, signal semantics differ |
| PHP streams and sockets | UNSUPPORTED |
| Filesystem functions | UNSUPPORTED |
| cURL functions | UNSUPPORTED |
| PDO/MySQLi/ext-pgsql/ext-redis | UNSUPPORTED |
| SOAP and other extension operations | UNSUPPORTED |

The explicit alternatives have their documented async backends. HOOK_ALL means
implemented available hooks only. The complete function matrix remains in
[HOOK_COMPATIBILITY](HOOK_COMPATIBILITY.md).

The historical PHP8.5.11 ZTS DEBUG investigation passed single-interpreter CLI
tests. Concurrent interpreters and patched8.5.12 ZTS are not certified. Userland
ThreadPool remains **BLOCKED**; native workers and ProcessPool are independent.

## Security, performance and next steps

The native ledger excludes third-party allocators, kernel buffers and child
processes. A separately reproduced 109-byte libpq TLS allocation at library
unload remains unresolved upstream. The Clang callback-type findings above also
remain documented. No critical Asyncara invalid-memory defect is known in the
executed configurations; this is not a universal guarantee.

Raw comparisons retain all samples. Historical 24-request median wall times
were 0.73731s sequential PHP cURL, 0.18435s Asyncara/four active, 0.16016s native
curl multi, 0.17557s FPM/four workers and 0.16766s Swoole on a loaded shared host.
WebSocket was slower than Python in measured echo workloads. Dedicated mappings
reduced large-read peak RSS but introduced page/copy cost; transferring the
already-owned file buffer reduced a separate three-sample median from 1.699s to
1.396s, while p95 worsened under load. These are workload observations, not
universal rankings. See [PERFORMANCE_PHASE3](PERFORMANCE_PHASE3.md).

The next work is to extend frozen final-source one-/six-/24-hour and FPM soaks,
follow up the upstream memory/tool findings, expand remote database auth/TLS and
cancellation acknowledgement, complete prepared caches and transaction APIs,
broaden HTTP/WebSocket interoperability, then add individual hooks and real
framework/loop adapters. Other OSes, interpreter threads and universal hooks
remain outside this checkpoint's supported scope.

Reproduction starts with matching PHP/SDKs and [INSTALLATION](INSTALLATION.md):

```sh
make verify
make audit
make audit-fpm
ASYNCARA_BACKEND=poll ./scripts/test.sh
./scripts/audit-allocations.sh
./scripts/test-native-mappings.sh
python3 tests/soak/run.py --seconds 3600 --tasks 1000 --databases --output /tmp/new-mixed-soak
python3 tests/soak/fpm.py --seconds 3600 --output /tmp/new-fpm-soak
python3 tests/audit/archive_install.py --archive build/asyncara-0.1.0-dev-phase3-r5.tar.gz \
  --output /tmp/new-archive-audit --dependencies /path/to/development-debs \
  --variants /path/to/php-variants.json
```

Set `PHP_BIN`, `PHP_INCLUDE_DIR` and `FPM_BIN` to matching isolated versions when
the system interpreter differs. Use new evidence paths; never overwrite a
historical artifact or report. Release classification remains **EXPERIMENTAL**.
