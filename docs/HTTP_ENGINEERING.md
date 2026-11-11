# HTTP completion and measured performance

## Current proxy authentication scope (2026-11-12)

[Explicit Basic proxy authentication](PROXY_AUTHENTICATION.md) now has local
HTTP/HTTPS forward and CONNECT tests, including buffered HTTP/2 over authenticated
HTTPS CONNECT. Earlier statements below excluding authentication describe their
original checkpoints. Hyper still rejects proxies; streaming remains HTTP/1.1.
See the [current capability contracts](PRIORITY_CAPABILITIES.md) for remaining
protocol and deployment gaps. Historical failed memory/deadline runs remain.

This report records the historical http-r1 checkpoint. Later source changes,
validated ownership optimizations and the optional PHP-integrated Hyper prototype
are in [HTTP_PERFORMANCE_WORK.md](HTTP_PERFORMANCE_WORK.md) and
[HYPER_EXPERIMENT.md](HYPER_EXPERIMENT.md). Historical results below remain
attached to their original modules and source.

Release: **EXPERIMENTAL**. Branch `phase6-http`, isolated worktree
`/home/coder/Asyncara-http-20261109T050419Z`. Base
`79d142f900a6324051484b921be23c9c81c92bd4`; implementation `a410d58`, corrected
wait-boundary checkpoint `92d0ba0cd28194a2626de3c4c12ca49ab9c25d4d`.
The test-only clock separation is `c6b5c89`; atomic acquisition ownership is
`45a4a20`, with full-suite wiring `6b9da6b`. Native C is unchanged after92d0ba0.
Evidence root: `/home/coder/Asyncara-http-evidence-20261109T050419Z`.
The preserved Phase 4 checkout, modules, failures and archives were not used as
mutable development files. No release publication or force push is part of this work.

The requested **4x curl_multi / 3x Swoole target is not achieved**. Asyncara's
public HttpClient is slower than both controls in the completed matched local
benchmarks. No fastest-in-PHP claim is justified. Feature completion is scoped
below; this is not universal HTTP, proxy or deployment certification.

## Implemented native and public behavior

| Capability | Implementation / ownership | Executable evidence |
|---|---|---|
| Multi-file multipart | `HttpClient::multipartFiles()`, 1..16 sequential lazy file sources; curl MIME `send_part()` shares one 64 KiB native slot; existing single-file API preserved | `tests/http/completion.php`, `native_completion.c`, original `tests/http_streaming.php` |
| Acquisition cancellation | Download takes ownership of a staged File under a shield before observing cancellation; existing finally closes it | `acquire_cancel.php`: real completion-boundary cancellation,20 cycles; old source retains1 handle/175 bytes, corrected source zero |
| Atomic streamed download | `download(atomic: true)`, worker-backed `File::stage()` / `publish()`; pinned parent directory, random exclusive 0600 staging, `renameat`; optional file+directory fsync | `tests/http/stage_native.c`, `completion.php`, `stage_shutdown.py`, `fpm.py` |
| Admission configuration | Request-local total/per-host limits, defaults64/8 preserved, configurable1..256 before native submission; client permits separate | `tests/http/connections.php`:8/16/32 caps, pending-I/O ticker and cleanup |
| Connection setup deadline | Trailing `connectTimeout` constructor parameter; existing total transfer timeout preserved | `tests/http/proxy.php`: silent TLS peer, at least two pending-I/O ticks |
| Proxy/TLS | Explicit proxy overrides NO_PROXY; curl verifies certificates/hostnames | Local forward HTTP and HTTPS CONNECT, private CA success/rejection; `tests/http/proxy.php` |
| Header safety | At most256 entries/64 KiB including CRLF; metadata rejects injection characters | `completion.php`, native submission validation |
| Redirects and partial bodies | Buffered POST303 becomes GET; streamed methods preserved; redirect loops/disallowed schemes/truncation fail; atomic destination survives prepublication error | Original streaming test and `completion.php` |
| Native result memory | `take_http_buffer()` transfers native allocation ownership into the result; no second full native body copy | Native pointer-identity test;3 MiB response under7 MiB aggregate budget, old baseline fails |
| Easy-handle reuse | At most64 detached/reset easy handles per request; shared multi retains its connection cache; cleanup at RSHUTDOWN | `pooling.php`:200 requests,1 creation/199 reuses; header/cookie reset; FPM cross-request counters |
| Multi failure handling | `http_action()` rejects/detaches all active jobs on CURLM error, drops invalid multi, permits recovery | Baseline negative control fails;3 cycles of8 injected failures collect exactly once with zero ledger |
| Batched start / wait | New handles start together at poll; buffered starts preserve real wait budget, streaming starts/resumes retain immediate dispatch | `start_wait.c`: real loopback epoll/poll; old candidate incorrectly requests0ms; corrected buffered wait positive, streaming0ms |

