# Native resource safety and bounded provenance

Release: **EXPERIMENTAL**. This continuation starts from
`0a45ca590419a08b7fe49fd15f66590dc58e81bf` on `phase5-fast-reliability` in
`/home/coder/Asyncara-fast-reliability-20261107T155547Z`. The protected Phase4
checkout is separate. Raw evidence, exact commands/environments, PID launch
records, terminal exits, failures, hashes and resumable journal live in:
`/home/coder/Asyncara-resource-provenance-20261107T212909Z`.

## Source provenance

The starting archive `asyncara-0.1.0-dev-native-program-r2.tar.gz` has 416 files
and SHA-256 `7eed7583e77165fcd7e0e554e4d067d61a70fbffdfbd590355b47106308bc26a`.
Every file was compared with packaged commit `7ffeaf8`; tested native `9bee5c3`
and final documentation `0a45ca5` are distinct. Starting module `program-r2.so`
has SHA-256 `ed61af9fcb8e871f6e9cbdd929697d66080aa2310f5d207629738e433abe08c1`.
`initial.json` records those checks, source manifest and protected artifacts.

| Checkpoint | Change |
|---|---|
| 5198f38 | Reject copies of canonical resource/state owners; preserve tested aliases/value copies |
| 9c8c369 | Bounded native ring and PHP scheduling provenance; diagnostic tests |
| 18da2e3 | Reject duplicate TcpStream constructor ownership without strong retention |
| b7ea081 | Bounded watcher index, early count validation, extraction completion and scoped memory/ownership findings |
| ce9b977 | Additional partial-output/flush-failure regression; runtime identical to b7ea081 |

Current validation module `build/resource-provenance-r2.so`:
`16fdd80136f45d3b3f6c7082bc1a0b8745411fc49c77067318e6646e0b7ca29b`.
Final validation and package attestations below distinguish source, test,
binary and distribution identities. No publication or history rewrite is part
of this continuation.

## Resource ownership

The complete object matrix is [NATIVE_OWNERSHIP_AUDIT.md](NATIVE_OWNERSHIP_AUDIT.md).
The operational contract is [IO_LIFECYCLE_CONTRACT.md](IO_LIFECYCLE_CONTRACT.md).
Policies differ intentionally: canonical owners reject cloning and serialization;
SQL statement descriptors, Emitter, CancellationSource and the PSR adapter clone
as aliases of a canonical owner but reject serialization; response/message/parser
value state remains copyable. Interface implementors and application values are
not assigned an unsupported universal policy.

Before correction, a cloned TcpStream destructor closed the original stream;
a cloned Event could settle the same Future twice. Separately, constructing a
second wrapper around the same PHP stream also closed the first wrapper's
resource on destruction. Negative controls are retained in `copy-negative`,
`copy-before`, and `duplicate-wrapper-before`. The weak owner registry introduces
no strong lifetime extension. `resource_copy.php` checks descriptors, child
processes, pending native jobs/handles/memory, alias behavior, shared cancellation,
forged unserialization, repeated close and dropped references during file work.
Live PostgreSQL/MariaDB/Redis checks use `tests/databases.php`.

Cancelling `Future::await(token)` removes that waiter, not the shared operation.
An operation's explicit cancel authority is different. Eager jobs remain owned
by the scheduler/request after losing their last user reference; collection or
request shutdown releases them. File/SQLite wrappers require explicit close;
there is no new promise of last-user-reference rollback. Workers join before
engine allocations disappear. Raw resource escapes, external fclose, arbitrary
Reflection state mutation, hard process termination and unbounded foreign-library
shutdown are outside these guarantees.

## Bounded diagnostic architecture

