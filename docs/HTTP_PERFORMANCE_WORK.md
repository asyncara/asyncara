# HTTP public-path performance investigation

## Latest checkpoint: c4aea948233d79aee887c9ceeea4b56da340cc57

Performance acceptance remains **NOT_MET**. The latest longer sample group
(64000 requests per sample, five rotated samples, four nginx workers) measures
1KiB public median10086.979/s, minimum9070.644/s; ratios0.9726x curl_multi and
0.8494x Swoole.64KiB median4840.751/s. Raw results:
`sustained-reference-comparison-data/report.json` in the evidence root below.
The earlier14322.756/s median is not substituted for this later slower result.

Matching PHP8.5.12 reference modules
are now built and fingerprinted; no fastest-PHP,4x curl_multi or3x Swoole claim
is supported. The earlier high-median samples below are historical observations,
not an acceptance result. Evidence summaries and hashes are retained in
[http-current-evidence.json](phase6/http-current-evidence.json).

The latest C correction snapshots curl interests once per wait and matches only
paused operations to socket owners. Without a paused stream its setup cost is
O(jobs + sockets), previously O(jobs * sockets). Original curl interests survive
pausing; unpause restores them on the next wait. No resource identity, wait
timeout, dispatch ordering or ownership is changed. `native_completion.c` covers
receive/send/both pauses, completed jobs, unrelated owners and restoration.
Real upload/download/backpressure suites provide the integration checks.

`wait_scaling.c` keeps1024 real submitted HTTP operations pending over256 real
connections while a separate socket wakes the production wait. Across three
repetitions,2000 waits consumed median2.572127s CPU before and0.185800s after
on poll; epoll consumed5.473897s before and2.140206s after. This narrowly scoped
wait-cost control is **not HTTP response throughput**. Both collect all1024
operations after cancellation with zero pending operations/tracked allocations.
The otherwise identical public-client comparison at concurrency128 gives
7913.0/s versus7967.7/s baseline for1KiB, so no general small-response gain is
established.64KiB medians are3285.5/s versus3096.4/s in this sample group.
All raw observations, including negative comparisons, remain preserved.

`2d6f539` separately rejects invalid explicit Hyper ports before operation
admission. Previously `port_u16().unwrap_or(80)` confused invalid and missing
ports. The negative control submits/cancels before driving sockets; it never
contacts port80. `hyper_uri.php` now covers IPv4/IPv6, overflow, empty/alphabetic
ports, userinfo, valid extremes and admission/collection bounds. Its tests and
all targeted PHP83/84/85, DEBUG, scoped GCC and DEBUG-Valgrind checks pass at that
checkpoint. Latest c4aea94 validation is recorded separately below.

`matched-reference-comparison-data/report.json` retains five rotated samples:
1KiB public Hyper median7548.266/s, minimum6233.931/s,0.6699x curl_multi and
0.7577x Swoole;64KiB median3408.285/s. The separate optional LTO build gives
9759.409/s and3691.298/s medians, without establishing the requested targets or
a consistent1KiB CPU improvement. LTO is not a new default.

The optional benchmark `--public-pattern batch` uses existing public get()/await()
with one root Fiber and a bounded batch barrier. It is separately labelled and
does not replace rolling-worker results. Raw-native mode remains diagnostic,
not public API throughput. Changing an application scheduling pattern cannot
be presented as a native backend speedup.

## Earlier implementation and experiments (preserved)

Status: **EXPERIMENTAL, IN_PROGRESS**. Base `3fe4cf9d1a774b04a8d5d14bf111f424b4b2de8a`.
Worktree `/home/coder/Asyncara-http-20261109T050419Z`, branch `phase6-http`.
Evidence `/home/coder/Asyncara-http-speed-20261109T073938Z`.
The default curl checkpoint below did not meet the 13,000 public HttpClient
requests/second target. The later [Hyper experiment](HYPER_EXPERIMENT.md) exceeds
that median only in a specified JIT/poll/1 KiB workload, with slower samples and
limitations retained. It is not a universal throughput floor.
No 4x curl_multi, 3x Swoole, or fastest-PHP claim is supported.
Historical Phase 4 evidence and the http-r1 archive are unchanged.

