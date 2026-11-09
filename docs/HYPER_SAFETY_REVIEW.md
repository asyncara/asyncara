# Hyper prototype safety review

Date:2026-11-09. Release:EXPERIMENTAL. Native API First remains unchanged.
This review concerns the optional Rust Hyper backend, not the default curl multi
backend. Neither universal HTTP feature parity nor a fastest-PHP claim is proven.

## Findings from the seven reported concerns

| Concern | Finding from source and execution | Current boundary |
|---|---|---|
| DNS | Confirmed missing: `ar_hyper_submit` parses a numeric IP. The public regression rejects localhost. | NOT_IMPLEMENTED in Hyper. Default curl supports DNS. No blocking resolver was added. |
| Portability | Hyper socket/FFI integration is Linux-only and now fails compilation explicitly on other targets. Certification of macOS/BSD was never established. | No portability implementation. Some cited constants also exist in BSD/Apple libc definitions; their presence alone does not certify behavior. |
| HTTPS | Confirmed missing: only the http scheme is admitted. | NOT_IMPLEMENTED in Hyper. Default curl retains verified TLS. No insecure TLS fallback. |
| Overwritten Wakers | Not established for the actual ownership model: Hyper SendRequest uses a dispatch channel; the connection driver owns and polls Io. | Rust test asserts each actual read/write poll uses the driver Waker across200 real64KiB responses. PHP test holds150 concurrent responses until two timer ticks, then releases them. This is scoped evidence, not proof against all deadlocks. |
| Compression and headers | Compression rejection is a prototype limitation;64KiB headers are a deliberate bound. A separate **real defect** ignored later Content-Encoding fields. | Every encoding field is now checked; identity followed by gzip/br and mixed lists reject explicitly. Decompression is still NOT_IMPLEMENTED. Header/trailer limits were not removed. |
| C callbacks/reentry | `ar_hyper_head/chunk` copy bounded bytes; `ar_hyper_complete` publishes to the native queue. They invoke no Zend/user callback, socket/file wait or Rust reentry. | Copying, allocator/page-fault time and the existing completion mutex can delay execution. No hard latency or lock-free claim. A new callback must preserve this contract. |
| Panic state reuse | Existing C drive/cancel errors already destroyed the engine. Other FFI entry points had incomplete unwind containment or ambiguous results. | Poisoned-engine fail-stop and distinct fatal results now cover all work/inspection entry points. C destroys Rust owners before job collection. |

## Proven corrections

`2678bd2f26a83b8e1eefafa109221bad8ee9e397` changes
[ext/hyper/src/lib.rs](../ext/hyper/src/lib.rs),
[ext/backend.c](../ext/backend.c), [ext/native.h](../ext/native.h) and
[ext/php_asyncara.c](../ext/php_asyncara.c).

`engine_ffi` catches unwinding panics, sets poisoned state, and rejects subsequent
work. Integer -2 and count SIZE_MAX are fatal; invalid submission remains -1 and
no timer remains -1. `hyper_start`, `wait_impl` and `asyncara_info` consume the fatal
sentinel. `ar_hyper_failed` drops the engine first and publishes each outstanding
completion once. Its counter now includes those failures. A submission that
panics after retaining a C pointer cannot leave that pointer alive after collection.
A panic after completion does not enqueue the completed job again.

Before-fix fault injections into descriptor enumeration and readiness aborted
at an unguarded extern-C boundary. Submission/deadline/connections controls found
missing fatal teardown. Drive/cancel already tore down the engine but failed the
completion-counter invariant. These are different findings: not every negative
control was a Rust unwind escaping to C.

[tests/http/hyper_ffi.py](../tests/http/hyper_ffi.py) builds a separate native
executable against a fault-enabled Rust library. Eight fault points on each of
poll/epoll check one teardown, unique completion IDs, zero pending C jobs/owned
bytes, and fresh-engine recovery. No production module contains the fault setter.
[ext/hyper/src/tests.rs](../ext/hyper/src/tests.rs) checks driver-Waker ownership
and rejection of all operations on poisoned state.

