# Long-running reliability tests

The existing `tests/stress.php` and FPM audit remain intact. The new
`tests/soak/run.py` creates an isolated source snapshot, copies the current native
binary, records a per-file manifest and extension SHA-256, and runs the workload
from that snapshot. Later working-tree edits cannot change the tested PHP code.
The immutable dependency extraction under `.local` is shared; no host service or
system configuration is changed. Use a new output directory for every run.

```sh
make build
python3 tests/soak/run.py --seconds 60 --databases --output build/soak-smoke
python3 tests/soak/run.py --seconds 3600 --databases --output build/soak-1h
python3 tests/soak/run.py --seconds 21600 --databases --output build/soak-6h
python3 tests/soak/run.py --seconds 86400 --databases --output build/soak-24h
```

`--databases` starts isolated PostgreSQL/MariaDB/Redis fixtures within the frozen
snapshot. It does not connect to production servers. Omitting it still exercises
SQLite. `--tasks` defaults to 1,000 newly scheduled Tasks per cycle (maximum
10,000). The workload keeps one scheduler alive throughout the requested duration,
repeatedly creates/closes SQLite connections, overlaps four HTTP requests, TCP
echo, file reads/writes, SQL transactions, Redis pipelines, bounded Queue work and
two process jobs. Every cycle checks cancellation/timeout cleanup; every 100
cycles crashes a worker and verifies replacement. Periodic idle waits exercise
wakeups. It is a CLI mixed workload, not an FPM test or a throughput benchmark.

Samples every five seconds record PHP live/allocated memory, RSS, descriptors,
sockets, threads, managed native memory, pending operations, task/Future/timer
counts, queue state, process pool jobs/bytes/restarts, known database connections,
timer lag, scheduling latency and expected errors. File/SQLite/pending-native
counts must return to zero at cycle boundaries. Successful shutdown must leave no
pending Futures. JSONL output is written incrementally rather than retained in
the measured process. Errors stop the run and remain in stderr and the report.

`report.json` includes warmup-excluded ranges, median endpoint changes and linear
slopes per hour. Growth thresholds request investigation; they do not prove the
absence of leaks. RSS includes allocator arenas and library caches. Sampling at
cycle boundaries misses transient peaks. Native curl readiness registrations do
not count its entire idle connection cache; OS socket counts include those sockets.
Scheduled Task counts describe Asyncara Fibers, not every application-created
Fiber. Third-party memory is not comprehensively accounted. Scheduling latency
includes the intentional burst that creates 1,000 Tasks.

Initial smoke: 20.173 seconds of workload, 126 mixed cycles, all four database
drivers, one injected worker crash/replacement, clean cleanup, no stderr. The
reported 28.479-second wall duration also includes database fixture startup and
shutdown. Timer lag reached 221.8 ms in this short run; this is retained as a
measurement, not hidden or presented as a latency guarantee. The one-hour frozen baseline subsequently passed: 3,600.149 workload seconds,
20,855 cycles / 20.855 million burst Tasks, 208 injected worker crashes and
recoveries, no unexpected errors, no pending Tasks/Futures/timers or managed
native bytes after cleanup. Across 706 samples, steady-state descriptors=20,
threads=5, sockets=7. RSS endpoint-median increase was 296 KiB; measured linear
slope 389.7 KiB/hour. Live PHP endpoint change was 216 bytes. Worst timer lag was
586.8 ms under simultaneous build/test CPU load; no latency guarantee follows.

This run froze commit `cad761e`, before later worker-signal, TLS-readiness and
filesystem changes. It is a baseline result, **not** a one-hour soak of the final
binary. Provenance and trends are in [phase3/soak-1h.json](phase3/soak-1h.json).
Six- and 24-hour tests are **not yet executed**. Consult
`docs/phase3/` and WORKLOG for completed run evidence before inferring coverage.

The separate scheduled workflow runs the one-hour test; its remote status is
unverified until hosted CI executes it. Longer commands require a machine/session
that can remain available for the full duration. Do not label a smoke test as a
one-hour soak or extrapolate successful short runs to 24 hours.

Final runtime checkpoint `8af2604` also passed a frozen 300.106-second mixed run:
1,894 cycles / 1.894m burst Tasks, 18 injected worker restarts, no unexpected
errors or final pending runtime objects. Stable sampled fds20/threads5/sockets7;
RSS endpoint medians +444 KiB and live PHP +16,168 bytes. The short-window RSS
slope was 8,838.7 KiB/hour; extrapolating five minutes to long-term growth is not
justified. The sample range and slopes are retained in
[phase3/soak-final-5m.json](phase3/soak-final-5m.json). Final-binary long-duration
stability remains unverified. Atomic replacement has separate fault/race tests;
this mixed workload exercises ordinary file operations.
