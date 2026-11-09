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
