# Fast reliability engineering

Status: scoped implementation and validation completed on `phase5-fast-reliability`;
release **EXPERIMENTAL**. This track starts at `6ee73d8563d6f7c5313eea47ceca2d4ba9fa55e4`
and does not modify the Phase 4 checkout, installed module or historical fixtures.

## Preserved history

The original supervisor's one-hour result remains PASS (3,600.126 seconds,
48,124 main responses). The independent ordering review has PASS acceptance
checks but an INDETERMINATE overall clock-order check: request-batch emission
order is not within-worker chronology. Neither result is relabelled.

The six-hour stage remains FAIL after 13,501.386592 seconds and 196,536 persisted
main responses: `I/O did not overlap or blocked loop`, `ticks: 0`. No 24-hour
stage started. Original supervisor, runner and FPM identities were absent from
`/proc` before new tests. Details and complete original artifacts are preserved
outside Git in `/home/coder/Asyncara-fast-evidence-20261107T155547Z/`:

- `phase4-original-evidence.tar`: 691,517,440 bytes, 1,573 members,
  SHA-256 `ecff33b16db232ffd81e9f219f2cac87d3aef71bbb74a8ad1abdccaf573919b4`.
  Tar comparison against the untouched originals returned zero.
- `checkpoint.json`, `preservation.json`, and a separate copy of the original
  `asyncara-0.1.0-dev-phase4-lifecycle-r1.tar.gz`.
- Original source package SHA-256:
  `8586c278f0d29d6b89c6a1fc00722ceb1e53f9943be6737e7524662097f59369`.

## Execution path and unresolved zero-tick cause

`Async::spawn` / `Task` create PHP Fibers. `Scheduler::turn` processes the PHP
monotonic timer heap, dispatches up to 256 FIFO ready tasks, then `poll` computes
the next deadline (ceiling to milliseconds) and calls `asyncara_poll`.
`ext/php_asyncara.c` converts streams into native watchers and calls
`ext/backend.c:ar_wait`. That function checks completed jobs under the engine
mutex, merges socket interests and worker pipe, bounds the wait with curl's timer,
and calls production `poll` or `epoll_wait`. EINTR returns to PHP to recompute
its deadline. Readiness queues tasks; worker results become PHP values on the
interpreter thread. `workers.c:ar_done` publishes completion and signals the pipe.

The timer heap and Fiber dispatch are PHP implementations; there is no native C
timer heap to replace. No timer priority or dispatch behavior changes here.
If a process resumes after both a timer deadline and network completion, ready
I/O tasks can run before the resumed ticker examines incomplete jobs. That is a
source-supported possible explanation, not attribution of any historical failure.
The historical failure lacks a deterministic causal trace. A peer held until two
ticks tests the necessary progress invariant but cannot certify host scheduling
for the original fixed-delay FPM peer.

## Native corrections and controls

`6ee73d8` changes accepted file/SQLite close dispatch: mandatory cleanup runs
regardless of cancellation and preserves actual close errors. It does not touch
the wait path. Native worker-gated tests fail on `d74f3b3` and pass on `6ee73d8`
for file/SQLite closes; ordinary cancellation controls pass on both.

This track changes only `ext/workers.c:ar_done`: retry the one-byte wake-pipe
write on EINTR. A completion can arrive after `ar_wait` checks `done_head` but
before the kernel wait. Publication without a successful notification can leave
that wait asleep. EAGAIN is not retried: a full pipe is already readable. Queue
ownership, publication locking, collection and worker joining are unchanged.

`tests/fast/native_wait.c` gates publication at the syscall boundary and injects
1–3 EINTR returns at the write boundary. Production queues and real kernel waits
remain in use. This is fault-injection evidence, not proof that current Linux
nonblocking one-byte writes encounter EINTR in the historical FPM workload;
ordinary worker signals are masked. No claim links this repair to zero ticks.
The regression also fills the pipe, checks duplicate collection/zero ledger,
merges descriptor interests, reads partial data, closes/reuses descriptors and
exercises injected wait EINTR separately from actual SIGALRM kernel interruption.

The first harness missed fortified libc's `__poll_chk` entry point. Its failures
remain in `initial-comparison/` and are not native defect evidence. The corrected
harness retains the libc bounds check, delegates to real waits, and asserts that
completion publication actually happened. `comparison-r2/` records the valid
failing-before controls. No suppression or acceptance threshold changed.

## Gates and coverage boundaries

