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
- HTTP permits only HTTP(S), verifies certificates/hostnames, defaults to no redirects, and rejects CR/LF header injection. It does not implement SSRF policy: restrict destinations and proxies in the application. Buffered compression is bounded by the decoded-body cap; streaming disables automatic content decoding and uses HTTP/1.1 to limit foreign pause buffers. Default streaming downloads can leave a partial destination; the explicit atomic option stages and publishes the completed file as described in HTTP_STREAMING.md. Native source84e49f1 rejects unsupported HEAD uploads/content before transport, preserving bodyless HEAD response framing; HTTP_HEAD_SAFETY.md records the tested scope.
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

The PHP-side profiler now rejects failed or incomplete log writes explicitly.
Known FPM error logs are checked for instrumentation failures even in tests that
expect fatal memory exhaustion, so a failed measurement cannot silently count as
a clean diagnostic. Negative controls reproduced the previous acceptance paths.
These changes concern test evidence, not PHP memory-exhaustion recovery or the
application runtime; the frozen running campaign retains its original tools.

## Native reliability continuation (2026-11-07)

`9bee5c3` (following intermediate `f470ea9`) repairs unchecked SQLite column-name and value-conversion allocation
failures in the native worker. Link-boundary fault injection into production
query execution reproduced two invalid-memory crashes and one wrong-success
result before repair. The correction preserves empty-value semantics and
statement/connection cleanup. `27d9a65` independently prevents native wait
preparation from restarting the caller's relative timeout budget. Neither is
claimed to explain the historical FPM zero-tick failure.

The distribution-PHP/Valgrind difference now has an independent PHP-only cycle
shutdown reproducer. Original failures remain failures; separate explicit-GC
controls and DEBUG's additional shutdown collection explain the build
comparison. This is not an unconditional leak-free claim, nor a suppression of
Fiber warnings. See DEPENDENCY_FINDINGS.md and NATIVE_OWNERSHIP_AUDIT.md. Exact
final-source instrumentation/gate coverage is in NATIVE_RELIABILITY_PROGRAM.md.

## Resource identity and diagnostic provenance continuation

Canonical owner objects now reject shallow cloning and serialization; ordinary
PHP reference assignment remains supported. TcpStream rejects a second wrapper
for an already owned stream. Statement/emitter/source/PSR clones share a canonical
owner instead of fabricating a new native handle. These policies do not authorize
external raw resource manipulation or turn PHP Reflection into a sandbox.

The opt-in diagnostic ring stores bounded numeric metadata, no payloads or
credentials. It never performs producer-side output or Zend access from workers.
Exports may contain operational process/descriptor/timing information and should
be protected. Output can fail or be incomplete; successful capture cannot be
guaranteed after fatal termination. See DIAGNOSTICS.md for explicit losses and
maximum memory, and RESOURCE_PROVENANCE_PROGRAM.md for measured overhead.

The distribution-PHP memory workload still reports losses. Matched PHP cycle
controls explain shutdown behavior but not every rich-workload retention
difference. Scoped clean DEBUG/native results are not a universal clean-memory
claim. DEPENDENCY_FINDINGS.md retains full unsuppressed evidence.


## HTTP completion boundaries

Multi-file MIME rejects NUL/CR/LF and bounds metadata, fields, part counts and
request headers. TLS peer and hostname verification remain mandatory in native
HTTP; the local CONNECT proxy regression verifies rejection of an untrusted
certificate after a trusted pooled request. Explicit proxies override NO_PROXY.
Do not treat redirects/proxies as an SSRF policy: applications must validate
allowed destinations and network access for untrusted URLs.

Atomic downloads require a trusted destination directory. A pinned directory
descriptor and random O_EXCL/O_NOFOLLOW staging name prevent path reopening and
accidental overwrite; they do not protect against an attacker authorized to
replace entries inside that directory. Temporary permissions are 0600. Abnormal
process termination can leave unpublished temporary files. Optional fsync is
scoped to successful filesystem calls, not universal storage-device durability.

