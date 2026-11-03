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

Multipart uses curl MIME callbacks and supports one streamed file plus up to64
scalar string fields. Each field is at most 64 KiB, all fields together at most 1 MiB;
field/file metadata rejects NUL/CR/LF and is bounded. Multiple files, nested MIME,
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
leave partial data after failure. It does not provide atomic publication or crash
durability. HTTP error-status bodies are not automatically rejected; use
`getStream()->headers()` when status-based acceptance is required.

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
HTTP/2/3, multi-file MIME and proxy/remote deployment matrices remain unverified.


Phase 4 extended mixed smoke adds verified HTTPS buffered and streamed bodies
with slow iteration, alongside existing plaintext streaming. Both epoll and poll
60-second runs passed. These8KiB local transfers add no multiple-file multipart,
proxy, redirect or larger-upload capability claim; see PHASE4_DELIVERY.md.

The expanded ten-minute mixed diagnostic and ten-minute FPM lifecycle diagnostic
passed with streaming enabled. The preceding requested one-hour campaigns failed
(mixed timeout; FPM ticker assertion). These shorter passes do not certify
streaming endurance. Source identities and raw failure evidence remain in
[PHASE4_DELIVERY.md](PHASE4_DELIVERY.md).