- Gate A: `tests/fast/gate_a.py`, production real-kernel
  PHP/C transitions plus separate virtual-time scheduler tests. Initial target:
  10,000 transitions per named path, epoll/poll, PHP 8.3/8.4/8.5 matrix.
- Gate B: `tests/fast/fpm_count.py --responses 200000 --seconds 600 --seed 17`.
  It reuses the original PHP acceptance fixture and `scripts/audit-fpm.py`, its
  200ms peer delay, four workers, two-tick assertion, A/B/C/D, recycling, reload,
  forced replacement, fatal/pending teardown, timeout and original RSS/fd/thread
  thresholds. Only the stop predicate and seeded arrival order change. Count
  target exhaustion produces INCOMPLETE and exit 2, never PASS.
- Gate C: optional 30–60-minute candidate observation only after Gates A/B pass
  and a specific remaining question justifies it. No automatic long soak.

Four workers, with three of every four main requests requiring at least 200ms,
impose an ideal upper bound of 26.7 responses/second, before any overhead.
200,000 responses therefore require at least 7,500 seconds even with perfect
pipelining. The preserved batch barriers impose further overhead. Achieving that
count in 10–30 minutes would require changing protected workload semantics or
worker count. This runner reports the actual shortfall; it does not disguise it.

`kernel.php` proves two ticks before releasing four real TCP reads, then repeats
pending socket-pair reads, timers, cancellation before/after observed readiness,
timeouts, close/reopen and completed-result cancellation. Negative early-I/O
controls must fail the same two-tick assertion; they are synthetic oracle checks.
`virtual_time.php` uses test-namespace clock/poll functions with the unchanged
production Scheduler for heap ordering, cancellation, equal/past deadlines,
large jumps, repeated timers, timeouts and rounding. It never certifies kernel
waiting. `resources.php` records operation-count file/SQLite/cancellation cleanup
and checks native ledger, pending operations, handles, Tasks, Futures, timers,
watchers, descriptors and worker bounds. RSS is recorded, not treated as leak proof.

## Validation record

[Machine-readable validation](phase5/fast-validation.json) records every result.
Every command writes a unique log, exit status,
wall/CPU time, file hashes and source manifest under the evidence directory.
No new binary is installed into the Phase 4 checkout. Initial comparison modules
omit optional PG/MySQL drivers; their results certify the exercised core/SQLite
paths only. Full-feature and memory-tool runs must be listed separately.
The earlier distribution-PHP Valgrind discrepancy (904 direct +120 indirect lost
bytes) remains unresolved and is not erased by scoped new native tests.


## Executed results and source identities

- Native correction: `8263fcdca5c02a89555053553d6e760bb7792ee5`.
- FPM runner/reviewer checkpoint: `cd91b4955e36f3f68246f96eb2c6e7ddf98c151d`.
  Its `ext/` and `src/` bytes are identical to the native correction.
- Main Phase 4 remains `6a7e0f45cb64577b3ba0cf30e160bd98901e4d90`; its three
  untracked reviewer files and all 12 protected file fingerprints match.
- Own full-feature binary: `build/fast-full.so`, SHA-256
  `8c4dc86b5188dc46cf08eda3cfd2f78f4225c352a636eeebf500f4956f463b84`.
  It was compiled before committing 8263fcd and its complete native source
  manifest was subsequently verified against that checkpoint. DEBUG/sanitized
  builds have separate filenames; none replaced a Phase 4 binary.
- Linux x86_64, Ubuntu 24.04, GCC 13.2; PHP **8.3.36**, **8.4.27**, **8.5.12** NTS.
  Dependency versions and every compiler command are in the evidence records.

