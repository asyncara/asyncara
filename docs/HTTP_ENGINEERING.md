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
