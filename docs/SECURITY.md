# Security and memory-safety assumptions

Phase 4 found a UDP endpoint ownership defect: PHP stream-server bind implicitly
enabled `SO_REUSEADDR`, allowing another live UDP socket to share the endpoint
and receive its datagrams. The fix uses an exclusive native bind with the
existing PHP stream lifecycle. This is a local socket isolation/reliability
repair; it does not authenticate datagrams or make UDP reliable. Historical r2
and earlier packages contain the defect. Consult PHASE4_DELIVERY.md for the
corrected source/package identity and validation status. Foreign/kernel buffers
remain outside Asyncara's native memory ledger.

Asyncara is experimental infrastructure. See [the independent audit](INDEPENDENT_AUDIT.md) for the repaired high-severity defects, exact tested configurations and limits. Do not expose its low-level native functions, worker registry, filesystem paths or connector configuration directly to untrusted callers.

## Boundaries

- Native worker threads never call Zend, execute PHP or manipulate zvals. Inputs are copied before queueing. Results are marshalled on the request thread after synchronized completion. Shutdown joins threads before releasing their memory.
- Process workers are trusted PHP programs, not sandboxes. An explicit local registry selects callables; request data contains only bounded JSON, never serialized PHP objects or closure code. proc_open receives an argument array. Job failure/crash is not automatically replayed. Cancellation can kill a worker but does not roll back external side effects.
- HTTP permits only HTTP(S), verifies certificates/hostnames, defaults to no redirects, and rejects CR/LF header injection. It does not implement SSRF policy: restrict destinations and proxies in the application. Buffered compression is bounded by the decoded-body cap; streaming disables automatic content decoding and uses HTTP/1.1 to limit foreign pause buffers. Streaming downloads can leave a partial destination; they are not atomic replacements.
- SQL uses native parameter binding. SQLite has connection ordering; libpq uses parameterized extended protocol; Connector/C uses prepared statements. SQL text itself remains trusted application input. Connection credentials can appear in PHP exception traces if the application's PHP configuration records arguments; set an appropriate logging policy.
- Redis is length-framed and parser limits reject malformed or excessive replies. It has no cluster/sentinel trust model or arbitrary push demultiplexer. Cancelled/misaligned network database connections are discarded.
- Files obey submission-time open_basedir checks, but symlink races are not a sandbox boundary. Regular-file operations reject special files. Streaming keeps one native descriptor with serialized operations. Partial side effects after cancellation are documented. Ordinary path writes are not atomic replacement or durable transactions; Files::replaceAtomic and File::sync provide explicit separate contracts documented in FILESYSTEM.md.
- Explicit Context is shallow and task-local. Shared object instances, superglobals, global variables, static fields, extension state and persistent resources remain shared.

## Executed validation

Native compilation uses `-Wall -Wextra -Werror`. The standalone native worker harness passed AddressSanitizer and UndefinedBehaviorSanitizer with leak detection and no suppression. It exercises file jobs, binary SQLite results, cancellation and request shutdown with queued work. This harness does not instrument Zend, curl/OpenSSL internals, libpq or Connector/C binaries.

Valgrind ran the PHP/native core stress workload and the PostgreSQL/MariaDB/Redis integration suite with `USE_ZEND_ALLOC=0`, matching locally extracted glibc symbols and no error suppressions. The completed runs report zero memory errors and zero definite/indirect/possible lost bytes after PHP cycle collection. About 77.5 KiB remained reachable at process exit. Stock PHP Fiber stacks trigger Valgrind stack-switch warnings; no max-stackframe setting or suppression was used to hide memory errors.

Valgrind found and prompted fixes for Connector/C statement handles on interrupted/error paths and library-global teardown. Its initial small PHP-object losses disappeared after explicit cycle collection; long-lived callers should understand normal PHP cycles and may collect at request/job boundaries. The 100-cycle stress script performs that collection and checks memory/descriptor stability. This is not proof that all possible shutdown orders or extension combinations are safe.

## Remaining verification work

The independent audit built exact PHP 8.5.11 debug and fully ASan/UBSan-instrumented PHP/extension configurations and executed core/database workloads. Selected malloc/calloc/realloc failure injection and fatal file/PostgreSQL/SQLite/MariaDB conversion tests passed after repairing native PostgreSQL ownership. Exhaustive allocation-failure injection, protocol fuzzing, long-duration load, ZTS, SQL TLS/authentication variants and adversarial filesystem races remain incomplete. The standalone native harness also passes ThreadSanitizer; this does not instrument third-party library internals. GCC TSan on this host requires non-PIE and process-local ASLR disabling through setarch; no global setting is changed. Independent isolated FPM tests cover 100 sequential requests, concurrent operations inside requests and concurrent requests across four workers, with state, teardown, thread, descriptor and RSS checks. A deliberate fatal request is followed by a healthy request on the same PID; the failed request itself is not recovered. No critical invalid-memory access is known in the tested configuration; production certification is explicitly withheld. An aggregate budget bounds retained Asyncara-owned native allocations and tests cover rejection/recovery plus Zend memory-limit bailout cleanup. Third-party library allocations and bounded readiness control buffers are excluded; see BACKENDS.md. Time bounds on all shutdown paths remain incomplete.

New native code must include ownership/error-path review and executed regressions. Report a suspected vulnerability privately to the project owner; this repository has no established public security-contact address yet.

## Phase 3 findings