[DIAGNOSTICS.md](DIAGNOSTICS.md) defines schema1, event codes and limitations.
`ext/memory.c` owns a request-local ring; `ext/native.h` defines fixed 64-byte
records. Atomic try-lock admission never waits or spins; contention drops are
counted. Capacity 16..4096 is immutable after allocation; the oldest event is
replaced on wraparound with visible overwrite count. Maximum ring storage is
262,216 bytes on this ABI, separately reported from payload memory. Fixed
metadata adds 32 bytes to each request engine and 24 bytes of process/thread
identity counters (262,272 bytes total additional native storage for one NTS
engine at maximum capacity, excluding allocator bookkeeping). Extraction
uses a 262,144-byte temporary and bounded PHP conversion/JSON, outside dispatch.

Producers record numeric metadata only: monotonic timestamps, sequence, PID,
request identity, scheduler scope, job/task/timer/watch IDs, deadlines, readiness,
wait duration/result/errno, queue counts and byte counts. No application payload,
SQL, path, URL, credential, header, zval or pointer is retained. Workers never
access Zend. Disabling capture retains storage through worker joins. No file or
network logging occurs in native wait/scheduler paths; the caller explicitly
extracts after Async::run. Failed snapshot admission is EBUSY; failed/truncated
output throws and does not consume the ring. Existing output files are preserved.

Events cover wait planning and actual kernel entry/exit, task queue/dispatch,
Fiber suspension/resumption, timer registration/due/dispatch/removal, watcher
registration/readiness/close, worker submission/production/notification/collection,
HTTP pause, lifecycle and numeric anomaly markers. Selected event classes omit
others deliberately. Clock failure and capture loss remain visible. A marker
can freeze capture; there is no speculative automatic scheduler intervention.

Native shutdown entry is recorded but there is no post-RSHUTDOWN export. PHP
shutdown callbacks extract before native teardown and cannot certify later joins.
Hard OOM/crash/SIGKILL can prevent export. A bounded trace is not a crash recorder
and cannot explain an event it never recorded.

## Zero-tick investigation

Historical one-hour supervisor PASS, six-hour FAIL (13,501.386592 seconds,
196,536 persisted responses, zero ticks) and 24-hour NOT_STARTED remain unchanged.
The independent hour review's chronology limitation remains INDETERMINATE even
though workload/resource assertions passed. Earlier 364.506/998.680 failures
remain retained. None is retroactively tested by this source.

| Hypothesis | New evidence | Conclusion |
|---|---|---|
| Timer dispatch occurs only after I/O completes | Kernel peer is held until two timer markers, then released; trace verifies pending read and later completion | Required progress invariant passes on tested paths |
| Wrong wait deadline/setup budget | Existing injected preparation/rounding regression plus recorded PHP/native deadlines | Existing correction remains covered; not historical attribution |
| Lost worker notification on EINTR | Native injection records interruption, retry, notification and collection | Prior specific native fix independently covered |
| Interpreter fails to enter native wait promptly | Separate 300ms test-only PHP polling delay; marks bracket gap before native entry, then zero ticks | Controlled attribution proves diagnostic usefulness, not historical cause |
| Host scheduling delay, missed readiness, ownership or dispatch race in original request | Original record lacks per-wait trace and failed request identity | Historical cause remains unresolved; cannot distinguish these retrospectively |

`final-trace-attribution.json` ties the controlled delay to exact event sequences.
Normal and recovery controls require at least two ticks; delay control deliberately
fails the original progress condition. Diagnostic and uninstrumented runs remain
separate. Passing short tests never becomes six-/24-hour endurance certification.

## Distribution-PHP memory investigation

[DEPENDENCY_FINDINGS.md](DEPENDENCY_FINDINGS.md) records full differential results.
Valgrind 3.22.0, PHP 8.5.12, USE_ZEND_ALLOC=0, definite/indirect error-exit 99, no
suppressions. Plain object cycles lose 8,480 bytes identically with extension absent
or loaded idle; PHP-only Fiber-created cycles behave the same. Explicit collection
in separate controls and matching DEBUG PHP eliminate those losses. Plain Fibers
without cycles and raw native worker collection have zero losses.

