# HEAD upload framing correction

Starting native source: `09b47672644daeae8157b90a47116c0b92776b57`.
Evidence: `/home/coder/Asyncara-http-epoll-20261109T164557Z`.
Release remains EXPERIMENTAL; performance requirements remain NOT_MET and the
separate epoll candidate B2 failure remains FAIL.

`HttpClient::requestStream('HEAD', ...)` used `CURLOPT_NOBODY` followed by
`CURLOPT_UPLOAD` and `CURLOPT_CUSTOMREQUEST=HEAD`. The server correctly sends
HEAD metadata without a body, but upload-mode response framing can wait for the
advertised body length. The old binary times out for known zero/five-byte
uploads; an unknown-length control returned14 bytes of a later error response
as a HEAD body. `head-original-r2/stdout.log` preserves these observations.
Ordinary buffered HEAD did not become POST in the tested curl8.5.0 control;
that initial hypothesis was rejected. Nonempty buffered content was silently
ignored and is now explicitly unsupported.

The native submission validator rejects nonempty HEAD content and every HEAD
upload (including zero/unknown length) before submission. The C backend also
rejects native callers before adding an easy handle to multi. Empty buffered
HEAD skips POSTFIELDS configuration. No network wait is changed. Invalid
upload producers/files are never opened; permits and temporary allocations
follow existing error cleanup. Null/empty buffered HEAD retains metadata,
bodyless completion and connection reuse.

Relevant library semantics:
[NOBODY](https://curl.se/libcurl/c/CURLOPT_NOBODY.html),
[UPLOAD](https://curl.se/libcurl/c/CURLOPT_UPLOAD.html),
[HTTP HEAD](https://www.rfc-editor.org/rfc/rfc9110.html#section-9.3.2).
This is an explicit supported-method/content policy, not universal HEAD server
compatibility or certification of HTTP/2/3.

Focused evidence before the broader matrix:

- `head-regression-original-r2`: original PHP test FAILS with transfer timeout.
  The first test draft incorrectly paired zero length with a nonempty producer;
  its unrelated size-validation failure is retained as `head-regression-original`.
- `head-native-baseline-run`: previous C implementation exits2 because HEAD
  uploads enter multi. This is the expected negative control, not a passing old
  implementation.
- `head-native-run`: corrected C passes1000 alternating content/upload
  rejections with zero multi registrations and zero remaining payload ownership.
- `head-regression-epoll` and `head-regression-poll`: corrected PHP8.5.12 passes
  rejection before transport/producer, bodyless wire metadata, handle reuse,
  two ticks during pending HEAD, and native cleanup.

Tests: `tests/http/method_native.c`, `methods.php`, `method_server.py`; all are
wired into `tests/http/run.py`. Broader compatibility and memory results must
be recorded separately after actual completion. The previous source archives
do not contain this correction.

## Completed validation of 84e49f1

Native source: `84e49f137396c58e0954f2ba90dc8dd61df2615e`. PHP8.5.12 module:
`build/http-head-r1-85.so`, SHA-256
`0feff803646dca9afa566e59ed99c652c985807a391acbb937e3fd5b87d011de`.
`head-source.json` records the exact source/test manifest. The compatibility,
memory and short FPM checks below completed with that manifest unchanged.

| Validation | Result | Evidence under the evidence root |
|---|---|---|
| PHP8.3.36 /8.4.27 /8.5.12 HTTP, epoll and poll | PASS | `head-http-{83,84,85}-data/report.json` |
| DEBUG8.5.12 HTTP, epoll and poll | PASS | `head-http-debug-data/report.json` |
| GCC ASan/UBSan8.5.12 HTTP, epoll and poll | PASS | `head-http-gcc-data/report.json` |
| Full PHP8.5 suites, epoll and poll | PASS | `head-full-r2-{epoll,poll}/result.json` |
| Gate A, three seeds and10000 iterations/path | PASS,215.997s inner /216.272s outer | `head-gate-A-data/report.json` |
| Native HEAD and allocation-failure ASan/UBSan | PASS | `head-{methods,allocations}-asan-run/result.json` |
| Native HEAD Valgrind | PASS: zero errors or lost bytes;56 bytes reachable | `head-native-valgrind/stdout.log` |
| DEBUG HEAD Valgrind, both backends | PASS: zero errors or lost bytes;3888 bytes reachable | `head-debug-valgrind-{epoll,poll}/stdout.log` |
| Short curl and Hyper feature FPM fixtures | PASS:60 main requests each,1/4 workers | `head-short-fpm-independent.json` |

The first full-suite invocation supplied an absolute extension path to the
relative-path-only `scripts/test.sh`, so PHP did not load the module. Its original
FAIL is retained in `head-full-epoll`; the corrected invocation uses
`ASYNCARA_EXTENSION=build/http-head-r1-85.so`. No assertion or runtime source was
changed to obtain the repeated result.

Independent short FPM recalculation finds minimum18/23 ticks for curl's one/four
worker cases and19/26 for Hyper. Each idle sample retains6 descriptors,1 thread
and1 socket. Main responses have no pending native operations or tracked payload
memory. These fixtures test existing feature lifecycle; they do not specifically
exercise HEAD under FPM and do not replace the failed mixed B2 campaign.

No suppressions were used. Rust code was not ASan-instrumented; a scoped C/PHP
sanitizer pass is not complete Rust coverage. The distribution-PHP cyclic-shutdown
allocation discrepancy remains separate and unchanged. HEAD framing is corrected
in the tested scope; the historical zero-tick and current B2 one-tick causes
remain unresolved. No speed improvement follows from this correctness change.

## Additional HEAD-specific FPM validation

After the preceding unchanged-fixture run, `tests/http/fpm_request.php` gained
explicit HEAD upload rejection before producer execution and valid HEAD metadata /
empty-body checks. `tests/fixtures/http_stream_server.py` supplies HEAD metadata
without a body. No runtime source, original overlap/two-tick assertion, historical
Phase4 fixture, or resource limit changed.

`head-fpm-methods-data/report.json` passes20 sequential main requests through one
worker and40 through four workers, plus existing fatal/reset probes. Independently
checked raw responses show HEAD status200, zero body bytes, no rejected producer
execution, minimum21/16 ticks, and native cleanup. Evidence and hashes:
`head-fpm-methods-independent.json`. This is scoped PHP8.5.12 epoll FPM evidence
for the HEAD fix; it does not convert the separate B2 failure into a pass.
