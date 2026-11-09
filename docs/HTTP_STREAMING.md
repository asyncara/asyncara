# HTTP streaming

The existing curl multi owner now supports response streams, worker-backed file
uploads/downloads, iterable request bodies and curl MIME multipart requests.
These paths share the existing client semaphore and native connection limits.
No PHP callback or blocking file read executes inside a curl callback.

```php
Async::run(function () {
    $client = new HttpClient();
    $response = $client->getStream($url);
    try {
        $head = $response->headers()->await(); // ResponseHead: status, headers
        if ($head->status !== 200) throw new RuntimeException('Unexpected status');
        foreach ($response as $chunk) processChunk($chunk);
    } finally {
        $response->close(); // cancels if necessary and joins producer cleanup
    }

    $bytes = Async::await($client->download($url, '/tmp/output.bin'));
    $reply = Async::await($client->upload($url, '/tmp/input.bin')); // PUT
    $reply = Async::await($client->multipart(
        $url, ['description' => 'sample'], 'file', '/tmp/input.bin', 'input.bin'
    ));
});
```

`ResponseStream` is a single-consumer `ReadableStream`. `consume($callback)` always
closes it, including callback failures. Ordinary foreach early exit cancels the
producer; explicit `close()` joins cleanup. Close an unconsumed stream too.
Headers normally resolve at the final header block, before body delivery. A
terminal 3xx response with redirect following enabled can defer metadata until
body delivery/completion. Informational and proxy CONNECT blocks are excluded.
Trailers are not exposed as a separate API.

## Buffering and wire semantics

Responses retain at most 64 KiB in the Asyncara native body buffer and two queued
PHP chunks of at most 64 KiB. The producer, current consumer and terminal Future
can each temporarily retain a chunk as well. Header retention is capped at 64 KiB.
The native allocation ledger covers Asyncara buffers, not curl/OpenSSL memory.
Curl pauses receiving when the bounded buffer is full; consumption resumes it.
The backend removes the paused direction from socket interest so readiness cannot
spin while the application is applying backpressure.

