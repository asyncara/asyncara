# Latest HTTP benchmark

Date: **2026-11-09**. Release: **EXPERIMENTAL**.
These are measured results, not performance targets. The requested minimum
13,000 requests/s, 4x PHP multi curl and 3x Swoole are **NOT_MET**.

This is the last complete five-sample four-client comparison of the checkpoint
identified below. Later receive-geometry and Hyper safety changes in this worktree
and worker-resolved DNS are **not measured by this table**. Their three-sample screen and corrections are
distinguished in [HYPER_SAFETY_REVIEW.md](HYPER_SAFETY_REVIEW.md). The historical
measurements remain unchanged; no speed target has newly passed. A separate,
earlier rejected experiment is retained below.

## Public API throughput

Requests per second; median and minimum of **five independent samples**.
Each response's status and complete body were checked. These are client throughput
measurements against a local server, not Asyncara HTTP server benchmarks.

| Client | 1 KiB median req/s | 1 KiB minimum req/s | 64 KiB median req/s | 64 KiB minimum req/s |
|---|---:|---:|---:|---:|
| Asyncara By C | 6,762.67 | 6,042.29 | 2,860.03 | 2,577.14 |
| Asyncara By Rust | 9,301.92 | 4,257.91 | 4,970.34 | 3,626.29 |
| PHP multi curl | 8,730.99 | 7,038.85 | 5,537.47 | 4,048.47 |
| Swoole | 7,813.95 | 7,205.84 | 5,792.46 | 5,126.07 |

The minimum is the slowest observed sample, not a guaranteed throughput floor.
The five samples are separate runs; no best sample is substituted for the median.

## What each label means

| Label | Actual measured implementation |
|---|---|
| Asyncara By C | Public `HttpClient::get()->await()`, Asyncara's PHP runtime and C extension, with the default **libcurl multi event-driven** HTTP backend (`ASYNCARA_HTTP_BACKEND=curl`). |
| Asyncara By Rust | The same public PHP API/runtime and C bridge, with the opt-in **Rust Hyper HTTP/1.1 prototype** (`ASYNCARA_HTTP_BACKEND=hyper-prototype`). This is not a standalone Rust benchmark or a wholly Rust runtime. |
| PHP multi curl | Direct PHP ext-curl `curl_multi_*` loop, with persistent handles and checked response bodies. |
| Swoole | `Swoole\Coroutine\Http\Client` using persistent connections and coroutine workers. |

The Rust prototype supports numeric-address plaintext buffered GET in this scope.
It does **not** provide the default C/curl backend's DNS, HTTPS, custom headers,
redirects, streaming or uploads. The throughput comparison exercises their common
plaintext GET case; it does not establish equal feature coverage. Neither label
means that all Asyncara work is kernel-asynchronous.

## Identical workload and environment

- PHP **8.5.12 NTS**, tracing JIT enabled for every PHP client; libcurl **8.5.0**;
  Swoole **6.2.3**, built for the matching PHP SDK. Reference module hashes were
  verified against the recorded build attestation.
- nginx **1.31.7**, four isolated server workers, loopback TCP, HTTP/1.1 keep-alive;
  identical uncompressed 1 KiB or 64 KiB bodies; TLS is not part of this benchmark.
- **32 rolling connections**, **16,000 measured responses/sample**, plus
  **320 warm-up requests/sample**, five serialized, rotated samples/client/body.
  Client and server connection limits/configuration are fixed across samples.
- Asyncara uses **poll**. PHP multi curl and Swoole use their respective existing
  wait/reactor implementations; they are not forced through Asyncara's Event Loop.
- The four displayed clients total **640,000 measured responses** and **12,800
  warm-ups**. The complete raw run also includes an older Asyncara baseline:
  **800,000 measured responses**, **16,000 warm-ups**, 50 samples in total.
- Recorded host: Linux 6.8.0-147-generic, x86_64, glibc 2.39, six logical CPUs with
  affinity 0-5. Scheduling/host-load variability is not removed or attributed to a
  specific cause. No host tuning was used to obtain a desired result.
- Ordinary public Task/Future/Response behavior is included. Raw native calls,
  isolated header conversion and standalone Rust rates are not substituted.

## All five throughput samples

Values are req/s, shown in sample-index order within the rotated execution.

| Client | Body | Sample 1 | Sample 2 | Sample 3 | Sample 4 | Sample 5 |
|---|---|---:|---:|---:|---:|---:|
| Asyncara By C | 1 KiB | 6,762.67 | 7,274.70 | 6,042.29 | 7,380.35 | 6,347.59 |
| Asyncara By Rust | 1 KiB | 9,561.92 | 4,257.91 | 9,301.92 | 9,586.41 | 6,511.74 |
| PHP multi curl | 1 KiB | 9,049.47 | 7,516.26 | 7,038.85 | 10,765.52 | 8,730.99 |
| Swoole | 1 KiB | 8,889.56 | 7,813.95 | 7,205.84 | 7,308.20 | 9,466.95 |
| Asyncara By C | 64 KiB | 2,577.14 | 2,750.44 | 3,081.45 | 3,167.49 | 2,860.03 |
| Asyncara By Rust | 64 KiB | 3,626.29 | 4,381.97 | 4,970.34 | 5,134.24 | 5,736.47 |
| PHP multi curl | 64 KiB | 5,537.47 | 4,048.47 | 5,265.46 | 6,786.75 | 6,634.66 |
| Swoole | 64 KiB | 5,893.83 | 5,646.05 | 5,126.07 | 5,792.46 | 7,532.23 |

