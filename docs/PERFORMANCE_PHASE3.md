# Phase 3 performance samples

Recorded 2026-11-02T20:59:02.051971+00:00; Linux shared host, 6 CPUs, PHP8.5.11 NTS. Native binary SHA-256 `d861852d0e0a55814ac174beefafa0b3e488959880c80a45389ee161317c5eab`. All three samples per case are retained in [phase3.json](../benchmarks/results/phase3.json); the earlier latest.json and report are preserved. Concurrent soak/build activity means these are development observations, not isolated regression attribution or capacity guarantees.

| Case | Sample seconds (all three) |
|---|---|
| sequential-http | 0.67742 / 0.73731 / 0.80119 |
| async-http | 0.17621 / 0.18435 / 0.19943 |
| tasks | 0.12237 / 0.09254 / 0.09352 |
| timers | 0.10066 / 0.08413 / 0.07505 |
| amp-timers | 0.13937 / 0.10611 / 0.11350 |
| sequential-files | 0.00364 / 0.00304 / 0.00263 |
| files | 0.02903 / 0.02163 / 0.04762 |
| sqlite | 0.12527 / 0.17740 / 0.16010 |
| queue | 0.03602 / 0.07818 / 0.03978 |
| sequential-cpu | 0.45253 / 0.52264 / 0.55112 |
| cpu | 0.23721 / 0.28563 / 0.37642 |
| native-curl-http | 0.18745 / 0.15386 / 0.16016 |
| php-fpm-4-workers | 0.19968 / 0.17058 / 0.17557 |
| swoole-http | 0.16766 / 0.16535 / 0.19435 |

For 24 HTTP requests / four active, Asyncara median wall time was 0.18435 s; sequential PHP cURL 0.73731 s, native curl multi 0.16016 s, FPM four workers 0.17557 s, Swoole 0.16766 s. AMP timer and other workloads remain separate comparisons. These small samples do not establish a stable ranking. All latency distributions and memory/CPU data actually collected remain in the JSON.

Atomic file comparison: 100 sequential 64 KiB worker writes per sample; three samples for each mode. Ordinary write, atomic visibility and durable atomic replacement have different guarantees. No cold-cache or power-loss testing occurred.

| Mode | Median latency range, ms | p95 range, ms | p99 range, ms |
|---|---|---|---|
| write | 1.068–1.439 | 2.763–4.003 | 4.489–5.985 |
| atomic | 0.728–1.141 | 2.430–5.540 | 3.347–7.254 |
| durable | 7.323–8.999 | 14.353–22.808 | 29.108–31.208 |

[Raw atomic samples, including every operation latency](../benchmarks/results/phase3-atomic.json). Reproduce with `./benchmarks/run.py --output benchmarks/results/NEW.json` and `php -n -d extension=$PWD/build/asyncara.so benchmarks/atomic.php`.

No HTTP streaming, WebSocket, io_uring or broad platform benchmark is claimed because those implementations are absent.
