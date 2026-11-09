# HTTP completion and performance investigation

Implementation candidate based on `79d142f900a6324051484b921be23c9c81c92bd4`,
branch `phase6-http`, isolated worktree `/home/coder/Asyncara-http-20261109T050419Z`.
Evidence root `/home/coder/Asyncara-http-evidence-20261109T050419Z`.

This checkpoint adds multi-file MIME, atomic streamed downloads and connection
controls to the existing native API. HTTP network waits remain curl multi
EVENT_DRIVEN_LIBRARY; file input/output remains WORKER_BACKED_ASYNC. No HTTP/2/3
certification, new hooks, io_uring or server implementation is claimed.

Final validation, performance and package attestation will be appended after
commands finish. Requested 4x curl_multi / 3x Swoole throughput is **not achieved**
in initial matched samples; no fastest-in-PHP claim is justified.

Reproducible commands (substitute local PHP/module/output paths):

```sh
PHP_BIN=/path/to/php ASYNCARA_OUTPUT=build/http.so ./scripts/build-local.sh
./scripts/prepare-test-certs.sh
python3 tests/http/run.py --php /path/to/php --extension build/http.so --output /new/http-gate
python3 benchmarks/http/run.py --php /path/to/php --extension build/http.so --output /new/comparison --samples 5 --count 8000
```

Tests require the existing development headers/libraries and the locked Composer
dependencies for the full suite. Benchmarks require ext-curl and Swoole on the same
PHP binary; missing competitors are errors, never invented results.


## Hyper evaluation scope

The owner authorized Rust Hyper when technically justified.
`benchmarks/http/hyper/` is an isolated engine experiment, not a runtime backend.
The experiment uses stable Rust APIs rather than adopting Hyper's unstable C ABI
as an unreviewed runtime dependency. It will be measured against an isolated
nginx fixture and labeled separately from PHP/HttpClient throughput.

The benchmark also has a raw Asyncara submission/collection control. It excludes
Tasks/Fibers and is diagnostic only. This separates network-engine costs from the
public API lifecycle; it does not replace the public API in the performance target.
Source: [Hyper C API status](https://docs.rs/hyper/latest/hyper/ffi/index.html).

Rust1.99.1 / Hyper1.12.0 built in an isolated toolchain after the short FPM
validation terminated. Five rotated samples of8,000 requests at concurrency8
and32 completed against nginx1.31.7. At concurrency8 with1KiB bodies, median
requests/s were Asyncara3718.3, curl_multi13613.0, Swoole16691.9, standalone
Hyper15840.6. Hyper is not integrated into PHP and these results do not justify
a backend replacement. Existing curl network and worker disk behavior remain
the implemented product backend. Raw reports: `nginx-r2-c8`, `nginx-r2-c32`,
`nginx-r2-poll8` under the evidence root. The final report will separate baseline
and corrected results, body sizes, native controls and public API measurements.