The second correction in `dd63396` changes `transfer` to inspect all
Content-Encoding values. A loopback response with identity followed by gzip
previously succeeded and exposed encoded bytes. The exact
[public regression](../tests/http/hyper_encoding.php) fails before the correction.
It covers both field orders, a comma-separated list, another unsupported coding,
repeated identity, subsequent recovery and completion/resource accounting.

## What fail-stop does not promise

Caught Rust unwind is not the same as arbitrary memory-corruption recovery.
Invalid foreign pointers, aliasing/reentry by a new unsafe C caller, allocator
abort, process termination, double panic and arbitrary destructor panic are not
recoverable guarantees. The default Rust panic hook can emit stderr. No user
payload logging was added. `AssertUnwindSafe` does not repair state: that state
is quarantined and destroyed. Safe Rust unwinding alone does not imply UB.

Rust/Hyper allocations are outside Asyncara's native result-buffer ledger.
The adaptive read maximum is128KiB per driver in the receive-geometry candidate;
headers/trailers remain64KiB and256 fields. Responses remain buffered, bounded
by the existing16MiB C body limit. Maximum256 connections and1024 pending native
jobs remain. A512-poll drive budget bounds poll count, not elapsed CPU time.
This is not a total RSS ceiling, streaming API, Tokio runtime or threadpool.

## Evidence identities and validation

Evidence root:
`/home/coder/Asyncara-http-transfer-evidence-20261109T211228Z`.
All output directories are unique. Original failures are retained.

- `fault-before-*`: original isolated fail-stop negatives; `fault-after-*`:
  first14 corrected cases. `hyper-ffi-final-data/report.json`: two Rust tests
  plus16 corrected fault cases, PASS44.994s. This predates the encoding fix.
- `wakeups-*`:150 genuine pending public responses, two ticks before release,
  curl/Hyper × epoll/poll, PASS. `hyper_wakeups.php` is now in the HTTP gate.
- `encoding-before`: raw minimal reproduction exits42 with both encoding fields
  and incorrectly returned bytes. `encoding-regression-before`: public regression
  exits255 on2678bd2. These are expected negative controls, not passing clients.
- `http-r3-85-data`: retained zero-tick connections32 failure,146.545ms maximum
  timer lag. Eight matched baseline/candidate repetitions each passed without
  establishing its cause.
- `http-r4-85-data`:66-command scoped gate PASS115.544s before final test additions.
- `http-safety-85-data`:2678bd2 gate FAIL on poll proxy TLS progress/timeout assertion.
  Four matched repetitions each on baseline/candidate passed. Failure reporting
  now records elapsed time, ticks, transport error and diagnostics; the original
 0.8s limit and two-tick assertion are unchanged. A repeat pass does not erase it.

Final-source PHP compatibility, memory and FPM validation results are appended
only after completed commands have been inspected. No short test here explains
the historical Phase4 zero-tick event or certifies long-duration stability.

## Performance remains a separate gate

The receive-geometry candidate `72fc710` improved64KiB rates against its paired
baseline in a three-sample screen, but did not beat every reference and made the
small-body Hyper median worse in that run. The13k floor,4x curl and3x Swoole targets
remain NOT_MET. The screen includes both default and128KiB-tuned ext-curl controls.
It is not substituted for the historical five-sample comparison in
[LATEST_BENCHMARK.md](LATEST_BENCHMARK.md). Correctness findings take priority over
new speed claims. This review does not make the Hyper prototype a complete HTTP
client or approve the receive-geometry performance experiment.

## Completed final native checkpoint validation

Runtime source:`dd633969e75b9a908f466daf64f0c711f13950e9`.
[Machine-readable identities, raw report/log hashes and statuses](phase6/hyper-safety.json).
PHP85 module:`build/hyper-safety-r2-85.so`, SHA256
`a566a3e97c86f1f8ef7de7faf79295fd6f98296c204cc5fcac97f8e3aa9af602`.
Subsequent changes in this review are documentation, CI wiring and the standalone
libcurl dependency control; no later runtime change is included in these results.

