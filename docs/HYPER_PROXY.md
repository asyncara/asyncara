# Hyper explicit proxy transport

Status: explicit proxy capability **IMPLEMENTED_AND_TESTED** in the executed
scope below; entity-level status **PARTIALLY_IMPLEMENTED**.
Release: **EXPERIMENTAL**. This record concerns the optional Hyper transport,
not a claim that all proxy products, authentication protocols or HTTP versions
are certified. The broad regression gate and independent packaging have separate results.

## Execution and ownership

`HttpClient` retains its existing `proxy`, `proxyCaFile` and
`proxyCredentials` constructor options. `prepareNative()` resolves the proxy
endpoint through Asyncara DNS workers and reads trust files through filesystem
workers. HTTP(S) proxies and SOCKS5h resolve their delegated origins. SOCKS5 local-DNS mode is documented separately in [the SOCKS5 transport record](HYPER_SOCKS5.md). Direct origins
still use the existing resolver. The native connection budget includes this
preparation, TCP, proxy TLS, CONNECT and origin TLS; total request deadlines and
cancellation remain active throughout.

`ext/hyper/src/proxy.rs` prepares an HTTP/1.1 proxy hop on the existing readiness
reactor. Plain HTTP uses absolute-form requests. HTTPS uses CONNECT followed by
origin TLS. An HTTPS proxy adds a separate outer TLS layer; origin HTTP/2 is
selected through the existing inner ALPN path. No Tokio executor, synchronous
resolver, file read, or generic blocking network worker is introduced.

`ClientIo` retains the nested transport and any bytes received after a complete
CONNECT response. Each connection retains the canonical driver waker. Request
operations own their transfer future; cancellation collects that owner using
the established bridge. Shared waiter cancellation and owner cancellation are
separate. Pool reuse checks origin, endpoint address, proxy policy and origin
TLS/client-certificate identity.

## Trust, credentials and limits

Proxy trust and origin trust are independent and verified. A client certificate
is presented only to its origin, never to the proxy. Credentials are supplied
explicitly, not embedded in the proxy URL or an application-supplied
Proxy-Authorization header. Basic authentication is sent only to its configured
proxy; CONNECT carries no application Authorization header. Basic credentials
on a plaintext proxy are not encrypted by this feature; select an HTTPS proxy
when the proxy hop must be protected.

There are at most16 exact proxy policies per native engine, matching endpoint,
CA bytes and credentials. Existing policies remain reusable when the cache is
full; a new policy is rejected. Origin TLS policies have their separate existing
16-entry bound. Proxy URL8192bytes, CA1MiB, DNS candidates16 and each explicit
credential4096bytes are bounded. Basic currently accepts printable ASCII,
nonempty username without colon, and an optionally empty password. Rust-owned
credential staging/cache strings are zeroized on drop; this is not a guarantee
that every PHP/header/crypto allocation is erased.

