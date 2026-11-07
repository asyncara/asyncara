# Bounded Event Loop provenance

This is an opt-in diagnostic facility, not an acceptance configuration or a
guarantee that a historical failure can be reconstructed. It is disabled by
default. The normal runtime never writes a trace file.

```php
Asyncara\Trace::enable(capacity: 1024); // before Async::run
try {
    Asyncara\Async::run($application);
} finally {
    Asyncara\Trace::disable();
    Asyncara\Trace::dump('/private/new-unique-trace.json');
}
```

`dump` creates a new file exclusively. It never overwrites an existing file.
`snapshot` returns a bounded PHP array. `writeTo` accepts a caller-owned stream;
short writes are retried, zero/failed writes and failed flushes throw. Extraction
is allowed outside `Async::run`, since encoding and output can block. A failed
output can leave a truncated file; only a complete JSON document is usable.
Snapshot or output failure neither consumes the ring nor releases operations.

## Storage and concurrency

`ext/memory.c:ar_trace_emit` records fixed 64-byte events in one request-local
ring. Capacity is 16..4096 and cannot change once allocated in that request.
On the validated ABI the ring uses `72 + capacity * 64` bytes: 65,608 by default,
262,216 maximum. Snapshot conversion uses a separate 262,144-byte temporary
Zend allocation, then bounded PHP arrays and optional JSON (at most 4 MiB).
Those export allocations are subject to PHP's memory limit. The ring has a
separate hard bound and is reported as `nativeDiagnosticBytes`; it is not part
of the payload ledger `nativeMemoryBytes`. Disabling capture retains the ring
until request shutdown, after native worker joins. It does not free storage
under a publisher.

Producers use an atomic try-lock, with no spinning, blocking mutex, allocation,
file/network output, payload copy or Zend access. Contention drops the event and
increments `contentionDropped`; a full ring replaces its oldest event and
increments `overwritten`. Sequence gaps and clock failures are visible. Atomic
operations must be lock-free or enabling diagnostics fails. No callback or
arbitrary application code executes in a producer.

A snapshot also uses a try-lock, copies only fixed records, releases the ring,
then converts into PHP values. Contention returns an explicit EBUSY exception.
The caller may retry later. The diagnostic FPM test allows at most three
immediate post-execution attempts, without waiting or changing the original
workload. This is not a guarantee that every shutdown can export a trace.

## Schema 1

Header: schema, PID, per-process request sequence, request-start monotonic
timestamp, backend, capacity, native bytes, enabled category mask, attempted
events, overwritten events, contention drops, clock failures and completeness.
`complete` means no known capture loss since enabling; it does not mean every
possible runtime state or external library action was instrumented. Counts
sampled during concurrent publication can advance after extraction.

Rows contain `sequence, monotonicNs, scope, category, event, id, a, b, c`.
`scope` separates consecutive Scheduler runs within a request. Native job IDs
are request-local; task/timer/watcher IDs are scheduler-local. A stream resource
ID is not an OS descriptor: watcher-arm records provide the native mapping.
PID plus request start identifies samples across worker replacement. Zero
request-start time indicates a failed clock observation, not a valid timestamp.

| Category / mask | Events | Metadata |
|---|---|---|
| Wait / 1 | 10 entry; 11 syscall entry; 12 syscall return; 13 exit | wait ID; original timeout/count/absolute deadline; effective timeout/fd count/backend; return/errno/measured syscall nanoseconds; final return/errno/pending jobs |
| Wait / 1 | 60 PHP poll plan; 61 result dispatch boundary | earliest heap deadline, requested timeout, ready queue length; read/write/completion counts |
| Jobs / 2 | 20 submission; 21 worker start; 22 result produced; 23 pipe notification; 24 collection; 25 cancellation; 26 free | job ID, operation enum, counts/result flags; notification return and errno (including EINTR/EAGAIN). Result production precedes publication, collection can precede the final notification |
| Scheduler / 4 | 32 enqueue; 33 dequeue; 34 Fiber suspension; 35 resumption; 36 completion; 37 cancellation; 38 waiter wake | task ID, queue/task counts, suspended/error flags; no callbacks or PHP values |
| Timer / 8 | 40 registration; 41 removal; 42 due; 43 dispatch; 44 callback return | timer ID, monotonic deadline, wait-timer flag, owning task, measured lateness, active timer count |
| I/O / 16 | 50 watcher add; 51 remove; 52 native readiness; 53 wrapper close; 55 byte progress; 56 empty read; 57 HTTP pause; 58 native watcher arm | watcher/job/resource ID, fd where available, masks, buffered readiness, lengths; no bytes or URLs |
| Lifecycle / 32 | 1 enable; 3 extraction; 4 deferred output flushed; 30 Scheduler scope; 31 Scheduler drain end; 70 native shutdown entry | request/scope and bounded counters; 4 records bytes after successful PHP flush, not filesystem durability |
| Mark / 64 | 80 caller anomaly marker | numeric reason only |

All timestamps use CLOCK_MONOTONIC. Syscall duration -1 means measurement was
unavailable. Deadlines 0/-1 retain immediate/infinite meanings. Event 41 is
*removal*, also used after expiration: it is not necessarily cancellation.
Event 56 observes an empty PHP-stream read; it does not falsely assert a kernel
EAGAIN. Event 55 records byte counts only. Descriptor numbers can be reused;
watcher IDs and scope must be considered when correlating them.

`Trace::enable(classes: Trace::WAIT | Trace::TIMER)` selects classes. Selecting
fewer classes intentionally omits transitions. `mark($numericReason, true)`
marks and disables further capture; an already admitted publisher may still
finish, so extraction can return EBUSY. Nothing automatically changes timer
deadlines, scheduling order, resource ownership or acceptance thresholds.

## Shutdown and privacy boundaries

RINIT records request identity; enabling later cannot reconstruct earlier
events. Native shutdown entry is recorded if enabled, but the ring is then
freed. The public API has no post-RSHUTDOWN export. A PHP shutdown callback can
extract **before** native teardown; it cannot certify subsequent joins/frees.
Hard OOM, process crash, SIGKILL or unavailable output can prevent extraction.
The normal scoped `Async::run` cleanup trace is separately observable.

No application body, SQL text/value, host, path, credential, header, pointer,
closure or zval is stored by the recorder. Numeric process/resource IDs and
timing are operational information; applications should protect exported files.
Callers must not encode secrets in their own numeric marks.

Validation commands: `tests/diagnostics/run.py`, `tests/diagnostics/fpm.py`,
`tests/diagnostics/overhead.php`, and `tests/fast/dispatch_control.py --trace`.
The last deliberately delays the interpreter: diagnostic evidence only. Actual
measurements and source identities belong to the program's execution report.