| Check | Actual result | Scope/evidence directory |
|---|---|---|
| PHP8.3.36 /8.4.27 /8.5.12, epoll+poll | PASS,72 commands/version | `http-safety-r2-{83,84,85}-data`;113.033/106.379/119.523s |
| DEBUG focused HTTP cases | PASS18 commands | `debug-{epoll,poll}-*` |
| GCC ASan/UBSan Hyper cases | PASS10 commands | `gcc-{epoll,poll}-hyper-prototype-*`; C/PHP instrumentation, Rust machine code not instrumented |
| GCC curl focused cases | PASS6; **FAIL2 trailer workloads** | Geometry reports2304 leaked bytes/72 allocations; see dependency finding below |
| Rust ownership + native FFI fail-stop | PASS2 Rust tests +16 injected cases | `ffi-safety-r2-data`,42.690s (normal production module has no fault setter) |
| Native FFI ASan/UBSan | PASS16 injected cases | `faults-gcc-*` |
| Native FFI Valgrind | PASS16;0 errors/lost bytes,56 reachable | `faults-valgrind-*`; no suppressions |
| DEBUG Hyper Valgrind | PASS6;0 errors/lost bytes,3994 reachable | `debug-valgrind-*`; no suppressions |
| Allocation failure audit | PASS | `allocation-safety-r2` |
| Gate A | PASS211.949s,187.286 CPU seconds | `gate-a-safety-r2-data`; three seeds,10000 iterations/path, original negative control |
| Short curl FPM | PASS20+40 main responses | `fpm-safety-r2-data`;8.119s, min26/25 ticks |
| Short Hyper FPM | PASS20+40 main responses | `hyper_fpm-safety-r2-data`;8.544s, min22/19 ticks |
| Hosted optional Hyper CI | NOT_EXECUTED | New workflow is configured; no GitHub run or push claimed |

FPM reports were independently recomputed from individual responses: four
HTTP intervals overlap per request, original >=2ticks holds, pending native
operations and tracked bytes return to zero. All idle samples have6FDs/1thread.
Curl RSS samples:20652..21184KiB; Hyper:15556..17916KiB. These brief samples do
not prove a long-term RSS trend. No test worker remains running. The protected
Phase4 checkout remains on6a7e0f4 with its three pre-existing untracked soak
review files; source/acceptance fixture changes were confined to this independent
worktree. No global PHP installation, dependency or license was changed.

### Independent libcurl failure

The expanded binary/chunked geometry test exposed a leak in the installed
libcurl8.5.0-2ubuntu10.16. Both candidate and previous native source fail with
2304 lost bytes. A standalone program without PHP/Asyncara reproduces288 lost
bytes over10 reused trailer-bearing responses; the separate close-connection
control has0 lost bytes. This matches the upstream trailer-buffer defect.
See [DEPENDENCY_FINDINGS.md](DEPENDENCY_FINDINGS.md) for exact library hash,
upstream references and unsuppressed stacks. The affected check remains **FAIL**;
other passing checks must not be combined into a universal memory-clean claim.

The original Phase4 timing failure, this continuation's connections32 zero-tick
failure, and its first proxy timing failure are preserved and remain unexplained.
No assertion, timer threshold, proxy timeout or original workload was weakened.
No DNS, TLS, compression or cross-platform support was added to Hyper.


## Local source distribution and reproducibility

`build/asyncara-0.1.0-dev-hyper-safety-r1.tar.gz`,521files, packaged source
`3317084eb834e2ac14b80dc8d0211318bdb0d888`, native/tested runtime dd63396.
SHA256:`a29eab103da8f5fb4df78314a50afe670a483c7ace46ab743c91c9bd144db78b`.
Two independently generated archives are byte-identical; each member matches
its committed Git blob. A fresh extraction and independent Cargo/C build pass
six focused Hyper regressions on epoll/poll. Independent module SHA256:
`c2cc81d42364d5d81dd7f11e44ee179599986612de2c87e74b406f689065afde`.
[Build attestation](phase6/hyper-safety-package.json). This is a local experimental
candidate, not a published release or a full independent package/endurance gate.
The later attestation commit changes documentation/evidence only.

Next priorities are a vetted curl dependency fix with the same unsuppressed
trailer regression, the still-unexplained progress failures, and verified DNS/TLS/
decompression before considering Hyper feature parity. No universal benchmark
lead or production-readiness claim is justified by this work.
