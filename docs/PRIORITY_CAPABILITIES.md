# Current priority capability contracts

This is the current lookup for the owner's ten priority areas. Older roadmap
tables retain the source/date they originally described. They are not a current
inventory and must not be mechanically relabeled.

**IMPLEMENTED_AND_TESTED below applies to the stated API/backend contract**, not
every protocol, database release, operating system, deployment or possible
lifecycle. Asyncara remains **EXPERIMENTAL**. The primary application mode is
Native Server; FPM compatibility remains, with its historical failures retained.

| Priority area | Implemented contract | State and evidence | Outside that contract / remaining work |
|---|---|---|---|
| Rust Hyper backend | Linux HTTP/1.1 FFI transport, readiness reactor, bounded admission, DNS workers, verified TLS, connection ownership, failure handling | IMPLEMENTED_AND_TESTED: [safety](HYPER_SAFETY_REVIEW.md), [TLS](HYPER_TLS.md), [requests](HYPER_REQUESTS.md), [streaming](HTTP_STREAMING.md) | No Tokio network worker wrapper; no macOS/BSD certification, HTTP/2/3 or proxy implementation |
| Native Server | CLI routed Fiber handlers, middleware, HTTP/1.1, TLS/mTLS, bounded input/output streams, worker processes | IMPLEMENTED_AND_TESTED in [server scope](SERVER.md) and [completion evidence](COMPLETION_STATUS.md) | Half-duplex request/response streaming; no HTTP/2/3 or WebSocket upgrade integration; deployment/endurance and universal speed targets remain open |
| HTTP Client | curl multi buffered HTTP/1.1/HTTP/2, verified HTTPS, HTTP/1.1 streams, multipart files, atomic downloads, explicit HTTP/HTTPS proxying | IMPLEMENTED_AND_TESTED: [HTTP engineering](HTTP_ENGINEERING.md), [HTTP/2](HTTP2_CLIENT.md), [proxy trust](HTTPS_PROXY.md) | HTTP/2 streaming remains restricted to protect paused-transfer buffering; HTTP/3 not certified |
| Hyper HTTP Client | Explicit HttpClient API on optional Hyper, buffered methods/headers/bodies, gzip/deflate, bounded response and upload streams | IMPLEMENTED_AND_TESTED: [requests](HYPER_REQUESTS.md), [compression](HYPER_COMPRESSION.md), [streaming](HTTP_STREAMING.md) | No curl feature parity: redirects, proxies, client mTLS, Brotli/zstd and HTTP/2/3 remain unavailable |
| Broad HTTP Protocol/Proxy Certification | Local independent HTTP/1.1, buffered HTTP/2, private-CA HTTPS, forward/CONNECT, separate TLS proxy trust; explicit Basic authentication now implemented | PARTIALLY_IMPLEMENTED overall; [executed protocol matrix](HTTP2_CLIENT.md), [new authentication scope](PROXY_AUTHENTICATION.md) | Digest/NTLM/Negotiate, SOCKS, HTTP/3, broad remote appliance/platform certification are not established |
| PostgreSQL | Nonblocking libpq, binding, callback transaction affinity, bounded results/notifications, bounded prepared cache, verified TLS/client certificates | IMPLEMENTED_AND_TESTED: [compatibility](DATABASE_COMPATIBILITY.md), [prepare cache](POSTGRESQL_PREPARED.md), [mTLS](POSTGRESQL_MTLS.md), [path ownership](SQL_TLS_PATHS.md) | Tested PostgreSQL16.15/libpq16.15 configuration; no server-side cancellation acknowledgement guarantee, universal WAN/version/rotation certification; ordinary TLS unload109-byte finding retained |
| MySQL / MariaDB | Connector/C start/continue state machine, bound statements, callback transactions, pools, strict numeric-IP SAN TLS/client certificates | IMPLEMENTED_AND_TESTED separately for Oracle MySQL8.4.13 and MariaDB11.8.9: [compatibility](MYSQL_COMPATIBILITY.md), [mTLS](MYSQL_MTLS.md), [path ownership](SQL_TLS_PATHS.md) | Verified Connector/C3.4.10/OpenSSL3 configuration; DNS/SNI TLS and broader versions/authentication/prepared-cache APIs remain open |
| Redis | Nonblocking TCP/TLS, ACL authentication, bounded RESP2/RESP3 replies/pipelines, transactions, dedicated Pub/Sub, bounded notification queues | IMPLEMENTED_AND_TESTED against Redis7.0.15: [notification contract](REDIS_NOTIFICATIONS.md), [trust paths](STREAM_TLS_PATHS.md), [database scope](DATABASE_COMPATIBILITY.md) | No Cluster/Sentinel, streaming aggregates, attributes or idle notification pump |
| Native Filesystem | Owned Files/File APIs, four bounded pthread workers, partial I/O, bounded streams, atomic visibility/optional sync, admission-time filenames | IMPLEMENTED_AND_TESTED: [filesystem](FILESYSTEM.md), [lifecycle](IO_LIFECYCLE_CONTRACT.md) | Worker-backed async, not kernel nonblocking disk I/O; io_uring unavailable; no universal crash-durability/filesystem certification |
| Memory Hardening | Managed native ledger, bounded jobs/results/streams/notifications, copy guards, cancellation collection and request-owned cleanup | IMPLEMENTED_AND_TESTED for [managed boundaries](RESOURCE_LIMITS.md) and [audited ownership](NATIVE_OWNERSHIP_AUDIT.md) | Not complete process-wide accounting or a universal clean-memory certificate. [Dependency findings](DEPENDENCY_FINDINGS.md), including ordinary libpq TLS unload and distribution-PHP cyclic shutdown, retain their results |

These contracts do not mark the entire requested broad certification program
complete. Unsupported capabilities remain explicit; neither successful short
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