CONNECT parsing accepts partial reads and at most8 informational responses,
64KiB headers and256 header fields. It rejects101, non-2xx, malformed, oversized
and truncated heads. Successful CONNECT switches protocols: its Content-Length
or Transfer-Encoding cannot create a response body. Extra received bytes belong
to the tunnel. See [RFC9110 CONNECT](https://www.rfc-editor.org/rfc/rfc9110.html#name-connect)
and [Basic authentication](https://www.rfc-editor.org/rfc/rfc7617.html).

Environment proxy auto-selection, SOCKS4/BIND/UDP, Digest/NTLM/Negotiate and HTTP/3 remain
unsupported by Hyper. There is no silent fallback to curl. Linux is the tested
platform. Third-party Rust/TLS/h2 allocations are bounded separately and are
not all represented by the C allocation ledger.

## Defects found while adding the transport

* `Semaphore::withPermit()` did not redact its callback argument in PHP
  exception traces. A captured synthetic proxy password was visible when
  admission rejected a caller header. The new SensitiveParameter annotation
  preserves permit semantics. `tests/server/callback_traces.php` fails before
  the change and passes afterward, including the new semaphore case.
* `TlsIo::poll_read()` could fill plaintext into the caller buffer, then return
  a later UnexpectedEof and discard the delivered bytes. It now returns those
  bytes successfully and exposes the deferred error on the next read. A real
  TCP/rustls unit test fails against the frozen b1229f1 implementation and
  passes with the correction. The error is retained; incomplete HTTP bodies
  remain failures. Native Server shares this TLS transport.

The first proxy-focused run also retained a new-test Rust compilation error
(two implicit raw-pointer borrows), corrected by explicit engine references.
No compiler warning was suppressed.

## Independent protocol tests

`tests/http/proxy_auth.py --transport hyper` exercises authenticated plaintext
and TLS proxies, forward/CONNECT, stream/upload, redirect, identity isolation,
shared waiters, owner cancellation and cleanup. `--server` repeats client
assertions inside a Native Server handler and sends16 concurrent handler
requests. The unchanged default transport remains curl-multi for control runs.

`tests/http/https_proxy.py --transport hyper` exercises separate proxy/origin
roots, wrong roots and hostname rejection. `hyper_proxy_failures.py` uses a
separate asyncio peer for held/partial/informational CONNECT, malformed/oversized
heads, prefetched invalid TLS, bounded informational storms, cancellation and
connect timeout. The independent control channel requires two timer ticks while
CONNECT is still pending. Peer counters prove every failure case reached its
intended endpoint; pending operations, Futures and native ledger return to zero.

`http2.py --transport hyper --proxy-auth` exercises owned H2 streams through
nested TLS; `--case early-upload` and `--case goaway` retain their separate
zero-window upload and draining-connection invariants. Existing progress,
resource limits and request deadlines remain unchanged.

The first nested-TLS lifecycle run FAILED its5s request budget while transferring
the16MiB+1 oversize response; its held sibling independently timed out. A separate
timing copy records that error. With identical module/data/deadlines, changing
only both fixture tunnel sockets to TCP_NODELAY reduced oversize rejection to
1.088s and completed the fixture in4.963s. The fixture now explicitly matches the
asyncio origin's TCP_NODELAY policy. This is a test transport configuration
change, not a runtime fix or a claim about arbitrary proxy latency. The original
FAIL and diagnostic run are preserved separately.

## Evidence

External evidence root:
`/home/coder/Asyncara-hyper-completion-20261111T235911Z`.
`proxy-focused-r3/report.json`:18-command PASS, epoll and poll, Rust15 ordinary
unit tests plus3 explicitly executed TLS controls. `proxy-focused-r1` and
`proxy-focused-r2` retain their failures. `tls-eof-before-src` contains the frozen
TLS source plus the new regression only; `proxy-focused-r1/tls-negative.log`
records its expected failing plaintext assertion. `proxy-wire-r1` and
`proxy-permit-trace-before.log` retain the credential trace finding.

`proxy-matrix-r1/report.json`: **PASS**,86 commands,408.160229s. PHP8.3.36,
8.4.27,8.5.12 NTS,8.5.12 DEBUG and GCC ASan/UBSan builds, each epoll/poll.
This includes proxy trust/authentication/CONNECT failures, three nested-TLS H2
cases, Native Server handlers and the original Native Server TLS/mTLS suite.
GCC instruments PHP/C, not the release Rust/AWS-LC dependency code.

`proxy-memory-r1/report.json`: **PASS**,six ordinary DEBUG Valgrind3.22 cases,
proxy auth/trust/malformed CONNECT on both backends. No suppressions or widened
request deadlines. Every run reports0 errors,0 definitely/indirectly/possibly
lost bytes and5,354 bytes still reachable. This is scoped memory evidence, not
complete-process or universal allocation certification.

`proxy-validation-r1/report.json`: **PASS**. Gate A55 checks206.859534s,
HTTP111 checks221.929785s; complete PHP8.5 epoll40.095470s/poll38.816220s.
The first two durations are gate-internal; the full-suite durations are
supervisor wall times. All refer to the same runtime manifest and the respective
isolated native modules. [Source, binary and raw report attestation](phase7/hyper-proxy.json).
Independent source packaging is recorded separately. No whole entity completion
or release has been claimed or pushed.


## Independent source distribution

Packaged source `b777972e5b50f5f591cc42f85125e43963f0ddc6` contains743 files,
each compared byte-for-byte with its Git tree before extraction:
`build/asyncara-0.1.0-dev-hyper-proxy-r1.tar.gz`.
SHA-256 `d30d7767160478313078f0be66401e78a6bf39c82d87de198f7e2488af9dd6a9`.
A fresh isolated Cargo target and C build, followed by20 protocol/TLS checks,
pass in234.740928s total. Independent module SHA-256:
`3c2253e7b92692f70baae95dedf7ea6ee47c334b157686e83f0e202414465118`.
[Build/test attestation](phase7/hyper-proxy-package.json). The archive is local,
not a GitHub release. Its source is distinct from later documentation commits.

SOCKS5 TCP CONNECT is a later continuation; see [its own evidence and curl limitations](HYPER_SOCKS5.md). The historical HTTP proxy results above keep their original identities.
