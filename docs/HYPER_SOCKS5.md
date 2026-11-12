# HTTP over SOCKS5

Release **EXPERIMENTAL**. Hyper and both HTTP client entities remain
**PARTIALLY_IMPLEMENTED**. This document records one transport capability;
it is not whole-entity or universal proxy certification.

## Public interface and execution

Select the optional built Hyper transport with
`ASYNCARA_HTTP_BACKEND=hyper-prototype`; without that selection the existing
curl multi transport remains the default. There is no silent transport fallback.

The existing `HttpClient(proxy: ..., proxyCredentials: ...)` interface accepts
`socks5h://host:port` and `socks5://host:port`. Omitted proxy ports use 1080.
Scheme names are case-insensitive. URLs cannot contain credentials. Explicit credentials have `username` and
`password` fields, each 1..255 non-NUL bytes. Colons and UTF-8 bytes are legal
here; HTTP Basic retains its separate printable-ASCII policy.

```php
$client = new Asyncara\HTTP\HttpClient(
    proxy: 'socks5h://127.0.0.1:1080',
    proxyCredentials: ['username' => 'application', 'password' => $secret],
    timeout: 10,
    connectTimeout: 3,
);
$response = $client->get('https://example.com/')->await();
```

Hyper resolves the proxy endpoint through existing DNS workers. `socks5h`
delegates an origin hostname to the proxy; `socks5` resolves it with Asyncara's
DNS workers and sends a numeric destination. Numeric origins are not redirected
through a caller-supplied address list. DNS vectors have at most 16 addresses.
`HttpClient::prepareNative()`, C option validation and the additive
`ar_hyper_submit_http_route()` entry point keep endpoint and origin vectors
separate. The previous FFI entry points and `ProxyData` layout remain intact.

`ext/hyper/src/socks.rs` negotiates CONNECT on the existing nonblocking socket
and reactor. Its future owns setup until HTTP admission; no Tokio runtime,
synchronous resolver or network worker is added. A single original connect
deadline covers endpoint candidates, SOCKS setup and origin TLS. The total
operation deadline and owner cancellation remain active. Failed candidates are
retried only before the HTTP request future exists; application requests are
never implicitly replayed.

The tunnel preserves origin Host, TLS hostname, CA and ALPN. HTTPS uses the
existing verified rustls transport; HTTP/2 uses the established owned-stream
integration. HTTP/1 uses origin-form inside SOCKS, not HTTP proxy absolute-form.
Proxy credentials are not inserted into origin HTTP headers. Local-DNS pool
reuse requires the connected target to remain in the operation's current
resolved set, in addition to origin, proxy identity and TLS policy checks.

## Bounds, authentication and ownership

The parser reads exact bounded frames and handles fragmented reads/writes.
Its scratch frame is 262 bytes. RFC1929 credential frames are at most 513 bytes;
space is reserved before copying secrets, and the owned frame is zeroized on
drop. The existing 16-policy cache still rejects new identities at capacity.
This does not guarantee erasure of every PHP or third-party allocation, nor
does the C memory ledger account for all Rust allocations.

Hyper offers exactly the configured method: no authentication, or RFC1929
username/password. It rejects an unoffered method, authentication downgrade,
wrong version, failure reply, nonzero reserved byte, unknown address type,
empty bound-domain and truncated frame. IPv4, IPv6 and bounded domain reply
addresses are consumed completely without consuming tunnel bytes.