| Check | Actual result | Evidence beneath the external evidence root |
|---|---|---|
| Frozen/candidate/corrected × PHP 8.3/8.4/8.5 × epoll/poll | 18 kernel and 18 virtual-time runs pass, 10,000 iterations; negative oracle fails as intended. 93 commands total, 375.277s | `matrix-r1/results.json`, corresponding logs/source manifest |
| Accepted close negative controls | Frozen file and SQLite checks abort; candidate and corrected pass; ordinary cancelled-write controls pass | `comparison-r2/`, `matrix-r1/` |
| EINTR notification negative controls | Both old implementations fail on both backends; corrected passes 10,000 completions/backend | `post-gate/baseline-wakeup-{0,1}.log`, candidate equivalents; `gate-a-r1/native-wait.log` |
| Gate A | PASS: 190.920s wall, 151.802s child CPU; seeds 17/271828/104729; 10,000 iterations per named path | `gate-a-r1/report.json` |
| Gate B | **INCOMPLETE**: 7,980/200,000 main responses in 600.026590s mixed work (13.299/s), 612.987s wrapper wall | `gate-b-r1/gate.json`, `gate-b-exit.json` |
| Independent Gate B workload/resource review | PASS within that partial count: 5,985 I/O responses, minimum 8 ticks; all A/B/C/D, recycling, reload, kill, fatal/pending and timeout checks pass | `gate-b-review-r1/report.json` |
| Gate C | NOT_STARTED; volume gate did not pass | No extended campaign launched |
| Full PHP 8.5 suite, epoll/poll | PASS after installing unchanged locked Composer dependencies: 44 core cases plus limits, fatal cleanup, lifecycle, streams, HTTP, protocol/IPC regressions | `post-gate-r2/full-{epoll,poll}.log` |
| PHP 8.3/8.4 targeted lifecycle/handles/readiness/resources | PASS, both backends | `post-gate/results.json` |
| Native and matching GCC ASan/UBSan PHP paths | PASS in the executed scope, plus existing native sanitizer | `post-gate/asan-*.log`, `sanitized-*.log`, `post-gate-r2/existing-native-sanitizer.log` |
| Native and Valgrind-aware DEBUG controls | Zero lost bytes and zero errors; still-reachable allocations remain separately reported | `post-gate/valgrind-native-*-memory.log`, `post-gate-r2/valgrind-debug-memory.log` |
| Distribution PHP lifecycle under Valgrind | **FAIL / UNRESOLVED**; no suppression | See next paragraph |

The first full suites failed because this isolated worktree lacked the locked
Nyholm PSR-7 dependency (43 pass, 1 fail). Those logs remain in `post-gate/`.
Composer installed six existing locked packages into this worktree only; no
manifest/lock/license changed. The complete suites were then rerun unchanged.

The initial distribution-PHP Valgrind repeat reported 1,128 direct/0 indirect
lost bytes. After dependency installation completed, two independent repeats
reported **904 direct/120 indirect**, then **1,888 direct/120 indirect** lost
bytes, exits 99. Corresponding DEBUG control has zero lost/errors. Traces include
Zend object/closure allocation and Fiber context frames; this neither assigns
ownership to upstream PHP nor absolves Asyncara's PHP classes. Fiber-stack
warnings are separate diagnostics. No link to the upstream libpq TLS allocation
has been demonstrated. No losses, warnings or old failures were suppressed.

## Resource observations

The independent FPM review recalculated all 7,972 steady samples. Four final
workers each have 1,993 samples, **six descriptors and one thread** when idle.
RSS growth beyond their warmup peaks is **2,836 / 468 / 2,312 / 556 KiB**, each
strictly below the unchanged 8,192 KiB rejection threshold. The ranges excluding
first three samples are 304/280/280/420 KiB. Short-run fitted slopes remain positive
(approximately 279–1,135 KiB/hour); these are observations, not long-term leak
predictions or evidence that all caches have reached their final size.

`resources.php` additionally completed **30,000 cycles / 210,000 worker jobs per
backend**. These are not FPM responses. Every checkpoint has zero native ledger,
pending operations, open file/SQLite handles, active Tasks/Futures/timers/watchers;
seven descriptors and five threads (four native workers plus interpreter).
RSS: epoll 31,196–34,104 KiB, ending 33,224; poll 31,280–33,740, ending 32,936.
Retained JSON samples deliberately contribute a small PHP-memory increase.
Foreign allocators and kernel memory remain outside the ledger.

`engine_cycles.c` exercised **20,000 complete native engine lifecycles and 80,000
worker starts**, alternating collection with shutdown while work is pending,
and cancelling every third read. It completed in 39.836s; descriptor count four
(including the scanner), RSS 10,300–10,372 KiB. Task-directory observations were
one or transiently two after pthread joins; they are not relabelled as always one.
A separate 2,000-cycle /8,000-worker-start ASan/UBSan run passed. This tests native
request-engine ownership, not Zend/FPM request count equivalence.

## Reproduction

Install the existing locked development dependencies and build an isolated
module using [installation instructions](INSTALLATION.md). Choose fresh output
paths; each runner refuses to overwrite an existing directory. For this host,
exact interpreter/SDK paths, commands, environment and hashes are in
`phase5/fast-validation.json` and the referenced raw command records.