Native worker signal inheritance, MariaDB local-address certificate identity bypass,
Oracle transaction-control compatibility and PHP/TLS buffered-readiness loss were
reproduced and repaired with regression tests. See FPM_LIFECYCLE.md and
DATABASE_COMPATIBILITY.md. MariaDB strict TLS now requires the tested callback /
OpenSSL configuration and fails closed otherwise. DNS TLS hostnames for that driver
remain unsupported. A third-party 109-byte libpq TLS BIO_METHOD allocation remains
lost on library unloading; the standalone libpq-only reproducer and complete scope
are documented. Do not generalize earlier zero-loss non-TLS tests to this path.
No invalid-memory access was found in the new instrumented tests; this is bounded
evidence. Fuzzing, ZTS CLI and short multiworker fault tests now have executed
results; multi-interpreter ZTS and six-/24-hour operation remain unverified.

WebSocket handshakes enforce role masking, bounded lengths, reserved bits,
UTF-8 and strict WSS certificate verification. Server Origin is explicitly
allowlisted when supplied; clients can omit/forge Origin, so it is not an auth
mechanism. Use the authorization callback and application access controls.
Slow consumers are disconnected with an explicit capacity error. No compression
extension is negotiated. Tests cover malformed frames and independent peers;
no Autobahn/browser or hostile remote deployment certification is claimed.

Latest-patch validation found two tooling boundaries. Unannotated distribution
PHP/Connector coroutine stacks produced nondeterministic Valgrind invalid-read
and uninitialised-value reports. Rebuilding both with their existing supported
stack-registration hooks makes the same database workload clean (zero errors,
zero definite/indirect/possible loss,3,888bytes reachable). CI now tests this
annotated configuration and retains ordinary functional distribution-library
coverage. Historical stock-Valgrind passes do not replace annotated coverage.

A separate Clang18 full-PHP8.5.12 UBSan build reports incompatible callback
function pointer types in unmodified Zend/date/opcache code even under `php -n
-v` without Asyncara. These upstream diagnostics are retained; the Clang full-PHP
run is not marked passed. GCC ASan/UBSan validation and native Clang fuzzing have
different coverage; neither proves absence of those function-type issues.

Supported deployment targets require patchedPHP8.3.36/8.4.27/8.5.12 and matching
SDKs. Deferred soft OOM is handled at Asyncara conversion boundaries. A genuine
PHP hard-limit exit, OS kill or process crash cannot run request cleanup. Use a
process supervisor and do not rely on shutdown callbacks to undo external writes.

Dedicated native mappings are not ordinary malloc allocations in LeakSanitizer.
Their unused tails are explicitly poisoned for ASan bounds checks; mapping
release is independently checked with mincore, the aggregate ledger, native
worker lifecycle tests and FPM RSS trends. Do not interpret a clean LSan report
alone as proof that all anonymous mappings were released.


Phase 4 independently reproduced the libpq TLS method lifetime on16.15/17.11/18.6
without PHP/Asyncara. Resident500-connection controls show no lost allocations;
repeated library unloads accumulate109bytes per load, with additional system GSS
losses in the16.15 configuration. No private cleanup or suppression was added.
Matching Clang18/PHP8.5.12 runs still produce callback-type UBSan findings without
Asyncara. See DEPENDENCY_FINDINGS.md for exact scope and unresolved status.
New mixed/FPM output directories and newly created database fixture directories
are private0700. No host configuration or deployed services are changed.

Early Phase 4 one-hour mixed/FPM attempts failed responsiveness/deadline checks. Their
sampled memory counts do not establish safe long-running deployment. Diagnostic
tracing is confined to owned test processes and excludes application payload
syscalls. The original thresholds and failures remain preserved. Source and
short-run sanitizer checks cannot replace the outstanding endurance gate.

The r3 and earlier archives also accept malformed numeric-looking UDP targets.
Two independently reproduced consequences are 16-bit destination-port wrap
(delivery to a different port) and synchronous resolver entry blocking the PHP
thread. Strict literal-IP and destination-port validation now rejects these
inputs before suspension/transport parsing. The regression prevents invalid
addresses from reaching the network even when run against affected source.
See phase4/udp-destinations-fix.json. Historical archives are preserved unchanged;
verified f2abb55 / phase4-r4 includes the repair. This repair does not add hostname or
scope-ID support to the UDP API.

The corrected r4 source has now completed mixed1h/6h/24h and independent hosted
mixed/FPM1h workloads. Frozen manifests, binary/archive fingerprints and raw
resource trends were independently checked. These scoped passes coexist with a
local FPM responsiveness failure and timer lag above one second; no hard latency,
universal memory-safety or production-readiness claim follows. The FPM-only retry
also failed its original responsiveness assertion. Its external observer's false
ownership error on zombie exit is independently reproduced and repaired without
relaxing live ownership/PID checks. The observer follows actual cgroup ancestry,
records counters only and treats unavailable metrics as missing. Diagnostic FPM
tracing is explicitly opt-in and adds synchronous logging/ptrace overhead; it
must not be enabled in applications or treated as production latency evidence.
Runtime code, signal handlers and host limits are unchanged.

The additional direct wait probe is an opt-in Linux test interposer, loaded only
into an explicitly owned FPM diagnostic with `LD_PRELOAD`. It uses public libc
symbols and no Zend API or engine-pointer modification. It logs numeric timing,
PID/TID, timeout/result/errno metadata; no request or network payload. It is not
installed system-wide and must not be used in application configuration. An
initialization, clock or log-I/O failure exits that diagnostic process with125.
The analyzer rejects malformed, overlapping or oversized observations and closes
its temporary index deterministically. Instrumentation changes timing; neither
these measurements nor scoped sanitizer passes establish production readiness.
