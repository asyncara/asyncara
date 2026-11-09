# HTTP public-path performance investigation

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