```sh
composer install --no-interaction --ignore-platform-req=ext-asyncara
./scripts/build-local.sh
python3 -B tests/fast/gate_a.py --output build/fast-a-new
python3 -B tests/fast/fpm_count.py --output build/fast-b-new   --responses 200000 --seconds 600 --seed 17
# Run only after the FPM runner has exited. Exit 2 means count incomplete.
python3 -B tests/fast/review_fpm.py build/fast-b-new --output build/fast-b-review-new
```

Set `PHP_BIN`, `PHP_INCLUDE_DIR`, `FPM_BIN` and `ASYNCARA_EXTENSION` for the chosen
isolated build. For comparison, export `ext/` from d74f3b3 and 6ee73d8 into fresh
`build/baseline` and `build/candidate` directories with `git archive`; verify their
bytes against Git. `validate.py --variants baseline candidate corrected --php
83 84 85 --output <new-directory>` uses matching SDKs, overridable through
`PHP83_BIN`/`PHP83_INCLUDE_DIR` (and 84/85 equivalents). The comparison harness needs repository history; Gate A also currently requires
a checkout for Git provenance. The production build and direct PHP test files
can run from a source archive without Git history.
Do not confuse the comparison harness's expected failures with passing baselines.

Standalone engine-cycle and sanitizer commands are preserved in
`run-post-gate.py` and its `post-gate/results.json`. CI now invokes Gate A on each
PHP matrix entry; hosted CI was not executed by this local assignment.

## Reliability conclusion and next priorities

The process now provides a measured three-minute developer gate, real-kernel
and virtual-time checks, deterministic syscall-boundary fault injection, and
count-driven FPM evidence with explicit incomplete outcomes. It detects the
injected lost-notification invariant and accepted-close regression rapidly.
It has **not reproduced or fixed the exact historical zero-tick cause**. Tests
that also pass on the frozen source are coverage, not proof of that repair.

Next: reduce the distribution-PHP allocation discrepancy; retain the historical
zero-tick failing request as a separate unresolved gate; develop additional
causally controlled scheduling diagnostics if needed. Preserve the original
200ms/four-worker/two-tick workload as its own criterion. A 200,000-response
qualification cannot be called a 10–30-minute gate under those constraints.
Native operation-volume tests and a short FPM sample complement it, not replace
its request count. No six-hour or 24-hour campaign is scheduled automatically.

No new hook, backend, protocol, framework feature, or scheduler rewrite was made.
Filesystem/SQLite/DNS remain worker-backed; HTTP remains curl multi event-driven.
No new TSan or remote database certification was performed. Release remains
**EXPERIMENTAL**. No new GitHub release, force-push or history rewrite occurred.

## Packaged source and independent build

[Package attestation](phase5/fast-package.json) identifies source
`7c0fbda089a204f81f6bab80513b8807cd05b769` and the 399-file archive
`build/asyncara-0.1.0-dev-fast-reliability-r1.tar.gz`:

```text
ac1f5c76e15fd235341f9e70b83220bec585c62a7b5b2955ea9ce93691d0a876
```

Every archived file matches that Git tree. Repeating `git archive` produces the
same checksum; LICENSE is unchanged. Extraction and compilation took place
outside Git under the evidence directory's `archive-check/`. Only development
headers were copied as dependencies, never modules or generated build outputs.
The newly compiled archive module is
`073deaf495fe13fab538a576391c2a41b2ddacef45871e13b4b8337ab7bc6d5f`;
binary paths/debug information differ from the worktree build. It passed
128-iteration kernel/virtual-time/deadline/resource smoke tests on both backends
and native close controls. This is independent package validation of that scope,
not another full Gate A/B execution. Later attestation/documentation commits do
not change the packaged native/PHP runtime bytes. Nothing was installed into
Phase 4 or published as a GitHub release.

Metrics report live owner counts and explicit loop counts. They do not expose a
universal cumulative Task/Future/watcher creation counter, nor account for all
third-party or kernel allocations. No missing metric is inferred from RSS.

## Continuous native-program follow-up

The results above are historical evidence for fast-reliability-r1. In particular,
7,980/200,000 FPM responses remains INCOMPLETE. The owner's subsequent program
separates native operation volume (B1) from short real four-worker integration
(B2); it does not relabel those responses or certify 24-hour stability.
See [NATIVE_RELIABILITY_PROGRAM.md](NATIVE_RELIABILITY_PROGRAM.md) for the two
additional C corrections, final-source gates, PHP shutdown controls, ownership
audit and new package identity. The original zero-tick cause remains distinct
from the proven injected EINTR notification defect.