Streaming uses HTTP/1.1, requests `Accept-Encoding: identity`, and disables curl
content decoding. If a server sends encoded data anyway, the iterable yields
encoded bytes; inspect `ResponseHead::headers['content-encoding']`. Chunked
transfer framing is still decoded by curl. This policy avoids curl's potentially
large decompression/multiplexing buffers while paused; see its
[pause documentation](https://curl.se/libcurl/c/curl_easy_pause.html).
The existing buffered client retains automatic compression handling and its 16 MiB
body limit. HTTP/2/3 streaming is not certified.

`requestStream(method, url, iterable, contentLength: null, headers: [])` accepts
strings of at most 64 KiB per chunk. Null length uses HTTP/1.1 chunked upload;
a supplied nonnegative length is checked against data supplied while the transfer
remains active. An early server response can stop reading the source before EOF.
Native upload retention is 64 KiB; empty sources and partial callback reads work.
File uploads obtain chunks from the existing worker-backed `Files::readStream`.
An application iterable can itself perform blocking work; Asyncara cannot make
arbitrary producer code nonblocking.

`HEAD` supports `request('HEAD', $url)` with a null or empty buffered body.
Nonempty HEAD content and every HEAD streaming/file upload are rejected with
`ValueError` before native submission or starting the upload producer. Use the
bodyless request API for HEAD metadata. This explicit boundary avoids libcurl's
upload mode changing response framing while the custom wire method remains HEAD.
The regression checks the subsequent GET on the reused connection and two timer
ticks while a bodyless HEAD is genuinely pending (`tests/http/methods.php`).
This is a client capability boundary, not a claim that all private HTTP protocols
forbid HEAD request content; see [RFC9110 section9.3.2](https://www.rfc-editor.org/rfc/rfc9110.html#section-9.3.2).

Multipart uses curl MIME callbacks. `multipart()` retains the single-file API;
`multipartFiles()` accepts 1..16 file descriptors, including repeated field names,
empty files and different content types. Only the active file is opened and all
parts share one native 64 KiB upload slot. Both APIs support up to 64
scalar string fields. Each field is at most 64 KiB, all fields together at most 1 MiB;
field/file metadata rejects NUL/CR/LF and is bounded. Nested MIME,
redirect replay and resumable uploads remain unavailable. Curl-owned copies of
field metadata/content are outside the ledger but inputs are bounded. The tested
curl 8.5 MIME layer may report a nested pause after emitting boundary bytes; the
send side explicitly sets then clears its public pause state before resuming.
No library-private state is modified. See
[curl MIME callbacks](https://curl.se/libcurl/c/curl_mime_data_cb.html).

## Ownership, errors and cancellation

Transfers start eagerly when their producer Task runs. Client permits are held
until stream cleanup. `download()` creates its producer lazily inside its owned
Task, so cancelling before the Task starts does not abandon a transfer. It writes
through a worker-backed descriptor, truncates an existing destination and can
leave partial data after failure. Optional `atomic: true` stages a private 0600 file in the destination directory
and publishes only a complete body using worker-backed `renameat`.
Acquisition is shielded through assignment to the download owner; cancellation
at native stage completion still closes and discards the staged entry.
`durable: true` requires atomic mode and syncs the file before rename and the
pinned parent directory afterward. A directory-sync failure can therefore report
`FilesystemException::committed === true`: the new file is already visible.
Cancellation after rename does not undo publication. Use a trusted destination
directory; removing write permissions can prevent temporary-file cleanup and
produce an error. There is no cross-filesystem copy fallback. Existing modes and
metadata are not copied from a replaced destination. `failOnHttpError: true`
rejects non-2xx responses before writing/publishing. Default downloads still
permit error-status bodies and partial destination files.

`upload()` and `multipart()` own their lazily created file source. A caller-created
ReadableStream passed to `requestStream()` remains the caller's responsibility
until its upload producer starts; always close it in caller cleanup as well.
Once started, upload cleanup closes/join that source. Closing/cancelling never
replays a request or reverses server-side effects.

As elsewhere, `Future::await()` cancellation stops the waiter; use `Async::await`
for an owned operation whose cancellation must propagate and join, or cancel the
returned Task explicitly. Transport errors, partial transfers, source failures
and timeouts propagate. Streaming transfer timeouts include paused time. TLS
certificate/hostname verification remains enabled. Raw native allocations and
unread chunks are reclaimed at request shutdown, including tested fatal PHP
memory exhaustion; this does not recover the failed PHP request.

## Executed evidence

`tests/http_streaming.php` verifies 32 MiB binary responses/downloads/uploads and
multipart hashes, slow consumers/server reads, native 64 KiB buffer bounds,
non-spinning pause, timer progress, headers before a gated body, chunked/truncated
responses, redirects, encoded bytes, valid/invalid TLS, empty/fixed-length input,
producer/consumer failures, cancellation before start/while paused/in flight,
early rejection and shared connection admission. Repeated MIME runs have no
unrelated timer. `tests/http_stream_shutdown.php` leaves a paused native transfer
for normal/fatal request cleanup.

PHP 8.3/8.4/8.5 NTS and fully ASan/UBSan-instrumented 8.5 dedicated tests pass.
The FPM audit preserves A/B/C and adds D: 12 incoming requests across four workers,
each with four simultaneous streamed operations. The allocation/callback fuzzer
executed 704,679 inputs/61s, then 602,547/61s against the final callback source; no
finding. This fuzz target does not claim coverage of curl's HTTP parser.
Further instrumentation, benchmark and soak evidence is recorded separately in
WORKLOG and docs/phase3. Long-duration streaming soak, other curl versions,
HTTP/2/3 and broad proxy/remote deployment matrices remain unverified.
The historical runs above predate the HTTP completion work below.


Phase 4 extended mixed smoke adds verified HTTPS buffered and streamed bodies
with slow iteration, alongside existing plaintext streaming. Both epoll and poll
60-second runs passed. These8KiB local transfers add no multiple-file multipart,
proxy, redirect or larger-upload capability claim; see PHASE4_DELIVERY.md.

The expanded ten-minute mixed diagnostic and ten-minute FPM lifecycle diagnostic
passed with streaming enabled. The preceding requested one-hour campaigns failed
(mixed timeout; FPM ticker assertion). These shorter passes do not certify
streaming endurance. Source identities and raw failure evidence remain in
[PHASE4_DELIVERY.md](PHASE4_DELIVERY.md).

Historical hosted FPM at 624cbe9 subsequently completed one hour / 67,520 main
requests with a streamed-HTTP request in each mixed four-request batch, plus the
separate D-streaming concurrency case. This is local small-body streaming within
that FPM workload, not large-transfer/proxy/TLS certification. It predates the UDP
repairs; corrected-source evidence was pending at that checkpoint. The r4 package's
existing streaming tests pass PHP 8.3/8.4/8.5, epoll/poll. Source identities and
full evidence are in PHASE4_DELIVERY.md and phase4/hosted-fpm-1h.json.

Frozen r4/f2abb55 subsequently passed mixed1h/6h/24h with buffered and streamed
8KiB HTTP/HTTPS bodies, and hosted FPM1h with streamed requests. These reuse the
current bounded API under the workload described in SOAK_TESTING.md. Large-body,
multipart, upload, proxy and single-connection24h endurance remain unverified.
No HTTP/2/3 or multi-file capability was added. See phase4/endurance-r4.json and
phase4/hosted-r4.json for exact source, durations and retained local FPM failure.


## HTTP completion checkpoint (2026-11-09)

```php
$client = new HttpClient(concurrency: 32, timeout: 30, connectTimeout: 5);
$reply = $client->multipartFiles($url, ['description' => 'sample'], [
    ['field' => 'attachments[]', 'path' => '/tmp/a.bin'],
    ['field' => 'attachments[]', 'path' => '/tmp/b.txt', 'contentType' => 'text/plain'],
])->await();
$bytes = $client->download($url, '/tmp/result.bin',
    atomic: true, durable: true, failOnHttpError: true)->await();
```

`Runtime::configure(['httpMaxConnections' => 64, 'httpMaxHostConnections' => 32])`
is request-local and must run outside `Async::run()` before any native submission.
Defaults remain 64 total / 8 per host; configurable range is 1..256 with per-host
at most total. Client semaphore admission and connection admission remain separate.
At most 64 detached easy handles are cached per PHP request; all options and
callbacks are reset before reuse. The shared multi owns connection reuse. The
cache and all library handles are freed at request shutdown, never shared across
FPM requests. Diagnostics expose limits, cached handles, creations and reuse.
Library allocations remain outside the native payload ledger.

Request headers are limited to 256 entries and 64 KiB combined including CRLF.
`connectTimeout` includes connection establishment/TLS as implemented by curl;
the existing total transfer timeout includes queueing inside curl and paused time,
but begins after client semaphore admission. Explicit proxies override environment
NO_PROXY exclusions. Local HTTP forward-proxy and HTTPS CONNECT tests verify
success and certificate rejection; SOCKS/proxy-authentication/WAN matrices are not
certified. Buffered POST follows curl's standard 303-to-GET behavior. Streamed
uploads retain their requested method and do not replay on redirects.

`tests/http/run.py` is the focused completion gate, covering multipart, staging,
error injection, limits, proxy/TLS and original streaming tests on epoll and poll.
See [HTTP engineering](HTTP_ENGINEERING.md) for source identities, completed
validation and the benchmark comparison. New results do not certify older archives.


The later buffered-request dispatch optimization and experimental Hyper adapter
are described in [HTTP_PERFORMANCE_WORK.md](HTTP_PERFORMANCE_WORK.md) and
[HYPER_EXPERIMENT.md](HYPER_EXPERIMENT.md). Streaming, uploads and downloads keep
their curl multi and worker-backed disk paths. The prototype explicitly rejects
these operations instead of claiming support or silently mixing pools.


## Buffer-capacity accounting correction

Native b1cfd18 reserves the terminating byte separately from geometric payload
capacity; exact64KiB payloads no longer trigger the next capacity tier. Payload,
header, pause, upload and queue limits are unchanged. Failed growth retains the
original owner and bytes. The ledger is not a complete process memory limit.
See [capacity evidence](HTTP_BUFFER_CAPACITY.md) for before/after real-I/O tests,
scoped instruments and retained unrelated failures.
