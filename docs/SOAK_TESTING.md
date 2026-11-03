# Long-running reliability tests

Current Phase 4 state: an independent hosted attempt failed at IPv4 UDP receive.
A deterministic regression then reproduced duplicate live UDP endpoints and
misdirected datagrams. The implementation now binds exclusively; earlier archives
and endurance runs do not certify this repair. The local `3587740` retry was
deliberately interrupted after 2,761.879 sampled seconds / 6,524 cycles. A corrected
expanded smoke passed 60.106 seconds / 111 cycles with all local SQL fixtures,
including Oracle separately, and extended networking. No long-duration pass is
inferred. Corrected checkpoint/package and new campaigns: PHASE4_DELIVERY.md.

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

The separate endurance workflow runs mixed and FPM one-hour tests on independent
hosted runners. Current run status and scope are recorded in PHASE4_DELIVERY.md;
a successfully started job does not establish completion. Longer commands require a machine/session
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

At that Phase 3 checkpoint, final-source one-hour, six-hour and 24-hour runs had
not executed. Phase 4 attempts and failures are recorded below and in
PHASE4_DELIVERY.md; neither an earlier one-hour checkpoint nor this short final
run establishes current endurance.

## Phase 4 harness memory

The first Phase 4 one-hour mixed/FPM attempts were interrupted, not passed;
see PHASE4_DELIVERY.md for current completion status. The longer-run harness now
aggregates mixed JSONL in two sequential passes with bounded statistics. It
retains the exact warmup exclusion, endpoint median windows and growth limits.
The whole-series `median` field becomes `mean`; exact whole-series medians can
still be computed offline from the preserved raw observations. No threshold
uses that changed descriptive field. Timeout and malformed-output reports retain
the raw workload evidence and fail explicitly.

`python3 tests/soak/test_metrics.py` checks equivalence with the historical
formulas, boundary failures, malformed/duplicate completion and 200,000 FPM
observations with under 64 KiB traced collector memory. A 20-second mixed smoke
passed after this change; it is harness validation, not endurance evidence.


## Expanded mixed scope and OS observations

`--extended-network` adds private deterministic IPv4/IPv6 UDP, localhost DNS,
TLS1.2/1.3 (verified CA/hostname, SNI and ALPN), HTTPS buffered/streamed responses
and two persistent ws/wss connections. Ping, reconnect and receive cancellation
are included. `--oracle-prefix /path/to/extracted/oracle` adds Oracle MySQL to
`--databases`; MariaDB remains a separate simultaneous fixture. This uses private
Unix sockets and does not add remote SQL TLS coverage. Exact versions appear in
the configuration record. Install the pinned WebSocket peer first using the
existing `scripts/install-test-websockets.py`.

`os-processes.jsonl` samples the harness, descendants and validated private
PostgreSQL daemon PID every five seconds. It records PID/start ticks, RSS, virtual
memory, descriptors, sockets and threads without collecting command lines or
credentials. Processes exiting or hidden by Linux `/proc` restrictions can be
missed. Descriptor/socket EACCES observations are null with explicit reasons;
these are gaps, not zero usage. Sampling is not a peak RSS/cgroup measurement.
Existing PHP/FPM assertions continue independently. `test_monitor.py` checks this
observer, including ownership mismatch and simulated permission failure.

The expanded epoll smoke passed 60.260 seconds / 115 cycles. Earlier epoll/poll
smokes failed the observer after their workloads completed; preserve their failed
reports. See phase4/harness-validation.json. Final-source one-hour attempts at
baseline179aa54 were interrupted after1195.491s mixed/1166.910s FPM. They do not
establish one-hour endurance. Restart each campaign in a new directory.

## Phase 4 frozen campaign failure

Source `2e2f84f` and the phase4-r1 archive were frozen before the next 3,600-second
attempt. It failed with an unhandled timeout, exit 255, after 2,023.583
wall seconds including fixture setup/teardown. Last completed sample: 1,940.227
workload seconds, 5,517 cycles and 55 injected ProcessPool restarts. Sampled RSS
endpoint medians increased 380 KiB; native bytes stayed zero at cycle boundaries,
threads five and fds 23–24. The required completion record is absent. Neither
bounded resources nor the preceding successful cycles make this a passing run.

The simultaneous FPM attempt also failed. The staged supervisor stopped without
starting six- or 24-hour tests. [campaign-failure.json](phase4/campaign-failure.json)
retains the final reports, provenance and raw-file hashes. New test-only stage
diagnostics retain the last bounded operation state on timeout; all existing
connection and cycle deadlines remain unchanged. A deliberately stalled TLS peer
checks diagnostic attribution, preservation of the original timeout and cleanup:

```sh
python3 tests/soak/test_network_failure.py --output build/new-network-failure
```

The C `tests/soak/epoll_latency.c` control measures five-millisecond waits without
PHP/Asyncara. It prints delays over 50 ms and a completion summary. Its reporting
threshold is diagnostic, not a replacement for any soak assertion. Commands:

```sh
cc -std=c11 -D_POSIX_C_SOURCE=200809L -Wall -Wextra -Werror -O2 tests/soak/epoll_latency.c -o build/epoll-latency
build/epoll-latency 300 > build/new-epoll-latency.jsonl
```

## UDP repairs and final-source boundary

The local 76e6557 mixed/FPM campaigns were deliberately interrupted before
repairing independently reproduced UDP destination validation defects. Last
completed samples were 972.031s / 2,635 cycles and 997.320s respectively; neither
completed one hour. Preserve phase4/campaign-76e6557-interrupted.json and its raw
files. The strict destination fix has a separate 60.119s / 166-cycle expanded
mixed pass. Compilation overlapped that smoke; it is not a performance result.
No six-/24-hour stage has run. A new immutable commit/archive must start a new
campaign. Existing thresholds remain unchanged.

Historical hosted FPM at 624cbe9 passed its full hour; its simultaneous mixed job
failed at UDP receive. The hosted run predates both UDP repairs and cannot certify
the corrected source. Current campaign identities are in PHASE4_DELIVERY.md.
