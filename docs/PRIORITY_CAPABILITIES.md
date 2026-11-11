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
