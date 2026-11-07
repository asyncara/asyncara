# Native reliability program

Release: **EXPERIMENTAL**. Native Async API First; no hook/backend expansion.
This continuation starts at `f2c0b83be64597db994e2a9b3df6383c197fd540`.
Evidence root: `/home/coder/Asyncara-native-program-20261107T173550Z`.
`initial.json` verifies the previous 399-file archive byte-for-byte against
`7c0fbda`, its published checksum, the current module and twelve protected files.
Phase 4 remains unchanged. `state.json` and `journal.md` record execution.

## Historical zero-tick investigation

The original one-hour PASS, six-hour FAIL (196,536 persisted main responses),
24-hour NOT_STARTED and old 7,980-response INCOMPLETE gate retain their statuses.
The failed request has four overlapping approximately 200ms peer intervals,
430.299ms elapsed, 125.596ms scheduled CPU, 97.937ms runnable wait, three schedule
slices, 138.280ms maximum task step and 276.595ms maximum timer lag. These aggregate
measurements do not locate the missing dispatch interval. No original per-wait
requested/actual timeout and surrounding execution trace exists.

| Hypothesis | Evidence and control | Conclusion |
|---|---|---|
| Worker wake notification lost after EINTR | Existing wrapped-write negative controls fail before 8263fcd, pass after; workers mask asynchronous signals; finite timer still bounds a missed pipe wake | Separate proven injected-error defect, not historical attribution |
| Cancelled close skips cleanup | 6ee73d8 negative controls; modifies worker cleanup only | Separate resource correction, does not change HTTP/timer dispatch |
| Broken timer heap or pending-read dispatch | Existing virtual scheduler and real-kernel two-tick barriers, multiple seeds and PHP versions | Tested paths pass; cannot exclude every interleaving |
| Interpreter unavailable while HTTP completes | `dispatch_control.py` r2 holds four HTTP operations behind a peer-arrival handshake, then injects 300ms interpreter delay before the real native wait: zero ticks on both backends; normal/recovery controls pass | Demonstrates the exact symptom without changing production code. Diagnostic counterexample, not proof of the historical cause |
| Host descheduling | Original scheduled/runnable totals and separate owned-child stop control (one tick in eight trials) | Plausible contributor; source of original delay remains unresolved |
| Native wait preparation restarts timer budget | `wait_budget.c` advances a test-only monotonic clock during allocation/epoll registration, still executes real kernel waits. Original requests another 5ms after budget expired | Confirmed additional delay defect; corrected here. No evidence that this accounts for the original 276ms lag |

Historical evidence is preserved under
`/tmp/asyncara-lifecycle-20261106T231254Z/fpm-uninstrumented-r1` and in
`/home/coder/Asyncara-fast-evidence-20261107T155547Z/phase4-original-evidence.tar`
(SHA-256 `ecff33b16db232ffd81e9f219f2cac87d3aef71bbb74a8ad1abdccaf573919b4`).
The one-hour supervisor reports PASS: 3,600.126 seconds, 48,124 main responses.
Its separate corrected review at
`/tmp/asyncara-review-ordering-20261107T013022Z/hour-review-r1/report.json`
(SHA-256 `35a158267c6d483f2334441371a554539c3247ec4e404a03496a9c5acc2c38e1`)
remains overall INDETERMINATE because independent within-batch worker chronology
was not captured; its workload/resource checks pass. This program does not
rewrite that review into an unqualified independent PASS.

The six-hour stage failed after 13,501.387 seconds. Its `fpm-21600.log` and
`fpm-21600/mixed-reload-kill/php-errors.log` retain the zero-tick diagnostic.
The failed batch was not appended to response JSONL, and the failure line lacks
request ID/PID. The last persisted response count is not a reliable identity for
the failing request. Exact per-wait timestamps, requested/actual wait duration,
readiness dispatch order and interpreter execution intervals cannot be recovered
from those aggregates. A native repair is therefore not attributed to that
historical failure. The 24-hour stage never started.

The PHP scheduler processes readiness/completions before the next timer dispatch.
If interpreter execution resumes after both deadlines and HTTP completion,
producer Tasks can complete before the ticker checks its pending predicate.
Moving timer priority solely to force a tick would change semantics and would
not recover real progress during the missed interval. No such change is made.

## Native corrections

