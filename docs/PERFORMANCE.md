# Measured performance

## Native reliability feedback measurements (November 7, 2026)

Final native source `9bee5c3`, Ubuntu 24.04.5 / Linux 6.8.0-147, six available
EPYC virtual CPUs, GCC 13.3, PHP 8.5.12 NTS. The module and complete environment
are identified in [the program report](NATIVE_RELIABILITY_PROGRAM.md). These are
validation-workload measurements, not application throughput benchmarks.

| Completed workload | Wall seconds | Child CPU seconds | Explicit work |
|---|---:|---:|---|
| Gate A | 200.729 | 191.967 | Three seeds, epoll/poll; real kernel, virtual scheduler, native allocation/notification/close/budget regressions |
| Gate B native wait driver | 14.659 | 11.197 | 350,004 actual kernel waits, about 23,876 waits/s; 50,000 injected failures excluded |
| Complete Gate B | 822.312 | 705.672 | Native waits, seeded kernel/worker checks, short FPM integration, independent review |
| B2 mixed FPM interval | 600.019 | See scope below | 8,240 main responses, 13.733 responses/s; 395,520 extra pending reads, 49,440 extra timer firings |

A+B takes 1,023.041 seconds (17.051 minutes) on this host. B1's native and worker
portion takes approximately 208.1 seconds including compilation; the entire B
scope includes fixture startup, fault checks, shutdown and independent analysis.
The FPM command CPU total includes the child process tree; the runner's own
`gate.json` CPU figure uses its narrower scope and must not be interchanged with
it. Child CPU can exceed wall time in parallel workloads. Shared-host scheduling
and per-command process accounting limit comparisons.

The old 7,980-response/600.027s result remains INCOMPLETE for its original
200,000-main-response target. The revised gate changes the coverage target to
measured native operations plus separate real FPM integration. It does not claim
a 25-fold HTTP speedup or rename native transitions as HTTP requests. Dense FPM
adds work and interleavings while retaining all original progress assertions;
raw throughput is not an equivalent-workload performance comparison.

The native correction has a deterministic before/after *semantic* comparison:
the old wait restarted a 5ms budget after 20ms of controlled setup time; the new
wait requests 0ms when that budget is exhausted. This is not a measured general
latency speedup. No production sleep, spinning or weaker limit was introduced.
The separately justified Gate C observes residual scheduling and resource trends
and is not part of every edit's fast feedback loop. No short gate certifies
six-hour or twenty-four-hour endurance.

## Lifecycle continuation regression probe (November 7, 2026)

The new lifecycle checkpoint reran `scripts/audit.py` on PHP8.5.12 with epoll.
Its1,000-sample/64-yielding-task latency probe measured median0.519ms,
p953.796ms, p9918.464ms and maximum56.303ms timer lag. It also retained three
independent samples each for1,000 and10,000 Tasks. Raw results are under
`/tmp/asyncara-lifecycle-20261106T231254Z/independent-audit/`; file hashes and
commands are in [lifecycle-hardening.json](phase4/lifecycle-hardening.json).

This is regression evidence on a shared host, not an HTTP/database/filesystem
throughput comparison or a claim of improvement. The cancellation-drain repair
prioritizes completed resource cleanup over early Task completion. No allocator,
pool or timing threshold was relaxed. Broader equivalent-workload benchmarks
remain deferred while the local FPM reliability gate is unresolved. The earlier
measurements below keep their original source/environment and are not relabelled
as results for this checkpoint.

Recorded 2026-11-02T17:11:32.545674+00:00. These are development measurements from a shared VM, not production capacity numbers. The complete [raw results](../benchmarks/results/latest.json) retain all samples and source/artifact provenance. Historical invalid measurements are identified in [the provenance note](../benchmarks/results/README.md).

## Environment and reproduction

Ubuntu 24.04.5, Linux 6.8.0-147, x86_64 KVM, six visible AMD EPYC cores, about 12 GiB RAM. PHP 8.5.11 NTS, GCC 13.3, curl 8.5.0, SQLite 3.45.1, OpenSSL 3.0.13. Asyncara uses epoll and four lazy native workers; optional libpq and MariaDB drivers are compiled. Composer.lock pins AMP 3.1.3 / Revolt 1.0.9; the installed Swoole baseline is 6.2.3. CLI cases use `php -n`, only explicitly loaded extensions and a 1 GiB PHP limit for Fiber workloads. The isolated FPM baseline runs four static workers.