The rich current PHP workload produced 1,784/648 direct/indirect bytes with the
trace module (two repetitions), 1,888/120 with the prior module; old PHP source gave
1,128/0 with old module and 752/120 with new. Lost stacks point to Zend objects,
Fibers and closures, with no native ring/payload loss identified. PHP source shows
DEBUG-only shutdown GC and deliberate object-store shutdown behavior. This proves
a PHP shutdown-cycle contribution, not identical application retention across
builds. Per-object differences remain unresolved. Distribution runs remain FAIL.
No production GC workaround was introduced. Scoped native/DEBUG cleanliness does
not establish that all Asyncara allocation paths are leak-free.

## Further native improvement

`ext/backend.c:wait_impl` previously scanned all prior descriptors for every input
watcher. The new temporary open-address index is used at 32 or more watchers;
small sets retain the existing scan. It merges duplicate interests while
preserving first-seen descriptor order, original watcher dispatch order and the
absolute wait deadline. Maximum additional control storage 131,072 bytes; the
same allocation/error cleanup path frees it every wait. Average lookup improves;
no hard collision-free complexity guarantee is claimed.

An oversized native watcher count now fails before dereference or arithmetic.
The previous native control crashed; PHP already enforced the count limit.
`watch_index.c` tests shuffled duplicate read/write interests, partial readiness,
both backends, allocation failures/recovery and oversized counts. Five matched
samples of 20 actual waits across 16/128/512/2048/4096 unique descriptors are in
`watch-comparison.json`. At 4096 descriptors: 9.63x poll and 1.54x epoll median
improvement. This isolates wait setup/readiness cost, not end-to-end throughput.
The benchmark raises only its child FD limit; it changes no host configuration.

## Validation and remaining limits

Final-source Gates A/B/C and their independent reviews passed. The matrix and
scoped native/DEBUG tools passed; distribution-PHP Valgrind remains FAIL. The
terminal evidence is recorded below and indexed in the validation attestation.
Historical artifacts and current failure logs are immutable. All source changes
stay in the independent Phase5 worktree. Native APIs remain primary; no new hooks,
io_uring, protocol, framework or userland-thread execution is introduced.

## Pre-gate verification on the current runtime

`final-matrix-data/results.json`: 38 commands, all expected outcomes, PHP 8.3.36,
8.4.27 and 8.5.12 on epoll/poll. Independent early-I/O negative oracles still fail
as intended. Copy-policy and trace tests additionally pass on all six combinations;
deferred-output truncation/flush recovery also passes all six. Full PHP 8.5 suites
and local PostgreSQL 16.15/MariaDB 11.8.9/Redis integrations pass on both backends.
Oracle MySQL, remote TLS, ZTS and other operating systems were not rerun here.

GCC ASan/UBSan: native ring, index, count bounds, allocation failure, wait budget,
EINTR, SQLite conversion and lifecycle; instrumented PHP/extension lifecycle,
copy policies, bounds, trace and 1,000-iteration kernel controls pass epoll/poll.
TSan ring publishers and native workers pass with process-local setarch only.
DEBUG PHP and unsuppressed DEBUG/native Valgrind controls pass. The final normal
distribution module still reports 1,784/648 lost bytes, 0 possibly lost, 77,515 reachable,
exit 99 (`final-distribution-memory.log`). Raw native worker control has zero loss.

A separate final diagnostic FPM run passes A/B/C/D with 136 distinct request traces,
including fatal/recovery, 66 contention drops and no wraparound. This is not an
uninstrumented acceptance pass. Five alternating overhead samples/backend are
documented in DIAGNOSTICS.md, including CPU time, memory, event loss and observed
host contention.

The first complete independent audit attempt failed the unchanged ProcessPool
CPU/wall >1.1 assertion (observed 1.09165). Both worker PIDs overlapped. A concurrent
benchmark in an unrelated checkout was observed; no process was signalled or host
setting changed. Three paired prior/current module controls passed, and a new full
audit passed unchanged, including all slow-I/O/fatal/cleanup assertions. The
original FAIL remains in `final-audit-data`; `cpu-controls-summary.json` and
`cpu-contention-observation.json` retain measurements. This does not establish
the historical zero-tick cause or guarantee CPU availability.

