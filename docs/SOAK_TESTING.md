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

Earlier runtime checkpoint `8af2604` also passed a frozen 300.106-second mixed run:
1,894 cycles / 1.894m burst Tasks, 18 injected worker restarts, no unexpected
errors or final pending runtime objects. Stable sampled fds20/threads5/sockets7;
RSS endpoint medians +444 KiB and live PHP +16,168 bytes. The short-window RSS
slope was 8,838.7 KiB/hour; extrapolating five minutes to long-term growth is not
justified. The sample range and slopes are retained in
[phase3/soak-final-5m.json](phase3/soak-final-5m.json). Final-binary long-duration
stability remains unverified. Atomic replacement has separate fault/race tests;
this mixed workload exercises ordinary file operations.

## Separate WebSocket workload

```sh
python3 scripts/install-test-websockets.py
python3 tests/soak/run.py --seconds 300 --workload websocket --connections 16 --output /tmp/new-ws-soak
```

The frozen workload retains connections across cycles, sends 1 KiB echoes on
each, interleaves pings, reconnects/cancels receivers every 100 cycles, includes
idle intervals, and records queue/counter/resource samples. This is separate
from `--databases` mixed HTTP/files/SQL/process work. A 10.033s workload smoke
completed 1,140 cycles with zero final pending tasks/Futures/sockets. Two samples
are insufficient for a leak trend claim. Longer results must record their own
source commit; no earlier mixed soak is relabeled as covering WebSocket.

The frozen WebSocket checkpoint `648c076` completed300.026 workload seconds,
24,686cycles /394,976echo jobs with16persistent connections,246 reconnect/read
cancellation injections and no unexpected errors. Steady RSS was26,008KiB,
fds25/threads1/sockets16; managed native bytes0. Live PHP endpoint medians rose
19,728bytes with a54,979.9bytes/hour short-window slope; this fluctuating short
run is not extrapolated to a leak rate. Maximum task step376.7ms and timer lag
162.8ms occurred under simultaneous benchmarks/instrumented tests. Final Tasks,
Futures,timers/watchers/sockets were zero. Full trends/provenance are retained in
[phase3/soak-websocket-5m.json](phase3/soak-websocket-5m.json). This is five-minute
WebSocket evidence, not six-/24-hour certification.

## One hour including HTTP streaming

Frozen commit `1f792ef` completed3,600.110 workload seconds (3,618.891s including
fixture startup/teardown),19,761mixed cycles /19.761million burst Tasks and197
injected ProcessPool crash/recoveries. Buffered and streamed HTTP, ordinary files,
SQLite/PostgreSQL/MariaDB/Redis, queues, cancellation and timers were included.
All706samples retained. Steady fds20/threads5/sockets7; native tracked bytes0 at
cycle boundaries. RSS endpoint medians+380KiB, slope472.37KiB/hour; live PHP
endpoint medians+16,168bytes. Final Tasks/Futures/timers/watchers/native work0.
Maximum task step655.7ms/timer lag502.7ms occurred on the shared loaded host.

This certifies the tested workload at the recorded source/native hash only. It
predates WebSocket and the later older-Connector/C capability-gate correction.
Those have separate tests; no six-/24-hour or final-binary endurance claim is
inferred. Raw provenance/trends: phase3/soak-http-1h.json. The separate expanded
FPM RSS failures required a later mapping repair; the unchanged follow-up checks
are documented in [FPM_LIFECYCLE](FPM_LIFECYCLE.md).

## Final native source: five-minute verification

Frozen clean commit `e5d94fd8dd959b88474332a05aaa1c9eb61f318f`, PHP 8.5.12 NTS,
completed 300.073 workload seconds (309.367 seconds including fixture setup and
teardown), 1,331 mixed cycles / 1.331 million burst Tasks and 13 injected
ProcessPool crash recoveries. No unexpected errors or final pending Tasks,
Futures, timers or native jobs remained. Native worker threads are still owned
by the active PHP request at the final sample and terminate during request
shutdown; they are not mislabeled as already reaped.

Steady samples had 20 descriptors, five threads and seven sockets. Native bytes
were zero at cycle boundaries. RSS ranged from 42,356 to 42,624 KiB; endpoint
medians increased 200 KiB and live PHP endpoint medians were unchanged. Maximum
task step was 287.8 ms and timer lag 410.1 ms on the loaded host. These remain
measured outliers, not a latency guarantee. Full provenance and trends are in
[final-native-validation.json](phase3/final-native-validation.json).

Final-source one-hour, six-hour and 24-hour runs are **not executed**. Neither
earlier one-hour checkpoint nor this short final run establishes that endurance.