```sh
make build
php -d extension="$PWD/build/asyncara.so" /usr/local/bin/composer install
./benchmarks/run.py
python3 benchmarks/report.py
```

The runner checks binary freshness, starts its own Python HTTP/TLS fixture and stops it afterward. FPM uses a temporary project-owned pool, not installed service configuration. No system-wide tuning, CPU pinning, cold-cache controls or load isolation was applied. The tested artifact SHA-256 is `02b89b3b472024e371167c4ac507ac1f8bb6b879faad9deb89b851fbbb041171`; the JSON also records the complete native/userland source hash manifest.

## All wall-time samples

Times are seconds. Throughput is the median of the three per-sample operation rates; it is not a server capacity claim.

| Workload | Sample 1 | Sample 2 | Sample 3 | Median operations/s |
|---|---:|---:|---:|---:|
| Sequential PHP cURL, 24 HTTP requests | 0.59675 | 0.81832 | 0.72482 | 33.1 |
| Asyncara, 24 HTTP requests / four active | 0.31241 | 0.17027 | 0.26320 | 91.2 |
| 1,000 tasks with one zero-delay yield | 0.15602 | 0.09669 | 0.09181 | 10342.6 |
| 1,000 Asyncara tasks with 5 ms timers | 0.13091 | 0.18438 | 0.11080 | 7638.9 |
| 1,000 AMP tasks with 5 ms timers | 0.13850 | 0.12271 | 0.12092 | 8149.6 |
| 200 sequential cached 16 KiB reads | 0.00877 | 0.00550 | 0.02555 | 22814.9 |
| 200 worker-backed 16 KiB reads / four tasks | 0.03602 | 0.02793 | 0.03485 | 5738.6 |
| 200 serialized in-memory SQLite queries | 0.17708 | 0.21999 | 0.23421 | 909.2 |
| 10,000 bounded queue transfers | 0.02923 | 0.02372 | 0.02720 | 367629.6 |
| Four sequential CPU jobs | 0.54121 | 0.56724 | 0.48681 | 7.4 |
| Four ProcessPool CPU jobs / four workers | 0.25533 | 0.31690 | 0.26427 | 15.1 |
| Native curl multi, 24 HTTP requests / four active | 0.15687 | 0.28891 | 0.17976 | 133.5 |
| PHP-FPM, 24 requests / four workers | 0.27653 | 0.42829 | 0.28301 | 84.8 |
| Swoole, 24 HTTP requests / four active | 0.21366 | 0.21417 | 0.15874 | 112.3 |

## Operation latency

Ranges below retain the minimum and maximum measured statistic across all three runs, in milliseconds. Percentiles use floor((n−1)×p) order statistics without interpolation. HTTP has only 24 observations per sample and CPU only four, so p95/p99 are descriptive sample values, not reliable tail estimates. Queue per-item latency was not measured and is not invented.

| Workload | Median range | p95 range | p99 range |
|---|---:|---:|---:|
| Sequential PHP cURL, 24 HTTP requests | 23.907–26.444 | 27.518–59.576 | 30.642–61.153 |
| Asyncara, 24 HTTP requests / four active | 26.588–38.401 | 30.430–76.336 | 31.921–89.396 |
| 1,000 Asyncara tasks with 5 ms timers | 44.035–134.139 | 46.932–137.055 | 60.706–137.215 |
| 200 worker-backed 16 KiB reads / four tasks | 0.362–0.434 | 1.357–2.173 | 1.850–4.246 |
| 200 serialized in-memory SQLite queries | 0.481–0.570 | 2.452–3.290 | 3.252–10.626 |
| Native curl multi, 24 HTTP requests / four active | 23.465–32.862 | 29.828–94.727 | 30.410–96.489 |
| PHP-FPM, 24 requests / four workers | 31.471–37.972 | 44.644–80.783 | 52.284–99.457 |
| Swoole, 24 HTTP requests / four active | 23.457–32.683 | 29.299–60.759 | 29.814–65.462 |

## Interpretation and limits

