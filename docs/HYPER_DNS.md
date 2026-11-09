# Hyper DNS continuation and isolated curl trailer repair

Release: **EXPERIMENTAL**. Starting checkout6818dd6; dependency tooling34a50f6;
runtime922a02459774df98191937cbc1f3ffacc80c975d; fixture correctionb1b14e9.
Branch: `phase6-http-transfer-20261109T211228Z`.
Evidence root: `/home/coder/Asyncara-http-continuation-20261109T224615Z`.
No previous archive, Phase4 fixture, installed campaign module or global library
was replaced. The previous [safety review](HYPER_SAFETY_REVIEW.md) applies to its
own dd63396 checkpoint. This continuation changes DNS capability only; its earlier
panic/encoding/wakeup findings remain valid.

## Implemented path

`HttpClient::request` detects the selected native backend once per client.
Numeric Hyper URLs keep immediate native submission and inline endpoint/origin
storage. Domain URLs use an owned Task and the existing client admission permit.
`resolvedRequest` validates an ASCII DNS/A-label origin, rejects unsupported
proxy/scheme/options before lookup, then uses `IO\Dns::resolve` through the
existing bounded four-pthread resolver infrastructure. The worker executes
getaddrinfo; the calling Fiber suspends. No Tokio executor, new resolver pool,
blocking Event Loop lookup, global hosts-file change or implicit curl fallback.

After resolution,1..16 distinct addresses cross the bounded C option validation
in `valid_http_options`/`hyper_start`. Rust `ar_hyper_submit` validates and copies
them synchronously. Neither a PHP option nor a resolver result pointer survives
submission. Host uses the original URI authority. Pool origin identity is distinct
from the dial endpoint; a name and numeric IP cannot borrow each other's socket.
Per-host limits group a domain's addresses together. Different DNS names mapping
to one IP remain separate origins. Numeric endpoint/origin storage avoids adding
heap allocations to the existing IP path.

`Engine::next_address` retries another resolved address only before constructing
a request future. The same connect deadline covers every attempt. No sent request
is replayed. This is sequential connection-failure fallback, **not Happy Eyeballs**;
a stalled first address can consume the connect budget. Connection reuse requires
both origin identity and an address still present in the current result.

DNS results are not cached by Asyncara: each domain request resolves again; the
system resolver's own behavior applies. No TTL/rotation/IDNA/DNSSEC guarantees
were added. Unicode names require caller-supplied ASCII A-labels. Names are capped
at253 bytes. More than16 addresses rejects; no silent truncation.

## Lifetime, cancellation and timeouts

The owned domain Task holds its permit through DNS and HTTP collection, including
failure/cancellation. Cancelling one waiter detaches its wait and retains the
shared request owner. Explicit request cancellation cancels/drains its DNS or
HTTP operation. Unawaited owned Tasks still run; this is not a lazy operation.
After a DNS completion the owner checks cancellation before submitting HTTP.

The client total/connect budgets include resolution after admission. Remaining
budgets are passed to native Hyper; admission waiting retains the existing
separate client-queue semantics. DNS deadline expiry raises TimeoutException;
native HTTP timeouts retain the existing OperationException classification.
A running getaddrinfo cannot be forcibly stopped. A logical deadline/cancellation
can therefore wait for worker collection before the owner finishes, while other
Tasks/timers continue. This is not a hard shutdown-latency bound. Request shutdown
joins worker owners before releasing their request-local native state.

C-managed DNS/results remain in the existing ledger. Rust origin/address storage
and Hyper/libcurl/OpenSSL allocations are not all included. Existing1024 native
pending operations,256 total connections,64KiB headers/trailers and16MiB response
body bounds remain. No total-RSS or universal memory-safety guarantee.

## Regression design and retained failures

- `tests/http/hyper_dns.php`: localhost requests fail on the old binary; new
  behavior preserves Host, reuses the same origin's connection, isolates numeric
  origins, and requires two timer ticks while two real response bodies remain
  pending behind an independent peer barrier. It cancels one shared waiter and
  verifies both request owners finish. A reserved, non-listening IPv6 port forces
  refusal before a real IPv4 fallback. Invalid/oversized endpoints reject.
- `ext/hyper/src/tests.rs`: copied endpoint lifetime, bounded admission, origin
  identity and invalid-origin controls; existing real driver-Waker/panic tests
  remain. No network access for rejected URI tests.