Native files: `ext/backend.c`, `ext/native.h`, `ext/workers.c`,
`ext/php_asyncara.c`. Public changes: `src/Asyncara/HTTP/HttpClient.php`,
`src/Asyncara/IO/File.php`, `src/Asyncara/Runtime.php`.

Network waits remain **EVENT_DRIVEN_LIBRARY** through curl multi socket readiness
and timer queries. Disk reads/writes/fsync/rename remain **WORKER_BACKED_ASYNC**.
No blocking `curl_easy_perform`, PHP cURL hooks, io_uring or replacement scheduler.
Asyncara does not own curl's HTTP/chunk parser. Existing bounded response streams,
iterable/file uploads, JSON/form, compression for buffered responses, cancellations,
connection pooling and PSR integration remain available.

## Lifecycle and limits

See [HTTP streaming](HTTP_STREAMING.md), [lifecycle contract](IO_LIFECYCLE_CONTRACT.md),
[limits](RESOURCE_LIMITS.md) and [security](SECURITY.md). These are material limits:

- Atomic publication replaces permissions with0600; it does not preserve destination
  metadata. A trusted destination directory is required. No cross-filesystem copy
  fallback. Cancellation cannot undo a completed rename or network bytes already sent.
- Durable publication syncs the file before rename and the parent after it. A later
  sync failure reports `committed=true`; a visible file is not silently rolled back.
  Permission changes can prevent unlink. SIGKILL/host failure can leave staging files.
- Native close remains mandatory after acceptance, and shutdown joins workers before
  releasing their handles. Fatal PHP cleanup tests do not mean the request recovers.
- Stream bodies retain64 KiB native plus bounded PHP chunks; multipart files share
  one upload slot. Curl/OpenSSL/socket allocations are not fully charged to the ledger.
- Connect and total deadlines are different. Total transfer timeout includes curl
  admission and paused time, but starts after the client's semaphore admission.
- HTTP/1.1 streaming uses encoded bytes/identity negotiation. HTTP/2/3 remain
  uncertified. No nested MIME, redirected streaming-body replay, resumable transfers,
  trailer API, SOCKS/proxy-authentication or broad WAN compatibility claim.
- An application iterable/callback can block the interpreter. Library CPU work,
  TLS setup and foreign allocation are not hard real-time operations.

## Controlled negative evidence and retained failures

All failing outputs remain under the evidence root:

- `buffer-budget-r3-before`: baseline rejects3 MiB completion under7 MiB native
  budget; the corrected implementation passes. The earlier5 MiB design exhausted
  geometric buffer growth before completion, so it was not a valid copy test.
- `multi-error-before`: baseline leaves injected CURLM failures pending; corrected
  result collection passes. The negative native process abort is intentional.
- `start-wait-before-run`: `a410d58` manufactures a zero-time buffered turn;
  corrected real-kernel timeout test passes. This is not a historical zero-tick fix.