## Client CPU cost

Median client CPU microseconds per measured response (user + system); these
figures exclude server CPU. They are not latency percentiles or wall-clock time.

| Client | 1 KiB CPU us/response | 64 KiB CPU us/response |
|---|---:|---:|
| Asyncara By C | 140.55 | 325.02 |
| Asyncara By Rust | 96.27 | 189.01 |
| PHP multi curl | 101.15 | 162.87 |
| Swoole | 101.58 | 156.59 |

## Requested relative performance

Ratios of the median **public Asyncara By Rust** rate to each reference median
in this same run:

| Body | / PHP multi curl | / Swoole | 13,000 floor + 4x/3x requirements |
|---|---:|---:|---|
| 1 KiB | 1.065x | 1.190x | NOT_MET |
| 64 KiB | 0.898x | 0.858x | NOT_MET |

The default C/curl variant also fails these requirements. There is no evidence
here for a fastest-PHP claim, universal throughput guarantee, or production
readiness. Functional test success and a reproducible package do not establish
performance acceptance or resolve the retained FPM progress failures.

## Later experiment, excluded from the selected source

The isolated small-initial-buffer experiment `9eea780` is **DO_NOT_ADOPT** and is
not merged into this branch. Its own five-sample comparison measured:

| Implementation in that later run | 1 KiB median req/s | 64 KiB median req/s |
|---|---:|---:|
| Selected Asyncara By Rust, used as its baseline | 8,783.95 | 4,990.34 |
| Rejected small-buffer Rust candidate | 8,826.87 | 4,519.07 |
| PHP multi curl | 9,330.08 | 5,026.32 |
| Swoole | 11,783.10 | 6,186.40 |

Its C/curl sample used the rejected binary, so it is not presented as a new
four-client measurement of the selected source. This later run also shows that
relative rankings vary; the primary table's small-body Rust lead cannot be
generalized. The rejected change reduced small-body tracked capacity but did not
establish a useful overall speed improvement. Its original measurements remain
under `/home/coder/Asyncara-http-small-buffer-evidence-20261109T194455Z/comparison-data/`.

## Source, evidence and reproduction

- Selected development checkpoint before this document:
  `60ae4f4343bef1affd7264b8241bc35134cab648`.
- Measured native checkpoint: `b1cfd18a97eb9962761ba1c4c2fad0bad3ef25bb`;
  measurement checkout: `9a59bef23a584ed1b6a0702f380be5ba5c690b19`.
  Subsequent selected changes affect tests/docs, not these native/PHP runtime or
  benchmark sources.
- Measured module SHA-256:
  `435da35dd414bfd2e756006b02e6ee142586037e02c7a0975fa0a1367ae67bb5`.
- Complete raw report (local preserved artifact):
  `/home/coder/Asyncara-http-capacity-evidence-20261109T190638Z/comparison-data/report.json`.
  SHA-256: `6a0bef51a2660c525c874eb6f9c889ed6849bddb5e96e08ef14b58a6d78b1bc2`.
- All 50 individual log hashes and the native module/source fingerprints were
  rechecked. The five-sample values above are embedded here for GitHub readers;
  local absolute paths are provenance, not downloadable GitHub artifacts.
- Committed [measurement summary and evidence hashes](phase6/http-capacity-evidence.json),
  [engineering report](HTTP_BUFFER_CAPACITY.md),
  [source-package attestation](phase6/http-r5-capacity-package.json).
  Package binaries have separate identities and were not the measured binaries.
- Implementations: [benchmark client](../benchmarks/http/client.php),
  [isolated comparison runner](../benchmarks/http/nginx.py).

The selected four-way measurements came from this runner configuration (replace
paths with the matching verified local toolchain; every output directory must be new):

```sh
ASYNCARA_BACKEND=poll ASYNCARA_HTTP_BACKEND=hyper-prototype \
python3 benchmarks/http/nginx.py \
  --php /absolute/php8.5.12 \
  --extension /absolute/matching-asyncara-with-hyper.so \
  --output /new/benchmark-results \
  --baseline-extension /absolute/previous-asyncara.so \
  --baseline-source-root /absolute/previous-checkout \
  --baseline-http-backend hyper-prototype \
  --php-extension-dir /absolute/matching-reference-extensions \
  --swoole-extension /absolute/matching-swoole.so \
  --reference-attestation /absolute/reference-attestation.json \
  --compare-curl --jit --server-workers 4 --concurrency 32 \
  --count 16000 --samples 5
```

Preserve all output, including slower samples and failures. A new run gets its
own source/binary identities; it must not overwrite these observations.
