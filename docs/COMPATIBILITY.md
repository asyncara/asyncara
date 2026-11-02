# Verified compatibility

The following evidence is from this Linux x86_64 development host on 2026-11-02. The [independent audit](INDEPENDENT_AUDIT.md) supersedes earlier readiness claims and provides the complete function-by-function hook matrix and reproduced defects. Passing these cases does not establish production support on every build or extension combination.

| PHP build | Native compile | Core/IO/hook/process suite | PostgreSQL/MariaDB/Redis suite |
|---|---|---|---|
| 8.3.36 NTS, locally extracted CLI/dev | warnings-as-errors pass | 44 pass + four dedicated limit/lifecycle suites | pass |
| 8.4.27 NTS, locally extracted CLI/dev | warnings-as-errors pass | 44 pass + four dedicated limit/lifecycle suites | pass |
| 8.5.11 NTS host CLI, 8.5.12 API-compatible dev headers | warnings-as-errors pass | 44 pass + four dedicated limit/lifecycle suites | pass |
| 8.5.11 with host's normal extension configuration (including Swoole 6.2.3) | same binary | 44 pass + four dedicated limit/lifecycle suites | not a separate SQL coexistence certification |
| 8.5.11 debug / ASan+UBSan debug | independently built from source | 44 pass plus dedicated suites and audit regressions | instrumented database suites pass |
| ZTS | not executed | not certified | not certified |

All three CLI versions also pass native queue/size/permission limits, aggregate-budget rejection/recovery, PHP memory-limit bailout and stable-file-handle suites. Independent 8.5 FPM tests separately pass 100 sequential requests, four overlapping operations per request, and 12 requests across four workers. Idle descriptors/threads remain stable; context/configuration/hooks reset. A fatal request is torn down before the same worker serves a fresh request.

PHP 8.5 also passes the core suite with `ASYNCARA_BACKEND=poll`. This verifies the Linux poll fallback, not BSD/macOS/Windows portability. PHP 8.1/8.2 and unreleased versions are intentionally outside the configured source range.

## Function-by-function transparent hook matrix

Hooks are disabled outside Async::run and disabled by default. HOOK_ALL/Hook::ALL means all implemented hook groups (currently TIME), not every PHP function. Runtime::enableHooks returns enabled names, unsupported groups and reasons.

| Traditional operation | Classification inside configured runtime | Evidence and limits |
|---|---|---|
| sleep(integer >= 0) | asynchronous timer, partial compatibility | return 0, zero delay, negative argument and Fiber scheduling tested; signal interruption/remaining-seconds behavior differs and is not certified |
| usleep(integer >= 0) | asynchronous timer, partial compatibility | null return, negative argument, overlapping timers tested; OS signal interruption semantics not reproduced |
| fopen / fclose / fread / fwrite / stream_get_contents | blocking fallback / unsupported hook | use explicit IO APIs; arbitrary stream wrappers remain unchanged |
| file_get_contents / file_put_contents | blocking fallback / unsupported hook | Files API runs native workers; HTTP API runs curl multi |
| unlink / rename / mkdir / rmdir / copy / metadata | blocking fallback / unsupported hook | individual explicit Files counterparts tested |
| stream_socket_client / stream_socket_server / fsockopen | blocking fallback / unsupported hook | TcpStream/TcpServer implement explicit async readiness |
| stream_select | blocking fallback / unsupported hook | scheduler uses its native wait; no replacement |
| curl_init / curl_setopt | ordinary PHP cURL functions | no replacement required or claimed |
| curl_exec / curl_multi_* | unsupported transparent integration | separate native HttpClient implementation; no ownership of PHP CurlHandle objects |
| PDO SQLite / PDO MySQL / MySQLi | unsupported hook | use tested independent drivers; no PDO compatibility claim |
| ext-redis / ext-pgsql | unsupported hook | explicit Redis and libpq drivers are separate implementations |
| SOAP / sockets extension / process functions | unsupported hook | no generic blocking interception |

Policies: ALLOW_BLOCKING reports missing groups; WARN additionally emits a warning; STRICT rejects a request to enable unavailable groups. **STRICT does not intercept unknown blocking operations.** The scheduler measures long task steps after control returns and reports their duration without claiming to identify the native call responsible.

The native dispatch hook chains Zend's execute-internal hook and checks function identity; it does not patch internal function handlers. It is registered at module startup and restored at module shutdown when still owned. Request-local flags are reset at request shutdown. Host coexistence tests do not establish safe composition with every profiler, debugger or coroutine hook extension.

## Explicit operation matrix

| Capability | Category | Verification |
|---|---|---|
| TCP / UDP | nonblocking readiness | local TCP, 3 MB partial writes, EOF, refusal, 100 connections, UDP empty/binary datagrams |
| HTTP/HTTPS | native nonblocking curl multi | overlap, timeout, cancellation, broken connection, chunking, compression, redirects, JSON/form, large response, TLS trust/hostname failure; bounded HTTP/1.1 response/upload streaming and one-file MIME |
| DNS | worker-backed async | localhost and deterministic invalid-name failure; no TTL cache |
| Filesystem | worker-backed async | regular files, binary/empty/large chunks, copy/rename/remove/stat/list, cancellation drainage; stable handles, seek/sync, path replacement, handle limits and early stream cleanup |
| SQLite | worker-backed async | bindings, serialized operations, rollback, interruption, connection closure |
| PostgreSQL | nonblocking libpq | local Unix sockets and private-CA SCRAM/verify-full TCP, parameters, transactions, notifications, cancellation and independent connection overlap |
| MariaDB | nonblocking Connector/C | 11.8.9 Unix sockets and private-CA TLS/IP SAN, prepared bindings, transactions, cancellation and overlap |
| Oracle MySQL | nonblocking Connector/C | separately tested 8.4.13, caching_sha2_password / sha256_password over verified TLS; transaction control fixed; see MYSQL_COMPATIBILITY.md |
| Redis | nonblocking RESP over TCP | local RESP2/RESP3, pipeline, errors, transactions, Pub/Sub and cancellation |
| ProcessPool | multi-process CPU parallelism | distinct PIDs, overlapping CPU intervals, crash/timeout/replacement, framed data |
| Native workers | native thread execution | filesystem/DNS/SQLite only; no PHP callback execution |
| PHP ThreadPool | unavailable | no interpreter-thread executor; ZTS CLI testing does not enable it |
| io_uring | not implemented | separate kernel NOP probe succeeded; no Asyncara backend claim |

Phase 3: private-CA database matrix is in [DATABASE_COMPATIBILITY.md](DATABASE_COMPATIBILITY.md); single-interpreter ZTS scope is in [ZTS_COMPATIBILITY.md](ZTS_COMPATIBILITY.md). Kernel readiness now includes PHP-buffered stream data, with a deterministic regression and repeated TLS reconnect tests.

WebSocket client and raw TCP server upgrade are explicit APIs tested with an
independent Python websockets17.4 peer on PHP8.3/8.4/8.5. WSS client certificate
failures are tested. Extensions, browser/framework integration, FPM upgrades
and server-WSS certification are unavailable/unverified; see WEBSOCKET.md.

Expanded Linux loopback networking tests now cover IPv4/IPv6 TCP/UDP, dual-address
DNS, TLS1.2/1.3 SNI/ALPN, partial I/O and cancellation on PHP8.3/8.4/8.5.
See [BACKENDS.md](BACKENDS.md#expanded-local-networking-matrix) for executed
commands, injected DNS cleanup behavior and unverified TLS/remote boundaries.