- Candidate streamed POST initially became PUT after enabling CURLOPT_UPLOAD; the
  unchanged streaming assertion failed, then the explicit method repair passed.
- A missing locked Nyholm dependency caused an initial full-suite failure; the
  dependencies were installed without changing composer.lock or the assertion.
- Python benchmark fixture shutdown, output-directory collisions and the first
  isolated nginx temp-path setup failed operationally. Corrected infrastructure
  uses new output paths; old logs are retained, not renamed as runtime successes.
- `nginx-performance-summary.json` accidentally grouped both body sizes together.
  It is superseded by `nginx-performance-summary-r2.json`; all raw samples remain.

- `release-vg-stage`: Valgrind could not start without the existing local glibc
  debug-symbol path; subsequent commands supply it without suppressions.
- `release-vg-r2-wait`: real50ms budget expires under instrumentation, making the
  positive-timeout test assumption invalid. The unchanged real-clock check stays;
  a separate test-only clock control verifies admission logic with real kernel
  readiness under Valgrind. Before/after variants fail/pass in both modes.
- `release-vg-r3-debug`: completion test found a live native owner before request
  shutdown; a diagnostic repetition passed but did not erase the failure.
  `acquire-cancel-before` deterministically demonstrates staged-resource ownership
  escaping between worker completion and cancelled parent resumption. The repair
  is `45a4a20`; new result matrices are recorded below. No allocator suppression.

Historical one-hour FPM PASS, six-hour FAIL and unresolved zero-tick attribution
remain unchanged. The richer distribution-PHP Valgrind discrepancy remains separate
from the new scoped native/DEBUG HTTP checks.

## Performance and Rust Hyper decision

[Raw sample summary](phase6/http-performance.json) retains all170 nginx samples,
individual throughput/CPU/latency/RSS and original report/log hashes. Each sample
checks8,000 complete bodies after10 warmups per persistent connection. Clients run
serially in rotated order against one isolated nginx 1.31.7 process, with identical
concurrency and payload. PHP 8.5.12 NTS, curl 8.5.0, Swoole 6.2.3; `php -n`, no JIT.
The request/response wire workload is matched, not every API allocation: Asyncara
constructs Response/header objects, Swoole exposes its own response fields, and the
raw curl control checks status/body directly. These are public-client versus
low-level-client costs; the raw Asyncara control isolates some of that overhead.
Shared-host CPU pressure and server CPU are recorded; no CPU pinning or exclusive
host is claimed. Medians below are medians of five per-sample rates.

| Backend / concurrency / body | Public Asyncara requests/s | curl_multi | Swoole | Historical Asyncara79d142f |
|---|---:|---:|---:|---:|
| epoll /8 /1 KiB |3718.3|13613.0|16691.9|3734.6|
| epoll /8 /64 KiB |2761.9|7640.7|11342.6|2133.4|
| epoll /32 /1 KiB |4140.9|16767.9|16369.7|Not compared: old per-host cap8|
| epoll /32 /64 KiB |2400.0|9851.4|9200.1|Not compared|
| poll /8 /1 KiB |4613.6|16181.9|17339.7|4119.0|
| poll /8 /64 KiB |2945.6|8221.7|11751.1|2029.5|

The64 KiB epoll8 case improves1.29x over the old Asyncara median, while the1 KiB
case is essentially unchanged. This does not generalize across hosts/workloads.
At epoll /8 /1 KiB, public Asyncara/curl_multi is0.273x and Asyncara/Swoole0.223x,
far below requested4x/3x. Median client CPU for8,000 requests is2.068s versus
0.537s/0.436s; median per-sample p99 is5.77ms versus2.13ms/1.63ms.
These benchmarks use native92d0ba0 and its PHP source. The later atomic-download
acquisition repair changes only download cancellation, not the measured GET path.
The earlier Python delayed-peer comparisons remain in `benchmark-paired` and
`benchmark-c32`; these are separate workloads, not merged with nginx results.