## Native implementation and invariants

| C file / functions | Previous behavior | Change / invariant | Executable evidence |
|---|---|---|---|
| `ext/native.h` | No bounded provenance state | Fixed numeric event/schema and request-engine ownership; no Zend objects in workers | ring layout/capacity/concurrency tests |
| `ext/memory.c:ar_trace_configure/ar_trace_emit/ar_trace_read/ar_trace_clock` | No event recorder | One bounded allocation, nonwaiting producer admission, monotonic sequence, visible loss, deferred value snapshot; retain storage through joins | ring.c, trace.php, output.php, TSan/ASan/Valgrind |
| `ext/php_asyncara.c:get_engine/PHP_RINIT/asyncara_info/asyncara_trace_*` | Metrics only | Request identity, diagnostic byte counter, validated configuration and conversion after releasing native admission | six PHP/backend combinations, DEBUG, fatal diagnostic FPM |
| `ext/workers.c:ar_submit/ar_done/ar_collect/ar_cancel/ar_job_free/ar_engine_free` | Completion ownership without provenance | Record transitions/notification errno; copy job ID before publishing so logging never dereferences a collected job; no changed completion authority | injected EINTR/EAGAIN, native workers, allocation/fatal cleanup, TSan |
| `ext/backend.c:wait_impl/wait_fds/ar_wait`, HTTP callbacks | Wait/readiness without bounded chronology | Numeric entry/return/deadline/error and pause records, no output or timeout/order changes | native_wait, wait_budget, real kernel pending-read traces, diagnostic controls |
| `ext/backend.c:wait_impl` | Quadratic descriptor merging and late native count check | Bounded half-full temporary index for larger sets; early invalid count rejection; free buffers on every path | watch_index bounds/allocation/duplicate readiness and five-sample matched benchmark |

Scheduler/Task/TcpStream PHP changes add numeric events only when enabled and
explicit identity policies. Scheduler dispatch ordering, timer arithmetic,
cancellation authority and native completion semantics are preserved. Existing
EINTR retry and accepted-close behavior are inherited and independently covered;
neither is presented as the original zero-tick repair.

## Reproduction commands

From the isolated checkout, with the matching local PHP/toolchain paths in the
attestation (or equivalent public development headers and system dependencies):

```sh
PHP_BIN=/path/to/php8.5 ASYNCARA_OUTPUT=build/resource-provenance.so ./scripts/build-local.sh
python3 tests/fast/validate.py --variants corrected --php 83 84 85 --output /new/matrix
python3 tests/fast/gate_a.py --php /path/to/php8.5 --extension "$PWD/build/resource-provenance.so" --output /new/A
FPM_BIN=/path/to/php-fpm8.5 python3 tests/fast/pipeline.py B --php /path/to/php8.5 --extension "$PWD/build/resource-provenance.so" --output /new/B
FPM_BIN=/path/to/php-fpm8.5 python3 tests/fast/pipeline.py C --php /path/to/php8.5 --extension "$PWD/build/resource-provenance.so" --prior-a /new/A --prior-b /new/B --output /new/C
python3 tests/diagnostics/run.py --php /path/to/php8.5 --extension "$PWD/build/resource-provenance.so" --output /new/diagnostics
```

B/C are uninstrumented; run the separate diagnostic FPM workload only after they
finish. `final-validation.py`, `final-gates-a.py`, `final-gates-bc.py` and
`memory-controls.py` in the evidence directory retain actual executed commands,
including compiler flags, SDK paths and unsuppressed Valgrind controls. All
outputs use new paths. The build helper expects a checkout-relative output path.

## Unresolved scope and next engineering priorities

1. Attribute each rich-workload PHP shutdown object to its owner/reference cycle;
   retain distribution losses until that attribution is complete. Do not add
   production collection merely to change Valgrind output.