- `tests/http/hyper_dns_lifecycle.php` plus separately linked `dns_gate.c`:
  real resolver worker waits at a local peer barrier while two timers run.
  Cancellation/deadline cannot abandon that worker or submit HTTP from its late
  result. Releasing the barrier must collect the job and permit. This is a
  **diagnostic controlled interleaving**, not uninstrumented acceptance.
- `tests/http/hyper_dns_fpm_request.php` and `hyper_fpm.py --dns`: additional
  one-worker/four-worker checks retain the original two-tick/real-overlap
  assertions, fatal teardown, clean next-request identity and idle resource
  checks. Original numeric/curl/Phase4 fixtures are unchanged.

`dns-before` retains the old unsupported-origin failure. `dns-r1-epoll` retains
an incomplete first implementation's missing native option-validator case.
`dns-r2-epoll` printed functional success but timed out during peer shutdown:
**FAIL**, not PASS. `dns-peer-before` independently reproduces that fixture EOF
hang without PHP. Closing active clients before waiting for server closure fixes
it (`dns-peer-corrected`). No runtime assertion was removed.

The first final DEBUG/poll controlled-DNS test failed because its recovery step
required a new localhost lookup to finish inside the deliberately30ms DNS expiry
budget. `recovery-control-before` repeats it with an80ms test-only resolver delay;
`recovery-control-after` succeeds with the same runtime,30ms expiry and two ticks.
Recovery now requires real successful HTTP through the **same client/permit** on
an independently warmed numeric connection. It no longer asserts a localhost
DNS latency SLA. Original failure and fixture source remain in the evidence root.

## Dependency attribution

The default system libcurl8.5.0 trailer-memory failure remains **FAIL**. A signed
upstream8.5.0 source and the maintainer-vetted one-line trailer free correction
were built under separate private prefixes. The identical no-PHP/no-Asyncara
control loses288 definite bytes/9blocks before and zero lost bytes after, with
reuse enabled in both. Actual Asyncara transfer checks also pass under the
isolated corrected dependency. No suppressions or disabled connection pooling.
See [dependency findings](DEPENDENCY_FINDINGS.md#matched-isolated-trailer-correction-continuation)
for exact hashes/configuration. This old source build is a diagnostic control,
not a production dependency recommendation or complete security certification.
`CURL_PREFIX` and `--curl-prefix` select explicit isolated builds; defaults still
use the host dependency.

## Validation state

The runtime's first final focused matrix passed70 controls across PHP8.3.36,
8.4.27,8.5.12, DEBUG8.5.12 and GCC ASan/UBSan8.5.12, epoll/poll. It preceded the
last PHP cancellation checkpoint guard; final acceptance reruns qualify the
complete source. The complete corrected controlled-DNS matrix passed20 checks
across the five PHP configurations and both backends. Full HTTP acceptance
stopped at the unchanged curl TLS-proxy progress test (ticks1,49.915ms elapsed,
46.097ms maximum timer lag); a separate ordinary repetition failed with ticks0.
Four old-module ordinary repetitions passed, but that does not exclude a defect.
A trace-only diagnostic reproduced ticks1 on the new module: timer registration
was delayed27ms after Task dequeue, followed by16ms between native wait exit
and HTTP completion. That trace lacks thread-CPU measurements in those intervals,
so it cannot distinguish interpreter/library execution from off-CPU delay.
Separate wait-probe controls measured1ms epoll timeouts returning after69.745ms
and90.048ms with20.7us/48.45us thread CPU. They prove wait overshoot can happen
in this environment, **not** the exact cause of either uninstrumented failure.
Reports: `http-full-final-data`, `proxy-control-*`, `proxy-trace-only-*`,
`proxy-diagnostic-*`, and `proxy-waits-*.jsonl`. All failures remain FAIL.

The later full memory/Gate A/FPM/package sequence was not completed before the
owner's new native-server request. Earlier DNS/numeric FPM successes and prefix
memory results are not substitutes for final repeats. No new package was created.
CI additions are configured; no hosted CI result is claimed.

## Remaining boundaries

Hyper still does not support HTTPS, decompression, custom headers, redirects,
proxies, other methods, uploads or streaming. HTTP/2, HTTP/3 and non-Linux
certification are absent. Default curl retains its broader existing APIs.
The historical Phase4 zero-tick cause and later timing failures remain unresolved.
No new endurance claim or13k/4x/3x performance acceptance follows from this work.
Next capability work should address verified TLS/hostname validation and its
cancellation lifecycle before advertising Hyper as a general HTTP client.
