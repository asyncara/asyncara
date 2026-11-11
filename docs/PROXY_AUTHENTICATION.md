# Explicit HTTP proxy authentication

EXPERIMENTAL. The default curl multi client now accepts an optional final
`proxyCredentials` constructor argument. The implementation is in
`ext/backend.c:ar_http_start`, `ext/php_asyncara.c:valid_http_options` and
`src/Asyncara/HTTP/HttpClient.php`. It preserves the existing connection pool,
readiness integration, timeouts and streaming bounds.

```php
$client = new Asyncara\HTTP\HttpClient(
    proxy: 'https://proxy.example:8443',
    caFile: '/application/origin-ca.pem',
    proxyCaFile: '/application/proxy-ca.pem',
    proxyCredentials: ['username' => $username, 'password' => $password],
);
$response = $client->get('https://service.example/data')->await();
```

This is **Basic** proxy authentication. Username/password are each bounded to
4096 printable ASCII bytes. Username is nonempty and cannot contain a colon;
password can be empty or contain colons. The client detaches reference-valued
array elements. Native submission copies both strings into the existing owned
option representation; libcurl copies them through its public setters.
The native ledger does not account for libcurl's copies or connection buffers.

Credentials require an explicit `http://` or `https://` proxy without URL
userinfo. An incomplete pair, unsupported scheme, extra credential keys,
control characters, oversized strings or a conflicting `Proxy-Authorization`
request header is rejected. Existing URL/environment proxy behavior without
the new option is unchanged. Hyper still rejects proxies explicitly.

`CURLOPT_PROXYUSERNAME`, `CURLOPT_PROXYPASSWORD` and `CURLAUTH_BASIC` configure
the proxy hop. `CURLHEADER_SEPARATE` keeps origin headers out of CONNECT.
Both proxy and origin TLS verification remain enabled and have independent
trust configuration. Basic credentials travel in plaintext over an HTTP proxy
connection, including CONNECT setup; HTTPS proxy transport encrypts that hop.
This is not origin authentication, Digest, NTLM, Negotiate or SOCKS certification.

An HTTP forwarding authentication refusal is returned as status407; a rejected
CONNECT fails the transfer with `OperationException`. The client does not turn
all non-2xx statuses into exceptions. Buffered HTTP/2 can run inside an
authenticated HTTPS CONNECT tunnel. Streams remain HTTP/1.1 to preserve the
existing pause-buffer policy; authentication does not remove that safeguard.

## Exception and configuration ownership

The constructor's credential argument, internal option arguments, Scheduler
native argument arrays and native `asyncara_submit` arguments are sensitive.
An independent old-module control exposes a synthetic password before the native
annotation; the corrected control reports no exposure.

The Native Server integration exposed a separate root-callback trace problem:
`Async::run` and `Scheduler::run` retained callbacks whose bound/captured
application state can include secrets. Both now use the same SensitiveParameter
policy already present on `spawn`. `tests/server/callback_traces.php` fails on
both entry points before repair and passes after. It first checks an unprotected
control to establish that argument capture is enabled. This does not redact
arbitrary application messages, user callback arguments, explicit object dumps
or externally supplied URLs containing credentials.

## Reproduce

```
python3 tests/http/proxy_auth.py --php PHP --module MODULE --backend epoll --out NEW
python3 tests/http/proxy_auth.py --php PHP --module MODULE --backend poll --server --out NEW
python3 tests/http/http2.py --php PHP --module MODULE --backend epoll --proxy-auth --out NEW
```

The HTTP/2 fixture requires the existing isolated Python hyper-h2 dependency.
No public proxy or external internet origin is involved. The independent peer
records authorization/rejection counts and hop leakage, never credential values.
Tests cover HTTP and HTTPS proxies, forwarding and CONNECT, redirects, buffered
and streamed transfers, shared waiter/owner cancellation, two timer ticks before
peer release, credential changes, cached-handle reuse, invalid inputs, native
option copying and final native/Future/timer/watcher cleanup. Server mode also
executes16 concurrent inbound requests and the same checks inside a handler.

Raw evidence is under
`/home/coder/Asyncara-priority-20261110T205459Z/proxy-auth-work`.
The completed `matrix-r2` contains40 passing commands across PHP8.3.36,8.4.27,
8.5.12, DEBUG8.5.12 and GCC ASan/UBSan8.5.12, epoll/poll. Its source manifest and
five module checksums distinguish it from subsequent test-only additions and the
independent ConnectionPool constructor correction. Final regression, memory and
package attestations are recorded separately when completed.

Preserved failures include the missing-API before control, a test helper whose
own closure arguments exposed synthetic data, an incorrect Server Response
namespace in the first fixture, the actual root-callback leak, and an initial
matrix that could not import hyper-h2 because its isolated dependency path was
omitted. No original runtime assertion or deadline was weakened.

Public library contracts:
[proxy username](https://curl.se/libcurl/c/CURLOPT_PROXYUSERNAME.html),
[proxy password](https://curl.se/libcurl/c/CURLOPT_PROXYPASSWORD.html),
[authentication](https://curl.se/libcurl/c/CURLOPT_PROXYAUTH.html),
[header separation](https://curl.se/libcurl/c/CURLOPT_HEADEROPT.html).

## Memory evidence scope

`memory-r1` preserved a whole-handler server failure followed by forced SIGTERM;
its exit-time allocation report cannot certify ordinary cleanup. There were no
invalid read/write diagnostics before that termination. `memory-r2` runs the same
proxy groups in two separate diagnostic requests with the original deadlines and
all assertions intact (`--server-split`). Four CLI/server epoll/poll DEBUG
Valgrind processes complete with zero errors, definite, indirect or possible
losses; reachable library allocations are3888bytes CLI and4066bytes server.
This diagnostic scope is not substituted for the normal whole-handler fixture.
The observer now retains external request timings and attempts server metadata
and ordinary stop after failure. All failed reports remain preserved.

Whole-handler follow-ups `memory-original-diagnostic-r2` and `r3` also pass at
unchanged deadlines, including both readiness backends, with zero Valgrind
errors/loss and4066reachable bytes. Their9.492/9.633/9.851-second request times
show little margin under the10-second limit; they do not retrospectively prove
why the first request disconnected. The first report lacks a server timeout
counter. The intermediate diagnostic-r1 returned500 from a test-helper argument
mismatch; that failed report is retained and only the adapter was corrected.
Final normal HTTP gate101 and full PHP8.5/Native Server gates pass at the
`1e8be3c` test checkpoint, with native source still `2a96f61`.