The authorized Rust experiment uses Hyper 1.12.0, Rust 1.99.1 and one Tokio runtime
thread. It is **not a PHP backend**, excludes PHP/FFI/Fiber costs, and covers only
plain HTTP/1.1 local keepalive with bounded checked bodies. Its medians at epoll8's
shared fixture are15840.6/11818.0 requests/s for1/64 KiB, compared with
curl_multi13613.0/7640.7 and Swoole16691.9/11342.6. It does not approach the targets
even before integration costs. Existing TLS/proxy/cancellation/FPM semantics would
still require a separate verified bridge. No production Rust dependency was added.
The source/lockfile is preserved in `benchmarks/http/hyper/`. Hyper's
[C interface is unstable](https://docs.rs/hyper/latest/hyper/ffi/index.html);
this experiment uses its Rust API.

The raw Asyncara diagnostic control bypasses Tasks/Fibers and reaches10644.1
requests/s at epoll8/1 KiB. It is not public HttpClient throughput. A separate
800-request syscall profile (plus80 warmups) records945 mprotect /898 prctl calls
for the public client versus48 mprotect for the raw control, consistent with
per-request Fiber stack churn; userspace scheduling/conversion also contributes.
`http-syscall-profile/` contains complete strace summaries. Instrumented timings
are not benchmark results. This evidence favors profiling the public request
lifecycle before replacing the HTTP protocol engine. No speculative Fiber pooling
or ownership shortcut is included.

## Reproduction

```sh
PHP_BIN=/path/to/php ASYNCARA_OUTPUT=build/http.so ./scripts/build-local.sh
./scripts/prepare-test-certs.sh
python3 tests/http/run.py --php /path/to/php --extension build/http.so --output /new/http-gate
FPM_BIN=/path/to/php-fpm python3 tests/http/fpm.py --extension build/http.so --output /new/http-fpm
python3 benchmarks/http/nginx.py --php /path/to/php --extension build/http.so --output /new/nginx --samples 5 --count 8000 --concurrency 8
python3 benchmarks/http/run.py --php /path/to/php --extension build/http.so --output /new/delayed-peer --samples 5 --count 8000
```

Builds require matching PHP SDK and native library development headers; full tests
require locked Composer dependencies. Swoole/cURL benchmark competitors must be
installed for the selected PHP. Hyper is optional, built separately with the locked
Cargo manifest; pass its executable via `--hyper`. Missing competitors fail clearly.
All output directories must be new. Archive validation needs no Git metadata.

## Final validation

Tested runtime `6b9da6b7067637f56ab0764ae204d9132f8b47ef`, native92d0ba0,
module SHA-256 `96f4e160ebdb7910ab45e9c8ad25f6ee5cb4ea2b108c2d2b546e151da54db17b`.
The [validation attestation](phase6/http-validation.json) contains exact commands,
source manifest, per-version modules, failures, hashes and independent reviews.

| Completed check | Result / duration | Scope |
|---|---|---|
| PHP8.3.36 NTS HTTP | PASS /26.787s | epoll and poll, including deterministic acquisition cancellation |
| PHP8.4.27 NTS HTTP | PASS /25.854s | epoll and poll |
| PHP8.5.12 NTS HTTP | PASS /34.981s | epoll and poll plus native failure/ownership/wait controls |
| Full PHP8.5 suites | PASS /21.886s epoll,21.341s poll | Existing tests retained, acquisition regression added |
| DEBUG HTTP | PASS /27.072s | Both backends |
| Fully GCC ASan/UBSan PHP+module HTTP | PASS /52.559s | Both backends; explicit extra completion/acquisition runs enable LeakSanitizer |
| Native ASan/UBSan | PASS | Publication, completion, real/controlled wait tests; leak detection enabled |
| Native TSan | PASS /0.287s | Actual staged-file worker/error/close paths, no suppressions |
| Native / DEBUG Valgrind3.22 | PASS | Stage/completion/wait controls; two repaired full HTTP repetitions plus acquisition regression; zero errors, zero definite/indirect/possible lost bytes |
| Allocation audit | PASS /4.331s | Existing allocation failure checks |
| HTTP callback fuzzing | PASS /543,316 inputs in31s | Peak146MiB, seed20261102; Asyncara callbacks, not curl's parser |
| Feature FPM | PASS /8.568s command wall |60 recorded responses plus fatal/recovery; minimum27 ticks, all idle workers6FD/1thread |
| Gate A | PASS /176.034s | Three seeds, real epoll/poll, separate virtual time, native/scheduler/cancellation/diagnostics regressions |
| Gate B1+B2 | PASS /757.849s |350,004 real kernel waits,210,000 worker jobs; separate600.253s FPM interval |
| Independent B2 review | PASS |9,236 main responses, original A/B/C/D/recycling/reload/replacement/fatal/timeout checks |
| Gate C / new long-duration campaigns | NOT_STARTED | No long-duration certification for this HTTP source |
| Hosted CI additions | NOT_STARTED | Workflow includes focused HTTP and feature FPM; no hosted run claimed |

B2's raw-derived operation counts are443,328 pending reads,332,496 I/O completions,
55,416 timer firings,110,832 cancellations,886,656 closed endpoints and1,451,434
scheduler Task steps. These are not extra HTTP responses. Minimum/maximum pending
I/O ticker counts are5/56; the original minimum remains2. Four final workers have
6 idle descriptors and1 thread each. RSS ranges17,392..19,940KiB across workers;
per-worker post-initial-sample range372..460KiB, beyond-warmup growth636..3464KiB,
below the unchanged8192KiB threshold. Linear slopes871..1138KiB/hour over this
short interval are not a long-term leak forecast. All three recorded FPM masters
exit0. The independent reviewer preserves its clock-order and helper-exit-record
limitations. No short result certifies the historical six-hour failure.

Valgrind still-reachable memory is reported, not erased: the DEBUG HTTP process
retains3888 bytes/13 blocks at library unload; the controlled native wait retains
56 bytes/1 block. Earlier aborted/incomplete runs remain separate failures. The
older richer distribution-PHP allocation discrepancy is not reclassified as clean.
No new universal memory-safety guarantee follows from scoped tests.

A+B is933.883s (15.565min) on this host. The new-feature FPM smoke is separate
from Gate B's existing mixed workload; prolonged multi-file/atomic-download
endurance remains unverified. The historical original200,000-response target is
not claimed by this revised native-operation-plus-FPM gate.

## Packaging and next engineering boundary

Package source `f818d2975ef80d8188f751d07eb4243e5395263c`,463 tracked files,
`build/asyncara-0.1.0-dev-http-r1.tar.gz`, SHA-256
`d811baa43e71ca62df128c0626d9e115341b041191c64cd4011e088787cd20f0`.
Repeated generation is byte-identical; every archive member matches Git. Fresh
extraction, independent locked Composer install, PHP83/84/85 builds and HTTP
epoll/poll tests pass, plus full85 suites on both backends. All12 validation
commands exit0 in147.177s including packaging. Public SDK/library headers are
external dependencies; no previous generated module/vendor/build outputs were
reused. Composer reports only root-version fallback; LICENSE/composer.lock are
unchanged. [Package attestation](phase6/http-package.json) records independent
module hashes and exact commands. It is a later documentation artifact, not part
of its own archive. No old archive is overwritten; no release is published.

The next measured performance target is the per-request Fiber/Task lifecycle and
completion conversion cost, with the same admission/cancellation/result-ownership
semantics. A native completion path should be prototyped and benchmarked before
any broad architecture change. Rust Hyper is not justified as a production
replacement by the current standalone measurements. HTTP/2/3, broader proxy/auth,
large-transfer endurance and the historical reliability uncertainties remain
explicitly outside the completed scope. Release remains **EXPERIMENTAL**.