## Implemented candidate

- Buffered requests admitted immediately use a native-completion-owned Future,
  without a new Fiber. Contended admission retains the existing FIFO Task path.
  Slots and the former Task admission budget remain owned until native collection.
  Shared waiter cancellation does not cancel its producer. Explicit operation
  cancellation drains the result. Unobserved owned HTTP failures still reach run exit.
- Task Future waits reuse two bounded observers instead of allocating a closure
  graph on each wait. The ready queue, first-settler behavior, cancellation tokens
  and trace events remain. Observers are detached after each wait and cleared when
  the Task finishes. Generic descriptor/timer suspension is unchanged.
- Interpreter-thread HTTP completions skip their redundant pipe notification.
  Worker publication retains the EINTR retry and real wake byte. The operation
  kind is captured before publishing; workers must not read a collected job.
- Socket readiness does not invoke curl's timeout action unless curl reports a due
  timer. New transfers still receive their initial kick. Both readiness backends
  remain real kernel waits.
- HTTP body/header buffers remain owned by the native job until PHP conversion.
  No intermediate generic native result tree is created. Fatal conversion leaves
  native ownership registered for request shutdown.
- Common four-field buffered options use a bounded temporary native view during
  synchronous curl setup. The view is never attached across a Zend call, suspension
  or return, and never reaches a worker. Curl copies option strings. Complex
  headers/MIME/stream options retain owned conversion. Existing validation and
  native/PHP memory boundaries remain. See the [curl method ownership contract](https://curl.se/libcurl/c/CURLOPT_CUSTOMREQUEST.html).
- Response header metadata conversion runs in C, with the original PHP fallback
  for value-only use without the new native helper. Curl still owns protocol parsing.

## Current evidence

`build/http-speed-8.so`: SHA-256
`c962050566c5587091d440a5682204ba52ad3b3d8168a0439cc73a132dfd8400`.

- `speed8-http-results/report.json`: PASS, HTTP native/PHP suite, epoll and poll.
- `gate-A/report.json`: PASS, 177.126 seconds (three seeds/both backends).
- `options-lifetime/result.json`: PASS; referenced PHP option mutation after submit,
  invalid values, 1024 accepted/cancelled jobs and rejected admission cleanup.
- `dispatch-before-run`: preserved expected assertion failure on 3fe4cf9;
  `dispatch-after-run`: PASS, real kernel readiness with controlled curl timer return.
- `headers-test`: 10,005 metadata cases match the former PHP implementation.
- `wait-observer`: 2,000 shared first-settler races and 10,000 exceptional reused waits.
- `buffered-owner-1` and the later HTTP gate cover cancellation, queued owners,
  repeated results, abandoned operations, root failure and permit collection.
- `await-full-epoll`: initial full suite PASS before final ownership-budget/option
  view changes; this is not final-source full-matrix evidence.

Other PHP SDKs, final-source full suites, memory instruments and FPM integration
are pending for this checkpoint. No older result is attributed to the new source.

## Performance evidence and next experiment

`admission-nginx`, `speed3-nginx`, `speed4-nginx`, `speed5-nginx`,
`speed5-c32-nginx`, `speed5-jit-nginx`, `speed8-poll-nginx` retain all samples.
Each comparison uses checked complete 1/64 KiB responses, persistent connections,
8,000 operations/sample, five rotated serialized samples and matched concurrency.
JIT is a separate explicit configuration for **all** PHP clients and must report
active JIT; it is not combined with no-JIT results. Raw native mode is diagnostic,
not public-client throughput. Benchmark PASS means functional execution, not that
its speed target passed. Host/relative rates vary substantially; no cherry-picked
best sample is used as acceptance.

Callgrind and strace are separate diagnostics, not speed samples. Before reusable
wait observers, the 800-request public profile executed 202,293,984 instructions
(including startup) versus 102,387,834 for curl_multi. The newer syscall profile
removed the per-request Fiber stack churn and HTTP wake writes. Perf hardware
counters were unavailable (`perf_event_paranoid=4`); host settings were not changed.

Next: evaluate a bounded Hyper integration through the same public API, only if
an end-to-end experiment demonstrates a useful advantage. The existing standalone
Rust experiment is not a PHP backend or evidence that the public target passed.
Preserve curl for unsupported experimental configurations. Do not introduce a
blocking network wait, an unbounded pool, or PHP access from native worker threads.


## Preserved new FPM failure and fixture correction

`isolated-fpm/stdout.log` and its `A-lifecycle/php-errors.log` retain a failed
post-fatal overlap/two-tick assertion. Its error did not record the individual
tick count or intervals, so exact attribution remains **UNRESOLVED**. Five
separate diagnostic repetitions passed and do not erase that failure.

Source inspection established a distinct feature-fixture mismatch: the post-fatal
request used the generic lifecycle peer's one-operation barrier while the HTTP
feature fixture submits four operations. `tests/http/fpm_barrier.py` proves that
the one-operation barrier permits nonoverlap and the four-operation barrier holds
the batch until all requests arrive. Only the additional HTTP harness now maps
that recovery probe to the four-operation barrier. The >=2-tick and interval
assertions are unchanged, and failure messages now retain the actual values.
The historical `tests/audit/fpm_request.php` and campaign artifacts are untouched.
This correction is not proof of the failed request's cause or of the historical
Phase 4 zero-tick cause.

`fpm-corrected-data-0/1/2/report.json` independently retain three passing feature
runs (60 main responses each plus fatal/recovery). `responses.jsonl` preserves
individual results even if a later check fails. The first `final-fpm` run overlapped
the tail of sanitizer validation and is functional diagnostic evidence only;
the corrected repetitions and `isolated-hyper-fpm-r2` were isolated.


### Exact PHP patch SDK guard (2026-11-09)

Gate B's first FPM attempt failed before its timed stage because PATH selected
system PHP-FPM8.5.11 for a module compiled against8.5.12. Both advertise the same
module API, but `executor_globals` is1984 bytes in the former binary and2008 in
the latter. The October delayed-OOM layout is different. An isolated DNS export
on8.5.11 returned an empty poll array; the identical module/control passed on8.5.12.
This is an actual unsafe binary compatibility gap, not a zero-tick explanation.

MINIT now compares the runtime standard-module version with the compiled PHP
version **before** curl, database or hook initialization. A mismatched binary is
rejected with a matching-SDK instruction. `asyncara_info()` reports buildPhpVersion.
The fast FPM runner preflights the actual FPM executable and records its hash;
the pipeline propagates its explicit CLI selection. Native tests and API/header
numbers alone do not establish patch-SDK compatibility. Rebuild after a PHP patch
change; matching version is necessary, not proof of arbitrary vendor ABI safety.

`sdk-mismatch-before` preserves the failing empty-array control; `sdk-matched-before`
passes. `guard-regression-r2-data` proves matching load/submission and rejected
mismatch. `fpm-sdk-r2-negative/positive` verify the runner preflight. The first
candidate guard mistakenly used the Core module's Zend4.x version and rejected
its own matching PHP8.x runtime; that failed build is preserved and corrected by
using the standard module version. No host PHP installation was changed.

The original Gate B result remains FAIL. Its B1 native-volume checks completed;
B2 did not reach the timed phase. Revalidation must select the matching isolated
FPM executable. Historical Phase4 failures remain separately unresolved.

## Matching-SDK FPM revalidation and rejected experiments

At d57d088 the matching PHP8.5.12 FPM repetition completed: Gate A193.438s PASS;
Gate B2 PASS with8092 main responses over600.094582s. Independent review recomputes
minimum6 ticks, all original A/B/C/D and recycling/reload/replacement/timeout
checks,388416 pending reads,291312 I/O completions,48552 timer firings and97104
cancellations. Four idle workers each retain6 descriptors and1 thread. Their RSS
growth beyond warmup is3648/632/592/660KiB, below the unchanged8192KiB bound.
Evidence: `guard-gate-B2-data/review/report.json`. Module SHA-256:
`1a39f9141c5e7143a03fadbb927238abcfd687fcace48408140474b6e6a7169e`.
This is short mixed-workload validation, not HTTP throughput or endurance proof.
The earlier failed run remains intact. No campaign was interrupted.

Two experiments remain on isolated branches and are **not adopted**:

- `phase6-http-native-future`,02891a61130c3286bdf408f7db4567635da09e62:
  native Future storage/listeners built, GC/cancellation/ownership tests passed
  on85 epoll/poll. Five-sample public Hyper comparison:1KiB median9290/s versus
 11676/s for the original PHP Future. No useful gain; no full certification.
  Evidence:`/home/coder/Asyncara-http-future-evidence-20261109T095500Z`.
- `phase6-http-hook-cost`,d42ad07944a1c01307a3a14a6233607b5460c6e1:
  test-only removal of the optional internal adapter also showed no gain:
  4456/s versus5172/s. Production timer hooks and policy remain unchanged.
  Evidence:`/home/coder/Asyncara-http-hook-evidence-20261109T101000Z`.

Public Hyper at concurrency128/256 also failed the target (1KiB medians8411/7679).
These negative results are retained. Callgrind of the matching native Hyper module
records117140158 instructions including PHP startup for800 measured plus80 warmup
requests; it is an instrumented cost profile, not a throughput result.

### Reference-extension identity qualification

Inspection found that earlier comparison commands loaded system ext-curl from
PHP8.5.11 and system Swoole6.2.3 containing an8.5.10 build string into the isolated
PHP8.5.12 interpreter. Completed response checks do not certify those foreign SDKs.
Their old raw rates are preserved but cannot establish certified relative speed.
Asyncara's own matching-SDK rate and failed target are not erased. The extension
loading guard only protects Asyncara, not independently loaded PHP extensions.

The runner now accepts explicit isolated reference module paths and a fingerprint
attestation, and reports functionalStatus separately from performanceStatus.
Reference package/build attempts and all failures are preserved under
`reference-env` and `reference-swoole-build-*` in the evidence root. Matching
reference builds and their new comparison must be completed before claiming a
certified relative result. No global PHP configuration or installation is changed.

### Latest matched public/raw cost separation

`raw-cost-comparison-data/report.json` uses matching PHP8.5.12/curl/Swoole,
1KiB bodies,32 persistent connections, tracing JIT, poll,16000 requests/sample
and five rotated samples. Public Hyper median14322.756/s, minimum11692.586/s;
curl_multi15130.1/s; Swoole15589.6/s. Ratios0.9466x and0.9187x. The public64KiB
median is5072.925/s. The request body/status/count checks pass but performance
acceptance remains NOT_MET. This later faster median does not remove slower
samples or the earlier matched comparison failures.

Raw-native Hyper in the same group reaches median17078.5/s for1KiB, but omits
public Task/Future/Response ownership and header metadata conversion. It is a
cost control only. The separate public batch pattern gives7917.9/s median and
also fails acceptance. Neither is substituted for the public rolling-worker
API. The separate threaded capacity control below is not an implemented PHP backend.

## c4aea94 validation (completed)

- PHP8.3.36/8.4.27/8.5.12 NTS HTTP feature suites: PASS, epoll and poll,
  including TLS/proxy, uploads, multipart, atomic downloads, backpressure,
  cancellation, URI validation, pool/permit ownership and the opt-in prototype.
- Full PHP8.5 suites: PASS,33.657s epoll and28.770s poll.
- Matching DEBUG targeted controls: PASS, both backends.
- GCC ASan/UBSan/LeakSanitizer native/PHP HTTP controls: PASS, no suppressions.
  Rust machine code remains outside GCC sanitizer instrumentation.
- DEBUG Valgrind completion, Hyper and buffered-owner controls: zero errors and
  zero definite/indirect/possible lost bytes;3994 bytes still reachable in the
  prototype/owner processes. No universal memory-clean claim or change to the
  older distribution-PHP discrepancy.
- Gate A: PASS,174.69s inner gate, three seeds and both backends; unchanged
  negative controls still reject invalid progress.
- Separate curl and Hyper FPM fixtures: PASS, each60 main requests plus fatal/
  recovery lifecycle checks with one and four workers. Original >=2-tick and
  pending-I/O assertions remain. These are short fixtures, not endurance passes.
- PHP8.5 candidate module SHA-256:
  `1c0842dbfe058d93d5f82fe7f36b960e908febe361c4d7ea67fbb3948ab2b11b`.

All commands, SDK variants, source manifest and raw log hashes are retained under
`wait-final-*` in the evidence root and summarized in phase6/http-current-evidence.json.
The d57d088 ten-minute B2 remains historical evidence for its own binary; it is
not relabelled as a c4aea94 run. New package validation is recorded separately.

### Threaded Rust capacity control — not adopted

`hyper-thread-capacity/measurements/report.json` preserves a Rust-only experiment
with four isolated nginx workers,32 connections,16000 checked responses/sample
and five rotated samples of1/2/4 Tokio workers.1KiB median rates are10923.1,
18941.5 and14917.0/s respectively. Median total client CPU is73.52,72.22 and
91.60us/request.64KiB medians are4841.0,3933.5 and6265.0/s, with175.07,292.72
and277.05us CPU/request. The changed source, pinned lock, binary hash, commands
and all samples remain in the evidence directory. No C/Zend/runtime source was
changed for this control. It does not establish a4x/3x gain, a stable13k floor,
or public PHP performance. Adding Rust execution threads to Asyncara is not
justified by this control; existing request-local ownership remains unchanged.

## Reproducible http-r2 distribution

Packaged source: `ba9200f9e71e5247d6ef8d202dd37393fb4e577c`.
Native implementation: `c4aea948233d79aee887c9ceeea4b56da340cc57`.
Archive: `build/asyncara-0.1.0-dev-http-r2.tar.gz`,486 files.
SHA-256: `b9fae9987f2e564c2db6971577519900bbc3305cbf90cc6ad5a51f9792b034d8`.
Two independent archive creations match byte-for-byte. Every entry matches the
packaged Git tree; LICENSE bytes are unchanged. Clean extraction builds default
C modules and passes HTTP feature suites on PHP8.3.36/8.4.27/8.5.12, both backends.
The optional Hyper build uses a new Cargo target directory and passes the HTTP/
prototype gate on8.5.12. No previous module, generated project file, vendor tree
or test certificate is copied. Public SDK/library headers and pinned third-party
sources are build dependencies. Debug-path differences mean this is not a claim
of bit-identical compiled modules. No GitHub package or release was published.
[Full attestation](phase6/http-r2-package.json) records every build/test command,
module hash, log hash and exact scope. The following documentation-only commit
records this completed attestation and is deliberately outside the archive it
attests. The old http-r1 artifact is unchanged.

The reference-attestation negative control rejects an altered PHP hash before
starting a server or client. Functional success and performance success remain
separate. Final performance status is NOT_MET; the release is EXPERIMENTAL.
No source change in this continuation proves the historical Phase4 zero-tick
cause or resolves the separate distribution-PHP memory discrepancy.