No production-readiness or universal memory-safety claim follows from the new
HTTP tests; historical distribution-PHP Valgrind losses and FPM zero-tick failure
remain separately recorded in RESOURCE_PROVENANCE_PROGRAM.md.

The HTTP continuation fixed a staged-resource acquisition/cancellation race. A
cancelled download could lose the completed File result before taking ownership,
retaining its private file and native descriptor until request shutdown. The
shielded assignment and completion-boundary regression prevent this specific
retention. No historical zero-tick attribution or universal leak guarantee follows.

## Optional Hyper FFI containment

The Hyper prototype remains plaintext buffered GET on Linux. Worker-backed DNS
now preserves original Host and isolates connection pools by origin; endpoint
arrays are bounded and copied before submission returns. It does not implement
TLS or decompression. See [DNS continuation](HYPER_DNS.md). The default curl backend remains the
application-facing general HTTP path. Hyper rejects every unsupported
Content-Encoding field, including duplicates after identity. See
[HYPER_SAFETY_REVIEW.md](HYPER_SAFETY_REVIEW.md) for the reproduced bypass and scope.

Unexpected Rust unwinding poisons the request-local engine. Fatal FFI sentinels
cause C to drop Rust futures/sockets before publishing remaining failures, with
no duplicate completion. Fault controls are compiled only into isolated test
executables. This cannot recover an allocator abort, foreign-pointer misuse,
arbitrary destructor failure or process termination. Rust allocations are not
fully represented by Asyncara's native ledger. No universal memory-safety or
hard callback-latency guarantee follows from scoped tests.

The expanded framing test also reproduces a system libcurl8.5.0 chunked-trailer
leak without PHP or Asyncara linked. It persists in the previous Asyncara source.
Loss accumulates across reused connections;32bytes/trailer in the minimal fixture
is not a general upper bound. Asyncara's native ledger does not contain this
external allocation growth. The affected memory check remains FAIL pending a
vetted dependency repair and rerun; see DEPENDENCY_FINDINGS.md. No global library
was changed and no suppressions or pool-disabling workaround were introduced.


## Native server TLS and streaming boundary (2026-11-10)

Optional server TLS uses pinned rustls/AWS-LC, TLS1.2/1.3 and HTTP/1.1 ALPN. Keys
and certificate chains are setup-time local PEM inputs, each at most256KiB.
Client certificate authentication is mandatory when clientCa is configured;
there is no insecure bypass. Certificate rotation/revocation, multi-certificate
SNI routing and deployment-wide denial-of-service protection are not certified.
Connection admission and the existing request-cycle deadline also cover TLS
handshakes. TLS crypto and PHP application code consume interpreter-thread CPU.

Server response streaming enforces64KiB chunks, total responseBytes, and native
payload admission. Hyper/TLS/kernel/PHP memory is additional. A failed producer
after headers aborts the connection; application effects are not rolled back.
Do not assume a yielded chunk has reached the remote application. Arbitrary PHP
producer code can block a worker. Opt-in streaming inputs are handler-owned and
half-duplex; bodyBytes still bounds the total input. Historical memory findings
and endurance failures are unchanged. See SERVER.md and COMPLETION_STATUS.md.

The native server key parameter is marked SensitiveParameter during MINIT,
including invalid configuration with zend.exception_ignore_args=0. Synthetic-key
negative/positive tests verify redaction and listener cleanup. This does not
wipe all allocator copies of PEM data or hide application-owned configuration.
The initial rustls/ring AES-GCM Valgrind finding is retained separately in
DEPENDENCY_FINDINGS.md. AWS-LC was selected only after matched controls and the
actual server passed scoped unsuppressed memory tests. That does not certify
every TLS or application allocation path.

## Optional Hyper HTTPS client

