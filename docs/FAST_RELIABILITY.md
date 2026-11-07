# Fast reliability engineering

Status: implementation/validation in progress on `phase5-fast-reliability`;
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

- Gate A: `tests/fast/validate.py --variants corrected`, production real-kernel
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

Pending final aggregation. Every command writes a unique log, exit status,
wall/CPU time, file hashes and source manifest under the evidence directory.
No new binary is installed into the Phase 4 checkout. Initial comparison modules
omit optional PG/MySQL drivers; their results certify the exercised core/SQLite
paths only. Full-feature and memory-tool runs must be listed separately.
The earlier distribution-PHP Valgrind discrepancy (904 direct +120 indirect lost
bytes) remains unresolved and is not erased by scoped new native tests.
