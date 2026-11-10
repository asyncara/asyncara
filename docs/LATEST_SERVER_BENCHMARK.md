# Latest server benchmark — Asyncara versus Go fasthttp

**Requested performance acceptance: NOT_MET.** The server's functional checks
pass, but it does not match or exceed the supplied Go workload in every measured
case. A median above13,000/s in some cases is not a13,000/s floor. The server
remains EXPERIMENTAL. These are server measurements, separate from the older
curl/Hyper/Swoole HTTP-client comparisons.

Source `195f0888f6559ca80a72e637d551c5344f5cd5f4`; native module SHA-256
`0b4b43be8bbe02a742277a8e8e706065855b29df6a67258f2c4325dfa45bb423`.
PHP8.5.12 NTS with OPcache/tracing JIT; Go1.27.3 and fasthttp1.78.0. Linux,
six available CPUs; Asyncara six interpreter processes plus supervisor, Go
GOMAXPROCS6. Same host,128 persistent connections, two wrk threads,2s warmup and
5s measured load, three fresh-process samples. Engine order alternates by sample.
All responses are validated for exact body and status, and all servers exit0.
The raw report's PASS means response/lifecycle validation, not a speed victory.

Both handlers execute routing, query extraction and dynamic JSON generation for
every request. Both use the same immutable prewarmed payload cache (10/1024/65536
bytes); no complete reply cache. The reference fixes concurrent writes to the
user-supplied Go map, retains fmt.Fprintf, and uses a private port. Asyncara's
benchmark uses the same `/work?id=42` route as Go; dynamic parameter routing,
middleware, POST, TLS and streaming are not throughput-certified by this test.
The configured connection caps differ (4096 versus250000); active concurrency is
128 throughout and neither cap is saturated. Maximum connection capacity is not
being compared.

The host is shared: CPU pressure and sample spread are material. Each row's raw
resources.json records CPU pressure before/after. This is not a dedicated-host
capacity certification or proof of a general speed advantage. No host scheduling
settings were changed and no other Asyncara test/build ran alongside these loads.

## Requests per second

| Backend | Payload | Delay | Go median | Asyncara median | Asyncara/Go | Go min–max | Asyncara min–max |
|---|---:|---:|---:|---:|---:|---:|---:|
| epoll | 10 B | 0 ms | 18,598.56 | 12,772.56 | 0.687× | 7,380–20,595 | 6,519–22,987 |
| epoll | 1,024 B | 0 ms | 19,520.51 | 13,972.86 | 0.716× | 10,118–24,432 | 6,951–17,824 |
| epoll | 65,536 B | 0 ms | 3,076.44 | 4,805.69 | 1.562× | 1,640–5,744 | 4,539–5,951 |
| poll | 10 B | 0 ms | 20,931.49 | 17,231.43 | 0.823× | 14,590–27,107 | 10,690–17,500 |
| poll | 1,024 B | 0 ms | 14,186.21 | 13,794.13 | 0.972× | 11,316–22,572 | 11,053–17,647 |
| poll | 65,536 B | 0 ms | 3,513.61 | 4,558.19 | 1.297× | 3,372–4,438 | 4,487–6,165 |
| epoll | 1,024 B | 5 ms | 7,112.04 | 6,539.14 | 0.919× | 5,830–8,150 | 5,860–6,882 |

## Latency and resource observations

Values below are medians of the three run-level observations, not pooled latency
percentiles. Latency is in milliseconds. CPU seconds sum the server process tree
for a measured interval of approximately5s. Peak RSS is the sum of process RSS,
so PHP shared pages are counted repeatedly; it is not PSS or uniquely owned RAM.
It must not be used as a physical-memory efficiency ranking.

| Backend/payload/delay | Engine | p50 ms | p95 ms | p99 ms | CPU s | Summed peak RSS MiB |
|---|---|---:|---:|---:|---:|---:|
| epoll/10/0 | Go-fasthttp | 3.41 | 47.20 | 101.61 | 6.56 | 13.52 |
| epoll/10/0 | Asyncara-6 | 6.20 | 124.18 | 233.23 | 9.13 | 123.54 |
| epoll/1024/0 | Go-fasthttp | 3.36 | 97.42 | 213.93 | 6.26 | 13.86 |
| epoll/1024/0 | Asyncara-6 | 5.34 | 94.91 | 201.04 | 10.78 | 125.70 |
| epoll/65536/0 | Go-fasthttp | 25.46 | 320.66 | 436.61 | 4.87 | 56.53 |
| epoll/65536/0 | Asyncara-6 | 16.47 | 106.53 | 225.84 | 10.93 | 133.50 |
| poll/10/0 | Go-fasthttp | 3.32 | 37.64 | 65.65 | 7.34 | 13.92 |
| poll/10/0 | Asyncara-6 | 4.94 | 61.81 | 130.38 | 9.36 | 123.28 |
| poll/1024/0 | Go-fasthttp | 5.00 | 54.15 | 104.68 | 7.04 | 13.26 |
| poll/1024/0 | Asyncara-6 | 5.60 | 88.52 | 259.04 | 8.67 | 126.54 |
| poll/65536/0 | Go-fasthttp | 22.38 | 215.26 | 440.47 | 6.26 | 52.93 |
| poll/65536/0 | Asyncara-6 | 16.46 | 93.00 | 139.67 | 9.78 | 134.76 |
| epoll/1024/5 | Go-fasthttp | 11.35 | 62.79 | 166.28 | 5.28 | 12.55 |
| epoll/1024/5 | Asyncara-6 | 13.30 | 100.05 | 205.22 | 9.31 | 126.54 |

## Reproduce and inspect

Run `python3 benchmarks/server/compare.py --help`. Supply the matching PHP,
server extension, posix extension and separately built Go reference, plus a new
output directory. `--workers 6 --samples 3 --seconds 5` reproduces each no-delay
matrix; use `ASYNCARA_BACKEND=poll` for poll, or `--delay 5 --sizes 1024` for the
suspending case. Build the Go source in benchmarks/server/go using its pinned
module files. Each command is recorded verbatim with raw logs and fingerprints.

[Machine-readable results and hashes](phase7/server-benchmark.json) link all raw
artifacts under `/home/coder/Asyncara-server-evidence-20261109T232945Z/compare-r3-*`.
Earlier `compare-r1`, `compare-r2`, and `compare-final-*` directories are preserved
as preceding-source/exploratory evidence; they are not results for195f088.

This milestone delivers a working explicit server API and verified lifecycle
corrections. Meeting the all-cases Go performance condition remains unfinished.
Future optimization needs profiling and equivalent-workload evidence; caching
entire replies or bypassing PHP handlers would change the requested workload.