Verified TLS1.2/1.3 GET is implemented using rustls/AWS-LC with DNS/IP identity
checks and HTTP/1.1 ALPN. Trust comes from explicit caFile, SSL_CERT_FILE or the
documented Linux CA path; missing/invalid trust fails closed. No verify=false
option, network trust fetch or implicit curl fallback exists. Private-CA tests
reject an unrelated CA, hostname mismatch, expired leaf, wrong extended-key usage
and a server requiring a client certificate. Client mTLS is not implemented.

Exact trust bytes segregate pooled connections. Existing clients intentionally
retain their trust snapshot; create a new client to load changed roots, subject
to the request-local 16-policy bound. This is not automatic certificate rotation
or revocation checking. Proxy environments are explicitly rejected by Hyper.
See [HYPER_TLS.md](HYPER_TLS.md) for the tested scope and allocator limitations.

The subsequent buffered-request adapter validates and copies method/header/body
views before returning across FFI. Caller-owned HTTP framing and proxy headers,
CONNECT/TRACE and nonempty HEAD bodies are rejected. Upload completion, peer
rejection and cancellation have distinct ownership paths; local flush is not
remote application acknowledgement. An early final response cannot erase the
native accounting of a still-buffered keep-alive upload. The extra Rust body
copy and foreign library allocations are outside complete C-ledger accounting.
See HYPER_REQUESTS.md for proven defects, corrections and scoped validation.

## Opt-in PostgreSQL server preparation

Prepared names and the LRU belong to one connection. Names use a reserved ASCII
prefix plus an integer; application parameters remain protocol bindings. Entry
and SQL-byte limits do not bound every server parse tree, libpq buffer or PHP
metadata allocation. A closed connection clears its cache; failed/cancelled
operations are not replayed. Explicit manual deallocation can invalidate the
cache and is surfaced as an error rather than hidden by a retry.

The new ordinary-query, pending-exit and native OOM cleanup checks pass, but
E_USER_ERROR bailout under DEBUG PHP/Valgrind still reports Zend allocations.
Matched old/new/cache-disabled controls have identical lost-byte totals; a
PHP-only fatal control also fails. These observations do not certify arbitrary
fatal cleanup or erase the report. See POSTGRESQL_PREPARED.md and
DEPENDENCY_FINDINGS.md for the exact allocator and workload distinctions.

## Hyper bounded streaming

Streaming enables no new verification bypass or proxy fallback. Verified TLS
and worker-loaded trust policies apply to uploads/downloads as well as buffered
requests. The64KiB C slots reject/pause safely and aggregate-limit failures are
explicit. Rust frame/upload copies and TLS/kernel memory remain separate from
the C ledger. Malformed or truncated transfers fail; prefixes already delivered
to a consumer cannot be recalled. Atomic downloads publish only after complete
success. Buffered decompression remains unsupported; streaming intentionally
delivers encoded bytes. The new scoped memory checks do not erase historical
PHP fatal-bailout, distribution-build, libpq or libcurl findings.

## Database connection trace redaction (2026-11-10)

IMPLEMENTED_AND_TESTED, scoped to connection and task-callback argument traces.
Native `asyncara_pg_open` / `asyncara_mysql_open`, the SQL factory, public SQL
connection options and Redis's password use PHP SensitiveParameter. Spawned
callback arguments are also redacted: Closure debug output can reveal captured
connection options even after the public password parameter is protected.
No scheduling, connection, cancellation or result-ownership behavior changes.

A synthetic-marker negative control exposed9 cases before the correction.
The final11-case regression passes PHP8.3/8.4/8.5, DEBUG and GCC ASan/UBSan on
both readiness backends. Gate A224.319s and both full PHP85 suites pass.
Scoped unsuppressed DEBUG Valgrind reports zero errors and lost bytes.
[Commands and retained failures](phase7/credential-traces.json).

Redaction is not memory erasure or protection against explicit application
logging, inspecting SensitiveParameterValue, dumping captured objects, caller
frames, SQL/Redis command payloads or messages received from external servers.
Historical memory findings remain separate; no universal secret/memory guarantee.