`ext/backend.c`: `ar_wait`, `wait_fds`, new private deadline helpers preserve an
absolute monotonic deadline from native entry through preparation. Remaining
milliseconds round upward at the actual syscall boundary. Zero/infinite waits,
completed-worker and buffered-stream immediate returns remain unchanged. Curl's
own shorter deadline is bounded separately. Clock failures propagate with errno;
transient buffers and epoll descriptors are released. Large timeout conversion
is clamped, arithmetic overflow rejected. No polling loop, sleep or worker added.

`tests/fast/wait_budget.c`: original negative control exits 2 (expected 0ms,
actual 5ms); corrected 10,000 iterations per backend pass, covering elapsed
allocation and epoll preparation, rounding, zero/infinite and INT_MAX budgets.
Test-only clock/link wrappers are absent from the extension. Existing real-time
pending-I/O tests separately validate actual kernel waits. An earlier Gate A checkpoint
passed in 169.777s; complete final-source results are recorded below.

## Revised gates

`tests/fast/pipeline.py A|B1|B2|B|C --php PATH --extension PATH --output NEW_DIR`.
Set `FPM_BIN` to the isolated PHP-FPM binary for B/B2/C. Every output directory
is unique; active process command/PID, source manifest, binary checksum, command
exit, wall/CPU time and log hashes are recorded. C requires `--prior-a` and
`--prior-b` directories with matching source/module and completed PASS results.

B1 counts actual kernel wait calls at test linker boundaries, changing real
completion queues, readiness, partial reads, descriptor reuse and HUP. Injected
EINTR returns and virtual clock calls are excluded. It also runs three seeded
PHP kernel workloads/backend and file/SQLite cancellation/cleanup cycles.
PHP timer/Fiber transitions are reported separately from native waits.

B2 uses the historical A/B/C/D fixture unchanged, then a derived fixture with
additional real socket/timer/cancellation work inside each HTTP request. All
original statements and assertions remain, including four HTTP operations,
200ms peer delay, two ticks while pending, overlapping intervals, fatal/pending
shutdown, request isolation and resource thresholds. Added density contributes
64 pending reads, 48 completions, 16 cancellations, 8 timer expirations and 128
endpoint closes per I/O request. These are not additional FPM responses.
Arrival order cycles three deterministic seeds; resource summaries are
independently recalculated from raw samples. Default duration is 600 seconds.

C repeats larger kernel/resource workloads and 1,800 seconds of real FPM after
A/B pass, to observe residual growth/scheduling behavior. It is not 24-hour
endurance certification. A short smoke pass does not complete B2 or C.

## SQLite conversion correction and rejected intermediate candidate

`ext/workers.c:do_sqlite` now checks nullable SQLite column names and conversion
results before dereference/copy. It checks SQLite's error immediately after a
suspect return, frees the partial row and finalizes the statement. Tests cover
connection reuse and zero Asyncara-tracked memory after close. This remains
worker-backed SQLite; no Zend API is invoked on workers.