- HTTP cases use the same local HTTP/1.1 response and 20 ms upstream delay. Four active requests are used for concurrent clients and FPM; connection reuse is disabled for matched baselines. Every client checks the status/body. The sequential case necessarily serializes the delays. FPM timings also include FastCGI/Python client overhead, while direct clients do not. These measurements do not rank production PHP server architectures or test keep-alive performance.
- Cached small file reads are faster synchronously in this run. Native-worker submission, copies, wakeups and Fiber scheduling add overhead; keeping the loop responsive is the benefit, not a guarantee of faster cached I/O. The benchmark does not simulate slow disks or concurrent unrelated latency-sensitive work.
- CPU jobs perform 500,000 SHA-256 iterations each. ProcessPool wall time includes process startup, IPC and shutdown. Distinct PIDs and overlapping CPU execution intervals are separately asserted by integration tests. Shared VM scheduling makes speedup variable; it does not change the execution model into cooperative CPU concurrency.
- Task/timer cases include creation, scheduling, one suspension and join. Their total is not an isolated task-creation or context-switch cost. Timer lateness includes ready-queue processing and host scheduling; no zero-lag claim is made. AMP/Revolt is a timer baseline, not a full HTTP/DB comparison.
- Raw PHP samples include parent CPU time, maximum resident set size, voluntary/involuntary context switches and Asyncara's measured maximum task-step/timer lag. Maximum RSS is a process lifetime high-water mark, not a per-sample allocation delta; parent CPU/RSS exclude ProcessPool children and the fixture servers. Native/FPM/Swoole cases do not claim equivalent CPU/RSS instrumentation. End-of-run active counts are not peak connection measurements.
- Three samples, fixed case order, no independent warmup phase, tiny local payloads and a shared host limit statistical conclusions. All samples, including slower results, are retained. Extended TCP/large-file/database throughput matrices, cold-cache tests, externally driven load, sustained tail latency and cross-host repeatability remain unfinished.

## Changes justified by measurements

The repeated networking Valgrind workload originally requested about 25.7 GB of cumulative allocator sizes while resizing bounded HTTP buffers on every received chunk. Geometric growth reduced that same workload to 150,226,313 cumulative bytes, with zero memory errors/lost allocations. These are cumulative allocation requests under instrumentation, not peak RSS or a claimed application speedup. Small file reads also allocate from actual file size with bounded growth instead of an unconditional 16 MiB temporary buffer.

The 100-cycle mixed file/SQLite/queue stress run retained seven descriptors after lazy initialization (five beforehand), and PHP live memory samples remained within about 4 KiB, including the sample array itself. The 100-request single-worker FPM test retained six idle descriptors and one idle thread, with an RSS plateau. These bounded tests support lifecycle correctness; they are not a long-duration leak or production-readiness proof.

Later Phase 3 results are recorded separately in [PERFORMANCE_PHASE3.md](PERFORMANCE_PHASE3.md); the historical measurements above are preserved.

## Completed extended candidate observation

Final-source Gate C takes 2136.317s wall / 1549.862s
child CPU, including its larger kernel/worker prelude and independent review.
Its 1800.190s mixed FPM interval yields 24,740 main
responses (13.743/s). No comparative throughput claim is
made from this shared-host validation workload. The raw-derived latency and
resource distributions are in `phase5/native-program-validation.json`; timer lag
includes host and application scheduling. The extended gate remains separate
from the approximately 17-minute normal A+B feedback cycle.

Raw-response distributions use floor((n−1)×p) order statistics, without
interpolation. Milliseconds:

| Measurement | B median | B p95 | B p99 | B max | C median | C p95 | C p99 | C max |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| Main request wall interval |229.335|297.081|404.269|945.297|233.251|299.914|380.778|1012.330|
| I/O work monotonic interval |228.738|290.987|381.945|926.105|233.122|291.585|369.054|768.411|
| Per-request maximum timer lag |6.712|42.651|99.701|493.844|7.629|37.159|83.935|420.771|
| Per-request maximum Task step |3.046|19.423|51.253|469.953|4.483|22.187|49.565|284.740|

Main intervals include pending-shutdown requests; monotonic I/O intervals and
scheduler statistics cover only the 6,180/18,555 I/O requests. These are
distributions of each request's maximum lag/step, not individual timer latencies.
Wall timestamps alone cannot prove independent worker chronology. Passing runs
still show substantial scheduling delays; there is no real-time guarantee.