2. Use bounded traces in separate reproductions if zero-tick behavior recurs.
   Lost/overwritten events and instrumentation timing effects limit attribution.
3. The large-set benchmark still includes per-wait epoll creation/registration.
   Investigate persistent registration only with measured benefit and proven
   descriptor generation/cancellation safety; no speculative rewrite here.
4. Hard shutdown deadlines, foreign-library allocation caps, remote database
   variants and untested platforms remain outside this scoped verification.

A completed object audit is not universal lifecycle safety. Clean scoped tools
are not a complete heap proof. New short gates are not long-duration endurance.

## Preserved unsuccessful experiments

The evidence directory retains all original failures and corrections:

- `copy-before`, `copy-negative`, `duplicate-wrapper-before`: actual unsafe owner
  copying/duplicate ownership before correction.
- Initial trace-FPM extraction: EBUSY after outstanding file work; bounded
  deferred-fixture retries were added, never a change to the original acceptance
  fixture. Contention remains observable and export is not guaranteed.
- `watch-bounds-before`: native invalid-count control segfault; early rejection
  now passes. PHP's public wrapper already enforced the input bound.
- Initial 2,048-descriptor benchmark: process FD limit4096 exhausted during pipe
  setup. The repeated matched benchmark uses child-only FD limit16384 for both
  baseline and candidate; no global resource settings were changed.
- `final-watch-allocation`: erroneous test alias of the private engine wake FD;
  corrected test uses an independent pipe and retains identical failure/cleanup
  assertions. It passes both backends and memory tools.
- `final-module-build`: invocation used an absolute output path with a helper
  that expects a relative path. Its compiled artifact/log are retained; a new
  filename and correct invocation load successfully. No source workaround.
- `final-independent-audit`: CPU ratio assertion failure described above; retained
  alongside matched controls and unchanged full rerun.
- Distribution-PHP unsuppressed Valgrind failures remain failures, even where
  independent controls explain a shutdown mechanism.

No failed result is removed from the evidence index or converted into a pass.
Passing reruns apply only to their recorded source/configuration.

## Gate A and B terminal evidence

Gate A: PASS161.402964s wall /157.797869s child CPU,44 commands,three seeds,
10,000 iterations/path,epoll/poll. `final-a-data/report.json` records commands
and hashes. Gate B: PASS765.869168s wall /565.524949s child CPU. B1 records
350,004 real kernel waits plus210,000 worker jobs; operation definitions and
per-checkpoint native/scheduler/descriptor/thread/RSS measurements are retained.

B2: 9,376 main responses /600.013156s mixed workload
(15.626/s). All 7,032 I/O responses satisfy
the original two-tick assertion; observed minimum9. Dense work
records450,048 pending reads,337,536 completions,56,256 timer firings,112,512
cancellations,900,096 endpoint closes and1,472,451 scheduler task steps. These
are separate counters, not additional HTTP responses. Seeds17/271828/104729
each executed.

Independent `final-b-data/review/report.json` verifies contiguous request IDs,
overlap/progress, source/binary/fixture hashes, zero post-operation owners,
all lifecycle cases and recorded FPM master exits. It recalculates RSS from
2,342 steady samples per worker. Beyond-warmup growth:644/2532/528/3308KiB;
ranges after first three samples:440/240/240/432KiB; least-squares slopes:
1016.041/237.935/639.983/563.320KiB/hour over this short observation. All four
workers have six idle descriptors and one thread; original growth limit8192KiB
is unchanged. Positive short-run slopes are not extrapolated into a leak claim
or long-duration guarantee.

A/B/C/D, recycling, graceful reload, forced replacement, fatal/pending work and
request-timeout replacement pass. Within-batch chronological order is still not
independently recorded; no clock-rollback certification is asserted. Helper
A/B/C/D master exits are not separately persisted by the inherited runner; the
subsequent three owned master exits are recorded as zero.

