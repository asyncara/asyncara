# Limitations and non-goals of this build

Release assessment: **EXPERIMENTAL**; see [INDEPENDENT_AUDIT.md](INDEPENDENT_AUDIT.md). The original 0.1.0-dev archive contains audit-reproduced defects; use the corrected, separately named snapshot after review. This is a substantive development implementation, not the finished universal runtime in the project directive. The roadmap distinguishes tested paths from unfinished coverage.

- WebSocket is not implemented. There is no framing, fragmentation or close-handshake claim.
- io_uring, kqueue, IOCP and PHP interpreter threads are not implemented. Linux NTS is the executed platform matrix.
- Transparent hooks cover ordinary sleep/usleep only and differ under signal interruption. Generic PHP streams, curl_exec, PDO/MySQLi, Redis/pgsql extensions, SOAP and process APIs remain untouched. Strict hook policy cannot identify arbitrary native blocking calls.
- Stable file handles, seek, fsync and bounded streaming are implemented. Atomic replacement, cross-user permission matrices and robust typed errno codes for exists remain unfinished. The aggregate native allocation budget excludes library internals, worker stacks and bounded readiness control buffers; it is not a process/container memory quota.
- HTTP is buffered, with no response streaming, multipart helper, WebSocket upgrade support, certified HTTP/2 or HTTP/3. curl capabilities may exceed Asyncara's tested contract.
- UDP IPv6, explicit TLS renegotiation/shutdown edge cases and TLS servers are unverified. DNS is a worker getaddrinfo fallback without cache/TTL awareness or hard cancellation.
- SQLite result fetching is buffered. A busy wait can delay cancellation by its configured timeout. Manual transaction ownership requires careful application cleanup. Query cancellation cannot undo writes.
- PostgreSQL and MariaDB are tested against local servers over Unix sockets. Remote authentication/TLS variants, server-side prepared caches, async server-acknowledged cancellation, all notification/pipeline modes and MySQL-server variants are not certified. Their public API has transaction scopes but does not yet expose the entire requested common DB interface.
- Redis supports common RESP2/RESP3 data but not streaming aggregates, RESP3 attributes, arbitrary map keys, automatic cluster/sentinel routing or transparent reconnection/replay.
- The PSR-18 adapter's supplied request-body stream can block while being materialized. Framework integration is explicit adapter guidance, not tested Laravel/Symfony/WordPress lifecycle integration. Independent Revolt/ReactPHP event loops must not be nested.
- Cancellation is cooperative; CPU loops must yield or run in ProcessPool. libc DNS/native filesystem calls can delay shutdown. Signal watchers and portable wakeup integration are absent.
- Process jobs must be registry-addressable and JSON-compatible. Initial process spawning and final reaping have OS-level overhead; graceful shutdown has a bounded grace period followed by termination. CPU affinity, worker resource quotas and arbitrary closure transfer are absent.
- Repeating work keeps the runtime alive. Unobserved task failures propagate; abandoned native Future failures currently do not. Long-task diagnostics identify measured duration, not the precise blocking function. Native memory, connection counts and scheduler latency telemetry are partial.
- API stability, semantic versioning release policy, CI automation and packaging for distribution are not established. The tested development binaries should be rebuilt for each target PHP/toolchain.

Use explicit deadlines, bounded concurrency, scoped transactions, scoped connection leases and finally-based resource release. Consult WORKLOG.md for exact commands and outstanding engineering work rather than assuming a declared API implies complete coverage.