The first repair (`f470ea9`) passed its initial suites but a targeted UTF-16
semantic check found that taking a blob pointer before its byte count changed
TEXT encoding. The PHP regression returned UTF-16 bytes for a UTF-8 API result.
The final correction (`9bee5c3`) selects `sqlite3_column_text` for TEXT and
`sqlite3_column_blob` for BLOB before measuring length, preserving UTF-8 text and
raw binary data. This follows SQLite's documented conversion/error interfaces.
[SQLite result accessors](https://www.sqlite.org/c3ref/column_blob.html).

`tests/fast/sqlite_conversion.c` injects documented accessor failures into real
worker execution, including partial-row cleanup. `sqlite_encoding.php` covers
UTF-8/UTF-16le/UTF-16be databases, multibyte characters, embedded NUL, empty text
and binary bytes. Frozen d74 and the original fast archive pass the encoding
control; f470 fails; 9bee passes. All failures remain under `encoding-before`,
`sqlite-before-*` and `compare-*` in the evidence root.

The f470 sequencing process was stopped when this regression was identified.
B1 had just started; its active kernel child was allowed to finish, and no FPM
process had launched. `final-b-interruption.json` classifies that gate
INTERRUPTED. Original partial outputs remain; the fresh final campaign uses
`r2-*` directories and independently rebuilt binaries. This does not interrupt
or modify any historical Phase4 campaign.

## Ownership and PHP memory finding

See [NATIVE_OWNERSHIP_AUDIT.md](NATIVE_OWNERSHIP_AUDIT.md) for the creator, owner,
sharing/cancellation authority, release and request-teardown matrix. Concrete
SQLite allocation defects were corrected; copying remaining stream/pool owners,
uninterruptible kernel operations and foreign allocator coverage are explicitly
limited. No common cancellation authority or universal shutdown guarantee is
invented.

The distribution-PHP Valgrind discrepancy has a separate explanation: ordinary
PHP object/closure cycles reproduce shutdown losses without Asyncara or Fibers.
Explicit cycle collection clears them; DEBUG PHP performs extra shutdown
collection. The original no-explicit-GC reports remain FAIL, not suppressed or
reclassified as clean. Full differential controls and source evidence are in
[DEPENDENCY_FINDINGS.md](DEPENDENCY_FINDINGS.md). No production GC workaround was
introduced.

## Reproduction commands and interpretation

Use an independently built matching PHP module. Set `PHP_BIN`, `FPM_BIN` and
`ASYNCARA_EXTENSION` to the exact private binaries being tested; do not overwrite
a module while a gate is running.

```sh
python3 tests/fast/gate_a.py --php "$PHP_BIN" \
  --extension "$ASYNCARA_EXTENSION" --output /tmp/new-gate-a
python3 tests/fast/pipeline.py B --php "$PHP_BIN" \
  --extension "$ASYNCARA_EXTENSION" --output /tmp/new-gate-b
python3 tests/fast/pipeline.py C --php "$PHP_BIN" \
  --extension "$ASYNCARA_EXTENSION" --output /tmp/new-gate-c \
  --prior-a /tmp/new-gate-a --prior-b /tmp/new-gate-b
```

A fails on command failure, unexpected negative-control success, incorrect
ordering/progress, retained resources or broken native error invariants. B adds
at least 200,000 actual native kernel wait calls, separate high-volume worker
operations, and 600 seconds of mixed FPM with original assertions. C requires
completed matching A/B evidence and adds 1,800 seconds. Outputs include command,
PID, source/binary identities, per-command time/CPU, exit status, hashes and raw
samples. `test_density_fixture.py` mechanically removes only the additive
fixture edits and compares the result with the original fixture; FastCGI
transport is likewise compared. No original statement or assertion is removed.

The dense FPM fixture adds work and therefore changes interleavings; it is not
bit-identical to the historical request workload. Original A/B/C/D checks still
use the original fixture. Added operation counts never become HTTP-response
counts. The independent review checks request IDs, native/resource cleanup,
progress/overlap, lifecycle results, hashes, idle descriptors/threads and RSS
against original limits. It does not invent independent worker-local clock
ordering where the original records did not capture it. FPM helper exit-status
coverage and timestamp-order limitations remain in each review report.

Normal pre-merge feedback is A+B. The separately justified extended C observes
residual growth/scheduling for a candidate; it is not part of every edit's
feedback loop and is not six-/24-hour endurance. No hard real-time latency or
universal starvation guarantee follows from these passing finite workloads.

## Native execution map

- `Async::spawn` → PHP `Scheduler::spawn`/FIFO ready queue → `Task::step` → Zend
  Fiber start/resume. `Future::await` registers one waiter and suspends the
  current Fiber; its resume closure is idempotent. `Scheduler::turn` runs at
  most 256 ready Tasks, processes its monotonic heap and enters `poll` when
  appropriate. The queue/heap logic remains unchanged by this program.
- `TcpStream::read` attempts nonblocking PHP stream I/O, then registers a
  scheduler reader when no bytes are available. `asyncara_poll` converts
  resource/fd watchers → C `ar_wait` → actual epoll/poll → readiness IDs →
  scheduler resume → Fiber retries read. Partial writes use the analogous
  writable path; the fast test withholds consumption through two timer ticks.
- `Files`/SQLite submit bounded copied arguments → `asyncara_submit` →
  `ar_job_new`/`ar_submit` → bounded pthread queue → `do_file`/`do_handle`/
  `do_sqlite` → mutex-protected `ar_done` and wake pipe → `ar_wait`/
  `ar_collect` → PHP result conversion → Future settlement and Fiber resume.
  The worker never calls Zend. Accepted close owns mandatory cleanup even after
  cancellation; the SQLite repair occurs before publication and conversion.
- HTTP uses the same request engine with curl multi socket/timer callbacks on
  the interpreter thread, not the filesystem worker. Paused stream buffers and
  client permits retain their existing bounded ownership rules. HTTP disk
  operations use the separate pthread filesystem path.

The public APIs remain native Asyncara APIs; this terminology does not make
filesystem/SQLite/DNS kernel-nonblocking. PostgreSQL/libpq and MySQL/Connector/C
retain readiness state machines; Redis retains nonblocking TCP/RESP; ProcessPool
retains separate PHP processes. No new hooks, io_uring, scheduler model or
protocol implementation was introduced.

## Final-source results: `9bee5c3`

Native module: `build/program-r2.so`, SHA-256
`ed61af9fcb8e871f6e9cbdd929697d66080aa2310f5d207629738e433abe08c1`.
Every final output is under `r2-*` in the evidence root. Earlier `final-*` outputs
belong to the superseded intermediate candidate and are not silently reused.

| Validation | Executed result |
|---|---|
| Gate A | PASS 200.729s, 191.967 CPU seconds; three seeds, 10,000 iterations/path, both backends; includes five SQLite conversion controls and UTF-16 semantics |
| Targeted PHP/backend matrix | PASS 8.3.36/8.4.27/8.5.12 NTS × epoll/poll: real kernel, virtual scheduler, UTF-16 semantics; invalid-overlap negative controls fail as intended |
| Full PHP 8.5 regression suites | PASS epoll and poll; lifecycle, resource limits, file handles, HTTP streaming, networking, fatal-memory and existing protocol regressions |
| Native ASan/UBSan | PASS allocation injection, wait budget, notification/EINTR, SQLite conversion and worker/request lifecycle |
| Native TSan | PASS scoped worker lifecycle; process-local setarch personality, no global setting change; foreign libraries not instrumented |
| Matching DEBUG and GCC-instrumented PHP 8.5 | PASS lifecycle, resource bounds, kernel and UTF-16 controls on epoll/poll |
| Native/DEBUG Valgrind | PASS zero errors and zero definite/indirect/possible loss; no suppressions; still-reachable storage reported separately |
| Distribution Valgrind, original workload | FAIL exit 99, 1,128 direct / 0 indirect bytes; frozen-native control same count in this repetition. Separate explicit-GC control zero lost/errors; PHP-only explanation above |
| Local PostgreSQL 16.15 / MariaDB 11.8.9 / Redis | PASS original integration suite on epoll/poll; local Unix/socket fixtures. No new Oracle or remote TLS certification |
| Gate B | PASS 822.312s, 705.672 CPU seconds; independent review PASS |

B1 executes 350,004 real production kernel waits (175,002/backend), excluding
50,000 injected wait-EINTR returns. It also completes 210,000 worker jobs and
three seeded real-kernel PHP workloads/backend. B2 completes 8,240 main FPM
responses over 600.018848 seconds (13.733 responses/s), not 200,000 HTTP requests.
All 6,180 I/O responses satisfy the original overlap/ticker assertion, minimum 8
and maximum 82 ticks. Added density contributes 395,520 pending reads, 296,640
I/O completions, 49,440 timer firings, 98,880 cancellations and 791,040 endpoint
closes. Recorded scheduler Task steps total 1,282,020. The three arrival seeds
account for 2748/2748/2744 main responses.

The independent B review recalculates 2,058 steady idle samples per worker:

| Worker PID | RSS min–max KiB | Warmup peak KiB | Growth beyond warmup KiB | Range after first 3 KiB | Slope KiB/hour |
|---|---:|---:|---:|---:|---:|
|2834276|17128–17656|14556|3100|340|313.543|
|2834244|19108–19572|18980|592|276|616.453|
|2834245|18984–19572|18972|600|396|884.334|
|2834246|17188–17652|14940|2712|260|240.320|

Idle descriptors/threads are 6/1 throughout those samples. Original growth limit
is 8,192 KiB and was unchanged. A/B/C/D, recycling, reload, forced replacement,
fatal/pending cleanup and request-timeout cases pass. Explicitly recorded FPM
masters exit 0. Positive slopes over ten minutes motivate the separate C
observation; they are neither proof of a leak nor a plateau certification.
Evidence: `r2-b-data/fpm/gate.json`, `r2-b-data/review/report.json` and the hashed
raw JSONL/log inventory therein. No ptrace, wait probe, preload or sanitizer
mapping was present in read-only snapshots of the acceptance workers.


## Change and regression inventory

| Checkpoint | Scope | Evidence |
|---|---|---|
| `27d9a65f2ca3b8a4b38b11fc5e27ae63cca477d2` | Native `ext/backend.c`: `ar_wait`, `wait_fds`, monotonic deadline helpers | `tests/fast/wait_budget.c`; `budget-before` / `budget-after`, baseline/candidate comparison, final Gate A and memory checks |
| `5ed65e035c3eee833a7636736daf32a46e389b89` | Test infrastructure only: split native volume and dense FPM gates, diagnostic controls | Final A/B/C outputs; fixture fidelity regression; does not change production scheduler or hooks |
| `f470ea96191c84358030ac5a01ab9d3336a9a27f` | Native `ext/workers.c:do_sqlite` allocation checks; independent PHP shutdown control | Allocation failures repaired, but UTF-16 text regression found: rejected intermediate candidate, not final release source |
| `9bee5c3eeb1ba89124bb35251d679bbda0b8b7f8` | Native `do_sqlite` TEXT UTF-8 / BLOB byte semantics with immediate OOM checks | `tests/fast/sqlite_conversion.c` five cases; `sqlite_encoding.php`; complete final-source reruns |

The native changes are limited to those two C files. No `src/` PHP runtime,
original FPM fixture, historical acceptance runner, hook, backend configuration
or public API was changed. Documentation/evidence commits after `9bee5c3` retain
its native/runtime/test bytes; the package source is identified separately.

Test-only additions and changes:

- `wait_budget.c`: real production wait path with controlled monotonic/setup
  boundaries, real epoll/poll calls, rounding and clock-error cleanup.
- `sqlite_conversion.c`: linker-controlled documented SQLite OOM results in the
  real worker queue, partial-row cleanup and subsequent connection reuse.
- `sqlite_encoding.php`: public UTF-8/UTF-16le/UTF-16be text and binary semantics.
- `native_wait.c`: counts actual kernel calls separately from injected EINTR;
  retains original completion notification, partial-read, fd-reuse and HUP tests.
- `gate_a.py`, `validate.py`: include the new regressions in the developer and
  PHP/backend matrices. Existing `kernel.php`, `virtual_time.php`, `resources.php`
  and `deadlines.php` production-path coverage is preserved.
- `pipeline.py`: executable A/B1/B2/B/C, frozen identities, process records and
  prerequisite gating. `fpm_integration.py`, `fpm_dense.php`,
  `fpm_density.inc.php`, `fpm_client.py` add bounded in-request operation density.
  `test_density_fixture.py` proves the original fixture statements and transport
  remain after removing only the explicit additions.
- `review_integration.py`: independently recalculates raw request/progress,
  density, RSS, idle-resource and lifecycle evidence; records chronology limits.
- `dispatch_control.php` / `.py`: diagnostic-only peer-gated interpreter delay;
  not an acceptance configuration or production workaround.
- `tests/dependencies/php_cycle_shutdown.php`: independent PHP-only allocation
  control, with/without Fibers and explicit GC. It does not modify runtime GC.

Exact commands, compiler flags, environments, exits, CPU/wall time, log paths
and SHA-256 are retained by each `r2-*/result.json`, gate `report.json`, source
`identity.json` and memory log. The consolidated machine-readable index is
`docs/phase5/native-program-validation.json` after final evidence collection.

## Completed extended gate and resource review

Gate C PASS: 2136.317306 wall seconds, 1549.861680 child CPU seconds.
It runs four 25,000-iteration real-kernel workloads (two seeds per backend),
including 100,000 pending-read and 200,000 real timer transitions; five additional
cancellation/readiness/close cases total 500,000 iterations. These domains are
reported separately, not summed with the kernel syscall counters from B1.
Its 60,000 file/SQLite cleanup cycles submit and drain 420,000 worker jobs,
including cancellation; native/PHP ownership counts return to zero at checkpoints.

The mixed four-worker FPM interval completes in 1800.189838 seconds,
with 24,740 main responses (13.743/s).
All 18,555 I/O responses satisfy original overlap/progress
assertions; ticks range 4–84. Added density: 1,187,520 pendingReads, 890,640 ioCompletions, 148,440 timerFirings, 296,880 cancellations, 2,375,040 closedEndpoints.
Recorded scheduler Task steps: 3,838,837. Arrival-seed counts: {'17': 8248, '271828': 8248, '104729': 8244}.

| Worker | Samples | RSS min–max KiB | Warmup peak KiB | Growth KiB | Range after first three KiB | Slope KiB/hour |
|---|---:|---:|---:|---:|---:|---:|
|2897658|6183|17292–17896|14616|3280|304|216.906|
|2897626|6183|19028–19668|19024|644|324|333.754|
|2897627|6183|18860–19364|16632|2732|352|84.757|
|2897628|6183|19088–19560|18868|692|388|305.741|

The independent reviewer recalculates these values from raw samples and checks
against the stored summaries. The original 8,192 KiB growth bound is unchanged;
all steady idle observations have six descriptors and one thread. Functional
A/B/C/D, recycling, reload, forced replacement, fatal/pending teardown and timeout
replacement checks pass. Explicitly recorded master exits are zero. Helper exit
and clock-chronology limitations remain in the review. Resource trends describe
this finite window, not universal leak freedom or long-duration certification.

Evidence: `r2-c-data/report.json`, `r2-c-data/fpm/gate.json`,
`r2-c-data/review/report.json`, the raw JSONL files and their hash inventory.
`program-summary.json` independently derives response/lag distributions from
those records. The durable index is
[phase5/native-program-validation.json](phase5/native-program-validation.json).
No runtime/fixture changes occurred during A/B/C. Read-only worker snapshots
found no tracer/preload/sanitizer probe; no competing validation was launched.
The host is shared, so this is not an exclusive-host scheduling guarantee.

## Release assessment and next native work

**EXPERIMENTAL**. The specified finite developer/integration/extended gates and
scoped compatibility/memory checks complete; the original distribution Valgrind
command remains a failing shutdown-allocation observation with an independent
PHP-only explanation. Historical FPM zero-tick attribution remains unresolved.
No six-hour/24-hour result is inferred from these gates. No new hooks, io_uring,
protocol, ThreadPool, framework or platform certification was added.

Next priorities are explicit copying policies for remaining connection/pool
owners, narrowly targeted production-path regressions for any new ownership
finding, and low-overhead optional wait/dispatch provenance sufficient to attribute
future zero-tick events. Instrumented diagnostics must remain separate from
acceptance. Further event-loop persistence/allocation optimization requires a
measured bottleneck and equivalent-workload comparison. No speculative scheduler
priority workaround or universal memory-safety claim is justified.

The terminal log review retains expected PHP memory-exhaustion errors, the
intentional SIGKILL and timeout termination, and reload's inherited-socket notice.
No unexpected PHP error line was found in that classification. The first
process cleanup scanner matched its own shell text; the preserved corrected
process-title check found no owned FPM master and an exited supervisor. The first
Git-status comparison differed only by its recorded trailing newline; normalized
records and protected file hashes match. These observer corrections did not
modify runtime, acceptance assertions or raw logs.

Passing B/C still exhibit maximum per-request timer lag of 493.844/420.771ms,
respectively. These aggregate maxima exceed the historical failed request's
276.595ms maximum while its required progress still succeeds in the new runs.
They cannot, by themselves, attribute the original zero-tick cause. Full
latency distributions and caveats are in [PERFORMANCE.md](PERFORMANCE.md).

## Reproducible source distribution

Packaged source: `7ffeaf8146d067bfb8b62963b1851f96cae244bb` (documentation/evidence after native
`9bee5c3`; runtime/test bytes unchanged). Archive:
`build/asyncara-0.1.0-dev-native-program-r2.tar.gz`, 416 files, SHA-256:

`7eed7583e77165fcd7e0e554e4d067d61a70fbffdfbd590355b47106308bc26a`

Two independent generations are byte-identical. Every member matches Git.
Fresh extraction built matching PHP 8.3.36/8.4.27/8.5.12 modules using public SDK
headers and system library dependencies; no prior generated module or vendor
tree was reused. The shipped Composer lock was installed independently. Kernel,
SQLite encoding and lifecycle regressions pass on epoll/poll for all three
versions; full PHP 8.5 suites pass on both backends. All packaged source files,
including LICENSE/composer.lock, remain unchanged after build/tests.

The first extraction's validation failed because the external orchestration
omitted test-certificate preparation before a direct lifecycle invocation. Its
FileNotFoundError/PHP failure remains preserved. A second clean extraction uses
the existing `scripts/prepare-test-certs.sh`, with no test or archive change, and
passes. Composer validation succeeds; outside Git it emits a root-version
fallback notice. No dependency or metadata change was made to silence it.

[Package attestation](phase5/native-program-package.json) retains commands,
exits, logs, module hashes and both validation results. Archive source and final
attestation commit are distinct; no GitHub release/push occurred. The original
fast-r1 archive and Phase 4 history remain unchanged. The independent package
build does not claim another A/B/C run on its new binaries.