RFC1929 credentials travel in cleartext on the SOCKS hop. Origin HTTPS does not
encrypt this earlier exchange. This implementation does not supply SOCKS-hop
TLS, GSSAPI, SOCKS4, BIND or UDP ASSOCIATE. Its scope is HTTP over a SOCKS5 TCP
CONNECT tunnel. See [RFC1928](https://www.rfc-editor.org/rfc/rfc1928) and
[RFC1929](https://www.rfc-editor.org/rfc/rfc1929).

Cancelling a shared waiter does not cancel its request owner. Owner cancellation
drops pending setup or the owned HTTP transfer using existing cleanup. Failed
or timed-out setup releases the native job and socket. No application body is
sent until SOCKS/TLS setup completes.

## Independent validation and retained failures

The Python oracle `tests/http/socks5_peer.py` implements the protocol independently
and restricts destinations to its own loopback ports. `tests/http/socks5.py`
checks wire address types, HTTP/HTTPS, redirects, upload and response streams,
IPv6, TLS rejection, credentials, two ticks while handshakes are held, shared
waiters, cancellation, deadlines, native address validation and final cleanup.
Its peer-side assertions verify that malformed cases actually reached the peer.

`tests/http/http2.py --socks5 local|remote` runs the existing HTTP/2 tests;
`--server` runs the lifecycle test inside a Native Server handler. The HTTP/2
GOAWAY tests distinguish retirement received before response completion from
late retirement excluding a newly admitted request. `http2_goaway_late.php`
requires a surfaced error, no implicit replay, fresh-connection recovery and
cleanup for 16 cycles. No retry guarantee was added.

The first new oracle run exceeded the existing policy-cache limit and therefore
did not exercise every intended malformed reply. Its independent review is a
retained FAIL; the oracle now changes fault behavior on one endpoint. The first
local-DNS pool candidate made 20 authenticated CONNECT attempts for this workload.
A failing regression demonstrated repeated attempts to an unavailable first
address despite a reusable second-address connection. Current resolved-set
membership permits safe reuse; the same workload makes 8 attempts. A separate
failing-before control covers mixed-case SOCKS scheme admission.

An existing GOAWAY fixture sent END_STREAM before GOAWAY. A relayed connection
could deliver the complete response first, invalidating its assumption that
the next request must already know about retirement. Retirement tests now put
GOAWAY before END_STREAM; separate late-GOAWAY coverage retains the race case.
The first relay failure and a later intermittent lifecycle failure remain
preserved. A diagnostic copy exposed a remote GOAWAY NO_ERROR; twelve subsequent
diagnostic passes do not retrospectively prove the precise point of the earlier
generic failure. No speculative runtime retry or timeout change was made.

Installed curl 8.5.0 fails the fixture's local-DNS IPv6-to-IPv4 fallback. The same
failure occurs with standalone `/usr/bin/curl`, without Asyncara; remote DNS and
numeric destinations pass. `--curl-control` is explicitly a positive control
for those paths, not local-fallback or malformed-frame certification. Curl 8.5
also offers no-auth alongside username/password; Hyper's no-downgrade rule is
not a curl guarantee. [libcurl's authentication contract](https://curl.se/libcurl/c/CURLOPT_SOCKS5_AUTH.html)
documents the version-dependent distinction.

Evidence root: `/home/coder/Asyncara-socks5-20261112T043500Z`.
Final matrix, memory and broad-regression results are recorded separately in
the checkpoint attestation; incomplete commands are not passing evidence.

## Checkpoint validation

The final five-build matrix passes 46 commands in 185.934 seconds: PHP 8.3.36,
8.4.27, 8.5.12, DEBUG 8.5.12 and GCC ASan/UBSan, each on epoll and poll.
The matrix runs 16 Rust units; its 3 certificate-dependent ignored cases were
then executed separately and all passed. A fresh isolated FFI fault build also
passed all 16 native failure-point/backend combinations. No fault-enabled
library is linked into the ordinary module. GCC instruments C/PHP, not
all release Rust/crypto code.

Ordinary DEBUG Valgrind passes the full SOCKS/HTTP/1.1 fixture on both backends.
Cold HTTP/2 late-GOAWAY checks FAIL on both, before the first accepted TLS request.
All four reports show zero errors and zero definitely, indirectly or possibly
lost bytes, with 5,354 bytes reachable. A separate peer-debug run confirms the
original 3-second TLS handshake timeout. A separate warm diagnostic completes 16
late-GOAWAY cycles with zero errors/lost bytes; it does not replace either cold
FAIL. No production warm-up, suppression or deadline relaxation was added.

[Machine-readable identities and retained reports](phase7/hyper-socks5.json)
contain the exact commands, binaries and source manifests. The unchanged-source broad regression rerun passes: Gate A has 55 checks
(228.816 seconds), the HTTP gate has 117 commands (298.610 seconds), and the
complete PHP 8.5 suites exit zero on epoll (50.172 seconds) and poll
(53.477 seconds). The first broad failure below remains separate. Independent
packaging is verified separately below.

The first broad HTTP gate stopped at the existing 32 MiB curl multipart
request with `Timeout was reached`. Its log is retained at
`validation-r1/http/epoll-streaming.log`. The unchanged isolated fixture then
passed on candidate, prior checkpoint and candidate again (17.777, 14.082 and
13.130 seconds overall). This does not establish the cause of the initial
timeout. The original 10-second request deadline remains unchanged.

The new SOCKS-specific mTLS combinations are not separately certified. Existing
direct and HTTP/HTTPS-proxy mTLS checks pass, and the tunnel retains the origin
TLS policy, but those results are not a substitute for that missing combination.

## Frozen source and independent distribution

Native/test checkpoint: `2b90593680f0c4be29f7829739154937e0e728e3`.
`build/asyncara-0.1.0-dev-hyper-socks5-r1.tar.gz` contains 756 tracked files,
each checked byte-for-byte against that commit. A second packaging run is
byte-identical. Archive SHA-256:
`5a670d2a51b5c9c53c3fafce774579e0c7effbc0053a02426906c8151aebe589`.

A fresh Cargo/native build from the extraction and 12 SOCKS, HTTP/2,
Native Server handler and server TLS checks pass on epoll/poll, taking
199.960 seconds for the 13 commands. Independent module SHA-256:
`1cde483b1a09eb253f5dbb8722e38da7d61019792e632b639416b1bc7abb6b31`.
[Complete build/test attestation](phase7/hyper-socks5-package.json).
The archive is local; it is not a published GitHub release. Later documentation
commits do not change the packaged source or the tests it executed.