Gate C ran only after A/B prerequisite identity checks. Runtime/test source and
the selected module remained frozen throughout all three gates.


## Gate C, final matrix and preservation

Gate C: **PASS**, 2,033.493820 seconds wall / 1,296.405927 seconds child CPU.
Real-kernel prechecks use two seeds (65537/999983) with 25,000 iterations each
per backend, followed by 420,000 worker jobs across the resource tests. The
uninstrumented four-worker FPM workload completed **27,636 main responses in
1,800.070865 seconds** (15.352729/s). All 20,727 I/O requests satisfied the
original two-tick requirement; minimum six, maximum 102. Three arrival seeds
contributed 9,212 requests each. Dense operations were 1,326,528 pending reads,
994,896 I/O completions, 165,816 timer firings, 331,632 cancellations, 2,653,056
endpoint closes and 4,326,194 scheduler task steps. These counters overlap in
meaning and must not be summed into an invented HTTP-response count.

Independent review recalculated 6,907 idle samples for each steady worker:

| Worker PID | RSS min/max KiB | Beyond warmup KiB | Range after first 3 KiB | Linear slope KiB/hour | Idle FD/threads |
|---|---:|---:|---:|---:|---:|
| 3193322 | 13968/18004 | 3300 | 4036 | -11173.205 | 6/1 |
| 3193290 | 15540/19832 | 572 | 4292 | -11826.642 | 6/1 |
| 3193291 | 15488/19776 | 596 | 4288 | -11884.957 | 6/1 |
| 3193292 | 15608/19856 | 544 | 4248 | -11739.619 | 6/1 |

All remain below the unchanged 8,192 KiB growth limit. RSS falls during this run;
the raw observations do not establish the allocator/OS cause. Neither a negative
slope nor bounded RSS alone proves absence of leaks. Sample timestamps are
monotonic; within-batch request chronology still lacks an independent ordering
source. The review makes no clock-rollback certification.

All A/B/C/D, recycling, reload/replacement, fatal/pending-work cleanup and timeout
checks passed. Three captured owned master exits were zero. Original A/B/C/D
helper exits are not separately persisted. `terminal-log-process-review.json`
checks every recorded owned PID is absent and classifies the retained log errors:
only deliberate fatal allocation failures, forced SIGKILL and request-timeout
termination appear. It also preserves RSS quartiles. No orphaned campaign was
left running. FPM local log timestamps are Europe/Berlin (UTC+1); PHP error logs
label UTC explicitly. Durations use monotonic clocks.

| Validation | Actual result / scope |
|---|---|
| Gate A | PASS, 161.403 seconds, three seeds, epoll/poll |
| B1 | PASS, 350,004 real kernel waits + 210,000 worker jobs |
| B2 | PASS, 9,376 responses / 600.013 seconds, minimum nine ticks |
| Gate C | PASS, 27,636 responses / 1,800.071 seconds, minimum six ticks |
| PHP 8.3.36 / 8.4.27 / 8.5.12 NTS | PASS, critical regression/copy/trace/output tests on epoll and poll |
| Full PHP 8.5 epoll / poll | PASS |
| Local PostgreSQL / MariaDB / Redis | PASS, both backends; SQLite also covered in core/native tests |
| GCC ASan/UBSan | PASS, scoped native and instrumented PHP paths |
| Native TSan | PASS, ring and worker publishers |
| DEBUG PHP / native Valgrind | PASS, scoped checks, zero reported losses/errors |
| Distribution-PHP Valgrind | FAIL, 1,784 definitely / 648 indirectly lost bytes in final rich workload; per-object attribution unresolved |
| Separate diagnostic FPM | PASS, 136 traces; 66 visible contention drops, no overwrite |
| Independent audit | First FAIL retained; three matched controls and unchanged full rerun PASS |
| Historical zero-tick attribution | INDETERMINATE; no retrospective trace exists |
| Historical one-hour / six-hour / 24-hour FPM | PASS / FAIL / NOT_STARTED, original source only |
| Oracle MySQL, remote TLS variants, ZTS and other OS certification | NOT_STARTED in this continuation; no prior results reassigned |

