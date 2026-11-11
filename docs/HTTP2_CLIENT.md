# Buffered HTTP/2 interoperability and curl cache ownership

Native checkpoint `acda044` corrects a reproduced easy-handle reuse failure.
The existing curl multi API negotiates HTTP/2 with the tested TLS peer. No new
HTTP parser or transport replaces libcurl. Functional compatibility and lifecycle gates pass within the scope below.
The full proxy Valgrind gate retains a deadline failure. Release remains EXPERIMENTAL.

## Reproduced defect and correction

The independent TLS peer accepts only ALPN `h2`. A completed warm-up request is
followed by four genuinely pending streams on the same connection. A control
request completes while those streams remain pending. Before the fix, the next
request fails with `CURLOPT_BUFFERSIZE ... bad argument`. The peer independently
records four distinct stream IDs on one connection before failure. Both epoll
and poll failures are preserved, as is a native cache-admission negative control.

`ext/workers.c:ar_job_free()` reset and cached every successful, unpaused handle.
In the tested libcurl8.5.0, `multi_done()` detaches a completed HTTP/2 transfer,
then returns early when the connection still has users. The receive buffer is
freed later in the single-user completion path. `curl_easy_reset()` resets
options without guaranteeing release of that buffer. A later buffersize setter
therefore rejects the apparently reusable handle. This is a faulty Asyncara
cache-eligibility assumption exposed by library behavior, not proof that all
curl versions have the same internal lifetime.

The corrected owner probes the default receive-buffer setting using the public
`curl_easy_setopt(..., CURLOPT_BUFFERSIZE, 0L)` **only after detachment/reset**.
A handle that rejects this reset is cleaned up instead of cached. Other failure,
pause, shutdown and cache-capacity exclusions remain intact. Successful ordinary
handles still reuse the cache. The multi handle retains connection ownership;
independent streams and the shared connection survive. No private libcurl memory
access, active-transfer buffer mutation, error suppression, new timeout or busy
polling is introduced.

The native regression `tests/http/curl_cache.c` checks 200 explicit reset-failure
admissions, exact cleanup, ordinary reuse and final release. It uses the actual
job owner with a controlled public-API rejection. `tests/http/http2.php` supplies
the separate real-kernel, real-library failing-before/passing-after control.

