#!/usr/bin/env python3
"""Render every recorded sample; do not select the fastest result."""
import json, pathlib, statistics
root = pathlib.Path(__file__).resolve().parent.parent
data = json.loads((root/'benchmarks/results/latest.json').read_text())
labels = {
    'sequential-http': 'Sequential PHP cURL, 24 HTTP requests',
    'async-http': 'Asyncara, 24 HTTP requests / four active',
    'native-curl-http': 'Native curl multi, 24 HTTP requests / four active',
    'php-fpm-4-workers': 'PHP-FPM, 24 requests / four workers',
    'swoole-http': 'Swoole, 24 HTTP requests / four active',
    'tasks': '1,000 tasks with one zero-delay yield',
    'timers': '1,000 Asyncara tasks with 5 ms timers',
    'amp-timers': '1,000 AMP tasks with 5 ms timers',
    'sequential-files': '200 sequential cached 16 KiB reads',
    'files': '200 worker-backed 16 KiB reads / four tasks',
    'sqlite': '200 serialized in-memory SQLite queries',
    'queue': '10,000 bounded queue transfers',
    'sequential-cpu': 'Four sequential CPU jobs',
    'cpu': 'Four ProcessPool CPU jobs / four workers',
}
text = f'''# Measured performance

Recorded {data['recordedAt']}. These are development measurements from a shared VM, not production capacity numbers. The complete [raw results](../benchmarks/results/latest.json) retain all samples and source/artifact provenance. Historical invalid measurements are identified in [the provenance note](../benchmarks/results/README.md).

## Environment and reproduction

Ubuntu 24.04.5, Linux 6.8.0-147, x86_64 KVM, six visible AMD EPYC cores, about 12 GiB RAM. PHP 8.5.11 NTS, GCC 13.3, curl 8.5.0, SQLite 3.45.1, OpenSSL 3.0.13. Asyncara uses epoll and four lazy native workers; optional libpq and MariaDB drivers are compiled. Composer.lock pins AMP 3.1.3 / Revolt 1.0.9; the installed Swoole baseline is 6.2.3. CLI cases use `php -n`, only explicitly loaded extensions and a 1 GiB PHP limit for Fiber workloads. The isolated FPM baseline runs four static workers.

```sh
make build
php -d extension="$PWD/build/asyncara.so" /usr/local/bin/composer install
./benchmarks/run.py
python3 benchmarks/report.py
```

The runner checks binary freshness, starts its own Python HTTP/TLS fixture and stops it afterward. FPM uses a temporary project-owned pool, not installed service configuration. No system-wide tuning, CPU pinning, cold-cache controls or load isolation was applied. The tested artifact SHA-256 is `{data['artifactSha256']}`; the JSON also records the complete native/userland source hash manifest.

## All wall-time samples

Times are seconds. Throughput is the median of the three per-sample operation rates; it is not a server capacity claim.

| Workload | Sample 1 | Sample 2 | Sample 3 | Median operations/s |
|---|---:|---:|---:|---:|
'''
for case in data['cases']:
    samples = case['samples']
    text += '| '+labels[case['mode']]+' | '+' | '.join(f"{s['seconds']:.5f}" for s in samples)
    text += f" | {statistics.median(s['operationsPerSecond'] for s in samples):.1f} |\n"

text += '''
## Operation latency

Ranges below retain the minimum and maximum measured statistic across all three runs, in milliseconds. Percentiles use floor((n−1)×p) order statistics without interpolation. HTTP has only 24 observations per sample and CPU only four, so p95/p99 are descriptive sample values, not reliable tail estimates. Queue per-item latency was not measured and is not invented.

| Workload | Median range | p95 range | p99 range |
|---|---:|---:|---:|
'''
for case in data['cases']:
    if case['mode'] not in ['sequential-http','async-http','native-curl-http','php-fpm-4-workers','swoole-http','files','sqlite','timers']:
        continue
    text += '| '+labels[case['mode']]
    for key in ['medianMs','p95Ms','p99Ms']:
        values = [s[key] for s in case['samples']]
        text += f' | {min(values):.3f}–{max(values):.3f}'
    text += ' |\n'

text += '''
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
'''
(root/'docs/PERFORMANCE.md').write_text(text)