A+B total 927.272 seconds (15.45 minutes); C adds 33.89 minutes. All three gates
total 49.35 minutes on this machine. The normal A+B pre-merge path fits the
45-minute target; including optional C exceeds it. Sanitizers, toolchain builds
and package verification are additional scoped validation, not hidden in those
timings. No multi-hour campaign or endurance claim was substituted.

The complete command/result index and artifact hashes are
[resource-provenance-validation.json](phase5/resource-provenance-validation.json).
SHA-256: `45bfe6fe78cf0734b21ad14b8893f8d1b6ed5181e9a0f77daa4cdbec00faabdc`. Raw evidence remains under the directory
at the beginning of this report. It includes the failed experiments unchanged.
`final-source-identity.json` records the native source manifest, PHP/compiler/OS
and module fingerprints; `fixed-diagnostic-layout.json` measures fixed overhead.

`final-preservation.json` rechecks the protected Phase4 checkout, its unchanged
three untracked reviewer files, 12 protected artifacts, previous source/module
and the historical 691,517,440-byte campaign archive. The campaign archive SHA
remains `ecff33b16db232ffd81e9f219f2cac87d3aef71bbb74a8ad1abdccaf573919b4`.
LICENSE and Composer metadata/lock remain unchanged from the starting source.
This verification does not claim all unrelated user files were audited.

The implementation, scoped validation and independent package checks below are
complete. Unresolved findings remain explicit and the release is EXPERIMENTAL.


## Reproducible package and independent builds

Packaged clean source: `63a3a496b7892a7d0e699517a05fa394ae6ebbc9`. Runtime remains `b7ea081`, tests
remain `ce9b977`; this packaged commit adds documentation/evidence only.
Archive: `build/asyncara-0.1.0-dev-resource-provenance-r1.tar.gz`, **435 files**.
SHA-256:

`d3cf2f58cebfcef6e4a0148d0c2b7a6f9dc5d20a6d0819a44b197ff16ea2f176`

Repeated generation is byte-identical. Every member matches the packaged Git
commit, including LICENSE and Composer lock. A clean extraction built independent
PHP 8.3.36/8.4.27/8.5.12 modules from public SDK headers and system libraries,
without reusing any generated module, vendor tree or build output. Composer
installed six locked dependencies using an independent cache/home. Validation
passed; its only warning is root-version fallback outside Git.

All six PHP/backend combinations pass real-kernel, SQLite encoding, lifecycle,
copy/alias, bounded trace and output-failure regressions. Full PHP 8.5 suites pass
on epoll and poll. All 45 independent build/test/setup commands exited zero and
every packaged source file is unchanged after execution. The package report is
`/home/coder/Asyncara-resource-provenance-20261107T212909Z/package-r1/report.json`.

| Independently compiled module | SHA-256 |
|---|---|
| PHP 8.3 | `6967d19bb0899732911ce17900283e06d3ac4962781aff79ccdfb3a772ab91db` |
| PHP 8.4 | `c186dd26560b7ff736f337aa8e19520779bd1b6d546d5c868eb7eccca62914e0` |
| PHP 8.5 | `6eebc1e9ab0c849856f1e08bc56e953f9fa1f21f0350c9740e4610bbdf6554c4` |

The [package attestation](phase5/resource-provenance-package.json) records exact
commands, module identities, log hashes and preserved artifact checks. Its SHA-256
is `5301b6b9b9310ebaeb2910d50bf39d4b83f36ce4904d3f17a158eac6080a544d`. Native build paths/debug information differ from the gate binary;
these independent builds do not claim another Gate A/B/C execution.

Final attestation commits follow the packaged source and change documentation
only. No archive was overwritten; no Phase4 file, historical result, license,
Composer dependency or remote history was changed. No GitHub push/release was
performed. **EXPERIMENTAL** remains the final release assessment.