Upstream references: [8.5.0 multi_done source](https://github.com/curl/curl/blob/curl-8_5_0/lib/multi.c),
[easy reset contract](https://curl.se/libcurl/c/curl_easy_reset.html),
[buffer option constraints and later library changes](https://curl.se/libcurl/c/CURLOPT_BUFFERSIZE.html).

## Exact protocol scope

The test uses the existing `HttpClient::get/request/post` buffered interface,
verified private-CA TLS and a pinned independent Python hyper-h2 peer. It proves
actual h2 negotiation and distinct concurrent streams on one connection, not
merely the presence of a compile-time curl feature.

- Two timer ticks occur while all four peer-held streams remain incomplete.
- Cancelling one waiter leaves its operation active; cancelling an operation
  does not discard its siblings.
- Buffered binary PUT, HEAD, gzip and redirects preserve their expected data.
- Stream reset, premature content-length termination, 64KiB header overflow and
  16MiB body overflow reject while another held stream remains usable.
- A genuine pending stream reaches its configured operation timeout.
- Native operations, payload accounting, Tasks, Futures, timers and watchers
  return to zero after collection.
- The same buffered path is exercised through the independently verified HTTPS
  proxy/CONNECT layers in [HTTPS_PROXY.md](HTTPS_PROXY.md).

The public API does not expose strict HTTP/2-only selection. Version negotiation
and fallback remain libcurl policy; defaults vary by library version. See
[CURLOPT_HTTP_VERSION](https://curl.se/libcurl/c/CURLOPT_HTTP_VERSION.html).
These results do not certify every curl build or remote peer.

Response streams and streamed uploads deliberately select HTTP/1.1 to retain
their tested pause/buffer limits. The h2-only fixture verifies streaming rejection;
it does not relabel streaming as HTTP/2. Hyper client and Native Server remain
HTTP/1 in their current implementation. HTTP/3, h2c, server push, proxy auth,
HTTP/2-to-proxy negotiation and broad internet/WAN certification remain outside
this scope. The TLS proxy carries HTTP/1.1 CONNECT to an HTTP/2 origin.

## Reproduction and lifecycle coverage

```
python3 -m venv /new/peer-venv
/new/peer-venv/bin/python -m pip install --require-hashes --only-binary=:all: \
  -r tests/http/http2-requirements.txt
/new/peer-venv/bin/python tests/http/http2.py --php /path/to/php \
  --module /path/to/asyncara.so --backend epoll --out /new/h2-direct
/new/peer-venv/bin/python tests/http/http2.py --php /path/to/php \
  --module /path/to/asyncara.so --backend poll --proxy --out /new/h2-proxy
/new/peer-venv/bin/python tests/http/http2_fpm.py --module /path/to/asyncara.so \
  --fpm /path/to/php-fpm --backend epoll --out /new/h2-fpm
```

The dependency is test-only: h2 4.4.1, hpack4.2.0, hyperframe6.1.0, pinned with
wheel SHA256 values. No packages are installed into the system Python or the
production runtime. The peer caps active streams, upload bytes and recorded
metadata; logs exclude body/credential values. Each output path is new.

The FPM variation runs20 sequential and40 requests across four workers, with
four distinct delayed HTTP/2 streams per main request. It keeps the original
minimum two ticks and overlap requirements, idle descriptor/thread checks,
pending fatal-request cleanup and same-PID recovery. Its private peer retains
the0.2s delayed responses; a timer-only/immediately completed Future is not a
substitute. This is short integration evidence, not endurance certification.

The initial `http2-after-r1` FAIL is retained: the new fixture explicitly called
close outside its expected-error catcher after consume had already closed and
propagated the producer error. The fixture now relies on consume's existing
ownership contract. No production stream error was suppressed or assertion
threshold weakened. Historical Phase4 zero-tick failures remain unresolved.

## Instrumented deadline limitation

`http2-memory-r1`, `r2` and `r3` remain FAIL: the full nested-TLS proxy workload reaches
its unchanged5s operation deadline during the17MiB oversize-response test, before
the required16MiB body-limit rejection. Completed raw DEBUG memchecks show zero
errors/lost bytes, but that is not a full functional-memory gate pass.

`tests/http/curl_h2_control.c` independently uses libcurl multi, no PHP/Asyncara,
the same peer, verified TLS layers,128KiB receive preference,16MiB callback bound
and5000ms deadline. `http2-curl-control-r2` records normal proxy limit rejection
in0.370667s/0.118402CPU; under Valgrind it times out after10,485,760bytes in
6.256150s/6.169823CPU. Normal direct0.160274s and instrumented direct5.248044s
both reach the callback limit. The instrumented deadline can be checked after
CPU-intensive work, hence wall time may exceed5000ms. This is an instrumentation-
sensitive transfer/crypto cost demonstrated without Asyncara, not proof of a
runtime deadlock or a universal leak-free claim. No time limit was increased.

A separate fixture defect was demonstrated: its TLS tunnel could call select
with100ms timeout while OpenSSL already held decrypted input. The fixture now
checks pending input first; three isolated readiness tests verify immediate
processing, ordinary wait and no duplicate readiness. That correction did not
remove the Valgrind oversize timeout. Historical and new original logs are kept.
The first independent control runner failed at Valgrind loader initialization
because it omitted the existing private debuginfo path; its terminal record is
preserved. The corrected runner uses that path without changing the system.

## Final verification evidence

Raw root: `/home/coder/Asyncara-priority-20261110T205459Z`.
[Durable attestation](phase7/http2-client.json) includes original failures,
commands, source manifest, binary fingerprints and report hashes.

| Check | Actual result |
|---|---|
| Five builds: PHP83.36/84.27/85.12,85.12 DEBUG/GCC; epoll/poll | PASS35 build/test commands151.615s; final corrected peer30 tests109.565s |
| Gate A | PASS49 commands212.054s (outer runner) |
| Full PHP85 suites | PASS epoll47.255s, poll40.692s |
| HTTP gate, curl and Hyper | PASS93 commands183.459s (outer runner) |
| HTTPS proxy and direct h2 FPM, both backends | PASS240 main requests/960 HTTP operations |
| C-only independent module | PASS build plus4 direct/proxy/backend cases |
| Native cache, GCC ASan/UBSan, native Valgrind | PASS;200 controlled rejection/cleanup paths, valid cache reuse;56bytes reachable under native Valgrind |
| Direct h2 DEBUG Valgrind, epoll/poll | PASS;0errors and0definite/indirect/possible loss;3888bytes reachable |
| Full proxy h2 DEBUG Valgrind | FAIL at original5s deadline in17MiB limit test; raw memcheck0errors/loss,3888reachable; not a functional pass |

The final FPM raw recheck verifies source/module hashes, every response ID,
original two-tick and overlap conditions, per-request native cleanup, distinct
HTTP/2 stream IDs on one connection and idle6FD/1thread. HTTP/2 sequential RSS
ranges are21656..21972KiB(epoll) and21780..22260(poll); four-worker ranges
21412..21856 and21700..21736. Minimum h2 ticks28/13(epoll sequential/four) and
23/16(poll). The original conditional RSS threshold is retained; these short
cases have too few samples for a long-run trend claim. No request-order
chronology is inferred from batch emission order. Fatal-pending cleanup and
same-worker recovery use the existing audit runner. This is not an endurance pass.

The final matrix uses ordinary unloading and leak detection, without memory
suppressions. Rust release dependencies are not instrumented by the GCC C/PHP
build. No universal native-memory safety claim follows from these scoped checks.
Independent source packaging is recorded separately after this checkpoint.
