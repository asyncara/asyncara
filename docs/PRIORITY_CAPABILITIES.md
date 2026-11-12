# Priority capability evidence and open work

Updated 2026-11-12. [ROADMAP.md](ROADMAP.md#current-priority-status) is the
canonical source of overall priority status. **All ten priority entities remain
PARTIALLY_IMPLEMENTED.** This document records tested subsets and outstanding work.

The earlier summary of nine completed entities was incorrect: it substituted
narrow tested API contracts for the requested entity-level scope. That claim is
withdrawn. Existing implementation and passing test evidence remain valid within
their recorded scope; they do not close the gaps in the roadmap.

In the **Capability evidence** column, `IMPLEMENTED_AND_TESTED` refers only to the
named capability. The separate **Overall status** column is the state of the
requested entity. No runtime, assertion or historical evidence was changed to
make this documentation correction. Release remains **EXPERIMENTAL**.

| Priority area | Overall status | Tested capability scope | Capability evidence | Remaining work / limitations |
|---|---|---|---|---|
| Rust Hyper backend | PARTIALLY_IMPLEMENTED | Linux HTTP/1.1 FFI transport, readiness reactor, bounded admission, DNS workers, verified TLS, connection ownership, failure handling | IMPLEMENTED_AND_TESTED: [safety](HYPER_SAFETY_REVIEW.md), [TLS](HYPER_TLS.md), [requests](HYPER_REQUESTS.md), [streaming](HTTP_STREAMING.md) | No macOS/BSD or HTTP/3 certification. ALPN HTTP/2 now has [executed scope](HYPER_HTTP2.md); explicit HTTP/HTTPS proxies have [executed validation](HYPER_PROXY.md). Whole-entity completion remains open |
| Native Server | PARTIALLY_IMPLEMENTED | CLI routed Fiber handlers, middleware, HTTP/1.1, TLS/mTLS, bounded input/output streams, worker processes | IMPLEMENTED_AND_TESTED in [server scope](SERVER.md) and [completion evidence](COMPLETION_STATUS.md) | Retained GCC/poll/TLS slow-input reset and initial SQL-handler disconnect remain unexplained; half-duplex request/response streaming; no server HTTP/2, HTTP/3 or WebSocket upgrade integration; the [HTTP/2 evidence](HYPER_HTTP2.md) is for outgoing client operations, including calls from a server handler; deployment/endurance and universal speed targets remain open |
| HTTP Client | PARTIALLY_IMPLEMENTED | curl multi buffered HTTP/1.1/HTTP/2, verified HTTPS, HTTP/1.1 streams, multipart files, atomic downloads, explicit HTTP/HTTPS proxying | IMPLEMENTED_AND_TESTED: [HTTP engineering](HTTP_ENGINEERING.md), [HTTP/2](HTTP2_CLIENT.md), [proxy trust](HTTPS_PROXY.md) | HTTP/2 streaming remains restricted to protect paused-transfer buffering; HTTP/3 not certified |
| Hyper HTTP Client | PARTIALLY_IMPLEMENTED | Explicit HttpClient API on optional Hyper, buffered methods/headers/bodies, gzip/deflate, bounded response and upload streams | IMPLEMENTED_AND_TESTED: [requests](HYPER_REQUESTS.md), [compression](HYPER_COMPRESSION.md), [streaming](HTTP_STREAMING.md) | No curl feature parity: HTTP/3 remains unavailable; explicit proxies have [executed validation](HYPER_PROXY.md); [ALPN HTTP/2](HYPER_HTTP2.md) has local buffered/streamed evidence. Redirects, client mTLS and bounded multi-file MIME are now implemented in the working continuation, with scoped matrix results and retained failures in [Hyper completion](HYPER_COMPLETION.md); whole-entity acceptance is still open |
| Broad HTTP Protocol/Proxy Certification | PARTIALLY_IMPLEMENTED | Local independent HTTP/1.1, buffered HTTP/2, private-CA HTTPS, forward/CONNECT, separate TLS proxy trust; explicit Basic authentication now implemented | Scoped passing evidence: [executed protocol matrix](HTTP2_CLIENT.md), [new authentication scope](PROXY_AUTHENTICATION.md) | Digest/NTLM/Negotiate, SOCKS, HTTP/3, broad remote appliance/platform certification are not established |
| PostgreSQL | PARTIALLY_IMPLEMENTED | Nonblocking libpq, binding, callback transaction affinity, bounded results/notifications, bounded prepared cache, verified TLS/client certificates | IMPLEMENTED_AND_TESTED: [compatibility](DATABASE_COMPATIBILITY.md), [prepare cache](POSTGRESQL_PREPARED.md), [mTLS](POSTGRESQL_MTLS.md), [path ownership](SQL_TLS_PATHS.md) | Tested PostgreSQL16.15/libpq16.15 configuration; no server-side cancellation acknowledgement guarantee, universal WAN/version/rotation certification; ordinary TLS unload109-byte finding retained; initial Native Server pending-query disconnect remains unexplained |
| MySQL / MariaDB | PARTIALLY_IMPLEMENTED | Connector/C start/continue state machine, bound statements, callback transactions, pools, strict numeric-IP SAN TLS/client certificates | IMPLEMENTED_AND_TESTED separately for Oracle MySQL8.4.13 and MariaDB11.8.9: [compatibility](MYSQL_COMPATIBILITY.md), [mTLS](MYSQL_MTLS.md), [path ownership](SQL_TLS_PATHS.md) | Verified Connector/C3.4.10/OpenSSL3 configuration; DNS/SNI TLS and broader versions/authentication/prepared-cache APIs remain open |
| Redis | PARTIALLY_IMPLEMENTED | Nonblocking TCP/TLS, ACL authentication, bounded RESP2/RESP3 replies/pipelines, transactions, dedicated Pub/Sub, bounded notification queues | IMPLEMENTED_AND_TESTED against Redis7.0.15: [notification contract](REDIS_NOTIFICATIONS.md), [trust paths](STREAM_TLS_PATHS.md), [database scope](DATABASE_COMPATIBILITY.md) | No Cluster/Sentinel, streaming aggregates, attributes or idle notification pump |
| Native Filesystem | PARTIALLY_IMPLEMENTED | Owned Files/File APIs, four bounded pthread workers, partial I/O, bounded streams, atomic visibility/optional sync, admission-time filenames | IMPLEMENTED_AND_TESTED: [filesystem](FILESYSTEM.md), [lifecycle](IO_LIFECYCLE_CONTRACT.md) | Worker-backed async, not kernel nonblocking disk I/O; io_uring unavailable; no universal crash-durability/filesystem certification |
| Memory Hardening | PARTIALLY_IMPLEMENTED | Managed native ledger, bounded jobs/results/streams/notifications, copy guards, cancellation collection and request-owned cleanup | IMPLEMENTED_AND_TESTED for [managed boundaries](RESOURCE_LIMITS.md) and [audited ownership](NATIVE_OWNERSHIP_AUDIT.md) | Unresolved memory/shutdown findings and incomplete broader containment/lifecycle coverage. Not complete process-wide accounting or a universal clean-memory certificate. [Dependency findings](DEPENDENCY_FINDINGS.md), including ordinary libpq TLS unload and distribution-PHP cyclic shutdown, retain their results |

No priority entity is marked complete. Capability-level test evidence below
does not establish entity-level completion. Unsupported capabilities remain explicit; neither successful short
tests nor this table establish six-hour/24-hour endurance or fastest-in-PHP
performance. The original Phase4 one-hour PASS and six-hour zero-tick FAIL keep
their original identities.

## Current implementation continuation

Native/test checkpoint `2a96f61` adds bounded Basic proxy authentication and
redacts sensitive native submission/root callback arguments. A separate
ConnectionPool constructor correction allows configuration before App::listen
without starting connections: initial vacant Queue slots use non-suspending
admission. Factories and all actual borrowers still run in the scheduler.

The new Native Server service fixture combines SQL mTLS/pools/transactions,
Redis TLS/ACL/RESP3, curl or Hyper self-HTTP and worker-backed atomic file I/O.
It exercises shared query waiters, owner cancellation, timeouts, handler failure,
pool recovery and final cleanup, with independent server-side session counts.
Validation of that combined fixture and final package is recorded separately
from the earlier per-subsystem evidence above. One initial PHP83 service run
failed during a pending-query request; it remains preserved and is not explained
by the diagnostic rerun passing. No reliability failure is erased by status labels.

## Final-source validation (2026-11-12)

Runtime/native source is `2a96f61`; test/observer checkpoint is `1e8be3c`.
The exact [source/module/report attestation](phase7/proxy-services.json)
separates source identity from historical results and the later package.

| Scope | Actual result |
|---|---|
| Authenticated proxy matrix | PASS40 commands; PHP8.3.36/8.4.27/8.5.12, DEBUG/GCC, epoll/poll;161.896s |
| SQL/Redis/HTTP/files Native Server matrix | PASS38 commands;56 server processes,1232 main requests,336 required ticks during pending queries;452.078s |
| Gate A | PASS53 checks;199.987s |
| Complete PHP8.5 suites | PASS epoll and poll |
| HTTP regression gate | PASS101 checks;185.439s |
| Database bounds/plain integrations/allocation audit | PASS |
| Native Server protocol/TLS/input/output/four-worker scopes | PASS14 commands across epoll/poll |
| Final regression supervisor | PASS21 commands;511.101s |
| Ordinary DEBUG Valgrind, separate proxy memory scope | PASS4 processes;0 errors/definite/indirect/possible losses |
| Whole-handler DEBUG Valgrind repetitions | PASS3 processes including epoll/poll;0 errors/loss; original FAIL preserved |

The split memory diagnostic has two requests instead of one and is explicitly
separate. Later whole-handler requests took9.492s,9.633s and9.851s under Valgrind,
with the original10s server deadline unchanged. The first run has no captured
server timeout counter; cost-of-instrumentation/deadline attribution remains an
inference, not an established cause. Its forced-termination allocation report
is not relabeled a normal-shutdown leak or PASS. Another diagnostic fixture
iteration returned500 because it passed Request into a string helper; only the
test adapter was fixed. All raw failures remain in the attested evidence root.

Source-distribution independent build is attested separately after packaging;
no GitHub release or remote push is implied by these local results.

## Independently built source distribution

Packaged source `891c48c6626bdeb551a26d1c9e9f9f127a7424f2` contains 707 tracked files,
verified byte-for-byte against Git before and after extraction/build checks.
Archive: `build/asyncara-0.1.0-dev-proxy-services-r1.tar.gz`.
SHA-256: `32ccc430b2b71a931083b58b72354bb7e5dd8045f4cf2a757af7cf39826d2ec5`. Repeated packaging is byte-identical.

Fresh Rust/C build PASS in 141.291s; independent
module SHA-256 `e2ceebbcbbfdb88dc06c094b21e7f7525e8f42c44659108527a90ca563ee59cb`.
All 29 build/test commands PASS, including SQL/Redis/HTTP/file
handlers on all three SQL servers and both backends (264 main service
requests), authenticated proxies/HTTP2, copy/trace controls and 14 server scopes.
[Package attestation](phase7/proxy-services-package.json) retains exact commands,
hashes and limits. This later attestation is not inside the archived source.
No release publication or remote push was performed.
