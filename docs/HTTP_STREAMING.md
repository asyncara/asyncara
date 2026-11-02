# HTTP streaming status

**NOT_IMPLEMENTED**: response streaming, request-body streaming, multipart upload,
`getStream()` and `download()` are not available in this checkpoint. Do not treat
an iterable over an already buffered Response body as streaming.

The preserved HttpClient uses native curl multi/socket readiness, shared connection
limits, per-client admission, HTTP(S), headers, JSON/form/string request bodies,
redirect policy, compression, timeouts and cancellation. Body buffering is capped
at 16 MiB, headers at 64 KiB, and Asyncara-owned retained buffers participate in the
aggregate native budget. Curl/OpenSSL internal memory does not. Tests establish
concurrent slow requests and timer progress; this is asynchronous network I/O
with buffered responses.

The next implementation must extend this existing curl multi owner. It needs a
bounded native chunk queue, curl pause/resume on producer/consumer pressure,
explicit ownership through completion/cancellation, and joined cleanup on early
consumer exit. Upload callbacks must obtain file data through the worker backend,
never perform blocking file reads on the event-loop thread. Multipart should use
curl MIME facilities. Header/redirect/interim-response handling must preserve the
existing parser contract. Connection pooling must remain shared.

Acceptance tests still needed: responses larger than the buffered limit with
bounded memory; slow consumers; pause/resume races; early close; cancellation
while paused; truncated transfers; large uploads; multipart; private-CA TLS;
request shutdown with unread chunks; allocator faults and sanitizer runs.
No streaming benchmark or capability claim is published before these exist.
