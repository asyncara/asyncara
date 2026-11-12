# Hyper entity completion work

Started 2026-11-12 at `969f294` on `phase7-native-server-20261109T231953Z`.
The entity remains **PARTIALLY_IMPLEMENTED**. This record tracks actual work
against the outstanding capability gaps; it does not replace the canonical
[overall roadmap](ROADMAP.md#current-priority-status). No entity-level completion
or performance claim follows from the individual results below.

## Client certificate identity

`HttpClient` now accepts paired `clientCertFile` and `clientKeyFile` named
arguments. Both transports use bounded filesystem workers to read the files;
network waits retain rustls/readiness or curl multi. Filenames are captured at
construction, and the first complete pair is retained by that client. A new
client reads a changed identity. This is a content snapshot after the reads,
not an atomic snapshot of two independently replaceable filesystem objects.

A certificate PEM is limited to 1 MiB and the private-key PEM to 64 KiB. Hyper
accepts up to 32 chain certificates and a single unencrypted key; rustls checks
the signing key against the leaf certificate. Its request-local cache holds at
most 16 exact `(roots, certificate, key)` policies. Connections cannot cross
those policy identities. Cached Rust key bytes are zeroized on release; that
is not a promise to wipe every PHP, C or dependency copy. Existing CA bounds
remain. Policy bytes can now total up to 33 MiB outside the native ledger, plus
parsed TLS structures; this is bounded, not complete process memory accounting.

C uses `CURLOPT_SSLCERT_BLOB` / `CURLOPT_SSLKEY_BLOB` with copy ownership. It does
not introduce a key-file read into a reactor callback. Rust uses a new identity
submission entry point, preserving the previous C ABI entry points. Server
certificate verification, hostname checks and TLS 1.2/1.3 remain enabled.
Encrypted keys/password callbacks and PKCS#11 are not added.

## Hyper redirect chains

The public opt-in `followRedirects` path owns at most five HTTP(S) hops under
one total deadline. DNS and transport preparation still use the existing APIs.
Buffered request bodies can be replayed for 307/308; 301/302 POST and applicable
303 requests change to GET with content headers removed. Iterable uploads
continue to have no implicit replay API.

Relative references are resolved without decoding percent-encoded path segments.
Ambiguous Location fields, URL credentials, control bytes, unsupported schemes,
HTTPS downgrades and excessive chains reject. Standard Authorization/Cookie,
Origin and Referer fields are removed across origins. Applications remain
responsible for custom sensitive headers. mTLS chains reject a change of origin
instead of presenting the configured certificate to another origin.

Response-stream redirects close and collect intermediate transfers without
buffering their bodies. Only the final head/body is exposed. The outer bounded
producer adds a two-chunk queue; it does not collect the final response. Closing
it cancels and joins its owned inner stream. Cancelling a separate waiter leaves
the operation owner intact. A timeout or cancellation cannot undo sent bytes.

Policy references: [HTTP redirection](https://www.rfc-editor.org/rfc/rfc9110.html#name-redirection-3xx)
and [relative references](https://www.rfc-editor.org/rfc/rfc3986.html#section-5.2).

## Executed evidence so far

Raw root: `/home/coder/Asyncara-hyper-completion-20261111T235911Z`.

- `build-r1`: fresh isolated Cargo/native build; 11 ordinary Rust tests passed,
  two fixture-dependent tests were ignored and are not counted as passes.
- `mtls-matrix-r1`: 35 commands passed in 140.258514 seconds. PHP 8.3/8.4/8.5,
  DEBUG and GCC ASan/UBSan each ran new identity checks on both transports and
  epoll/poll, plus the existing Hyper TLS suite. Rust/dependencies are not all
  instrumented by GCC sanitizing the C extension/PHP.
- `validation-r1`: **FAIL** at the first cold Valgrind TLS request deadline.
  Earlier steps passed: 35 focused checks, Gate A 234.949s, HTTP gate 207.076s,
  full PHP85 epoll 57.739s / poll 47.156s. The failing process reported zero
  memory errors/lost bytes, but did not complete its workload.
- `mtls-matrix-r2`: 35 commands PASS in 126.804194s, including native rejection
  of client identity with raw redirect following. The original report's binary
  inventory accidentally lists older `hyper-completion` modules; the actual
  commands and nested reports identify `hyper-identity-final` modules. Separate
  `mtls-matrix-r2-independent-identities.json` records the correction without
  overwriting the original report.
- `memory-controls-r1`: independent rustls 0.23.46 / aws-lc-rs 1.19.0 TLS1.2
  client-authentication control (no PHP/Asyncara/Hyper) completed in 0.114729s
  normally and 3.575825s under Valgrind. The latter exceeds the unchanged 3s
  request deadline. Both controls reported zero errors/lost bytes. The warmed
  mTLS workload passed epoll/poll with cold timeout retained and all original
  assertions thereafter; this is a memory diagnostic, not normal acceptance.
  Cold redirect memory runs still failed their chain deadline; no suppressions
  or deadline increases were used.
- `validation-r2`: **FAIL** after PHP multipart assertions passed, because the
  independent TLS peer failed to terminate after early rejection of an unread
  upload. A no-PHP control reproduced this and passed after fixture transport
  cleanup (`multipart-peer-shutdown-r1`). Runtime assertions were unchanged.
- `validation-r3`: **FAIL** at the multipart pending-Future cleanup assertion.
  Native operations/handles/bytes were zero. A diagnostic Future constructor
  identified the pending object as a file-stream Queue join Event. A minimal
  production control then reproduced retained buffered entries after close.
  `AsyncStream::close` now joins the producer and acknowledges/discards only
  its unconsumed private buffer. A currently yielded item retains its iterator
  acknowledgement. No forced garbage collection was added. Historical FAIL
  remains; `validation-r4` and later runs test the correction.

Tests: `tests/http/client_mtls.{py,php}`, `client_mtls_peer.py`,
`hyper_redirects.{py,php}`, `hyper_redirect_peer.py`, and `redirect_uri.php`.
The URI control checks 42 valid relative references and 10 rejected inputs.
Independent Python/OpenSSL peers observe certificate fingerprints and connection
identities; no private key is logged. The fixtures retain the two-tick condition
while network I/O is genuinely held, verify owner/waiter cancellation and check
zero pending operations, managed buffers, file handles, Tasks, Futures, timers
and watchers at cleanup. Idle connection pools intentionally survive until
request teardown.

## Multipart work in progress

The Hyper path now emits MIME through the existing bounded upload producer;
file reads retain native filesystem workers. Multiple files, repeated file-field
names, binary scalar fields and quoted metadata are covered by an independent
Python email/MIME decoder. The implementation retains at most two MIME chunks
plus existing file/upload queues. Standalone PHP85 HTTP/HTTPS checks transferred
32 MiB with about 200 KiB peak tracked native memory. PHP 8.3/8.4/8.5 and DEBUG each passed epoll/poll after the stream-close
correction. GCC epoll passed in `validation-r5`; GCC poll failed its original
10s upload deadline. **The complete sanitizer matrix does not pass yet.**
`validation-r4` is also a retained deadline FAIL. The independent MIME fixture
now parses only part headers as text, and hashes binary bodies directly; seven
valid vectors agree with Python's original full MIME parser, with fourteen
invalid cases rejected. This does not explain every sanitizer deadline failure.

`multipart-timing-r1` is a separate **FAIL diagnostic**: the peer received only
16 MiB before the HTTP deadline and never entered MIME decoding. The PHP process
used 8.58s user CPU and 7.86s system CPU over 27.11s, including fixture creation.
The 4,096-event trace overwrote earlier events, so it cannot establish complete
submission-to-failure timing. At observation time host CPU pressure was 43.22%;
that is context, not proof that all failures originate outside Asyncara.
Remaining independent checks continue in `validation-r6-remaining`; it must not
relabel either failed broad run as PASS.

## Still open

Hyper proxy support, HTTP/3, remaining protocol/proxy parity and broad
interoperability remain unfinished. Native Server's retained failures and wider
acceptance gates remain separate. No push marking the Hyper entity complete
has been performed. Historical Phase 4 artifacts and the main checkout are
unchanged. Release classification remains **EXPERIMENTAL**.


`validation-r6-remaining` is **FAIL**, with distinct passing scopes: Gate A
244.557s; HTTP111 checks216.895s; fullPHP85 epoll38.568s/poll39.634s; warmed
DEBUG Valgrind mTLS epoll/poll and redirect epoll. Those three memory workloads
report0 errors/definite/indirect/possible losses,5306bytes19blocks reachable.
Warmed redirect poll failed a deadline with0 errors/lost bytes; its incomplete
workload remains FAIL. Exact manifests, commands, module/report hashes and
previous failures are in [the attestation](phase7/hyper-identity-mime.json).

## Multipart producer comparison

Local source checkpoint703bfeb preserves the initial MIME producer. The paired
`multipart-comparison-r1` uses the same PHP85 binary/module, poll backend,
32MiB HTTP and HTTPS bodies, assertions and fixture. Three samples per variant
pass. Median task steps:queued7791,direct6531 (-16.17%); median process duration
3.99085s versus3.97513s, insufficient evidence of a general throughput gain.
The direct MIME producer uses Files::open/File::read with shielded Async::using
cleanup, eliminating one nested file producer/queue. Both keep bounded queues
and the same native disk workers. The generic stream-close regression remains.


Brotli/zstd and direct MIME producer: targeted45-command matrix PASS on PHP83/84/85,
DEBUG/GCC, both backends. Native codec ASan/UBSan and Valgrind PASS, including
allocation failures and window/input/output limits. [Codec contract/evidence](HYPER_COMPRESSION.md)
records exact scope. This is still not whole Hyper completion; proxy/HTTP3 and
remaining acceptance work are open.


## ALPN HTTP/2 continuation

[HTTP/2 implementation and ownership evidence](HYPER_HTTP2.md) records buffered
and streamed requests, one-connection multiplexing and early-upload cleanup.
The initial opaque-executor implementation failed its zero-window early-response
regression; that failure is preserved. The owned h2 stream implementation passes
the five-build/backend targeted matrix. This does not close proxies, HTTP/3 or
whole-entity completion.
