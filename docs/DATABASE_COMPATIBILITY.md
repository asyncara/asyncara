# Database compatibility and TLS evidence

These are tested configurations, not universal server certification. Tests use
isolated processes, private CA keys, loopback TCP and per-run credentials.
TLS trust-file loading and cryptographic work run synchronously during connection setup; slow trust-store files can stall that thread. Verified nonblocking behavior covers socket waits and pending queries, not arbitrary library initialization.
No real WAN, managed service or certificate-rotation deployment is certified.
Third-party allocations remain outside Asyncara's native ledger.

PostgreSQL now exposes paired sslcert/sslkey and optional nonempty sslpassword
with verify-full, TCP and a per-connection noninteractive policy. Local private-CA
certificate authentication and encrypted keys are tested; see
[POSTGRESQL_MTLS.md](POSTGRESQL_MTLS.md). This does not certify implicit libpq
environment/default files. Connector/C now exposes paired certFile/keyFile and
optional keyPassword; independently tested issuer/subject-required accounts and
encrypted keys are detailed in [MYSQL_MTLS.md](MYSQL_MTLS.md). No verification
option was disabled.

| Driver / server | Backend | Verified authentication / TLS | Cancellation |
|---|---|---|---|
| SQLite 3.45.1 | Native worker, ordered connection queue | Local temporary databases | Cooperative interrupt; queued work and handles drained |
| PostgreSQL 16.15 / libpq 16 | Nonblocking libpq, descriptor readiness | SCRAM-SHA-256; verify-full; explicit certificate authentication, encrypted keys; wrong name/CA/password rejection | Discards connection; no server cancellation acknowledgement |
| MariaDB 11.8.9 / Connector/C 3.4.10 | Connector start/cont APIs | mysql_native_password over verified TLS; IP SAN, wrong name/CA/password rejection | Discards connection; no administrative KILL acknowledgement |
| MariaDB11.8.9 / Ubuntu Connector/C3.3.17 | Native start/cont, plain Unix socket only | TLS/default TCP/CA fail closed: callback/status API absent | Connection discard; local query/cancellation suite tested |
| Oracle MySQL 8.4.13 / Connector/C 3.4.10 | Same native driver, separately tested server | caching_sha2_password and legacy sha256_password over verified TLS; wrong identity/CA/password rejection | Discards connection, explicit fresh reconnect |
| Redis 7.0.15 | RESP2/RESP3 over nonblocking PHP TLS streams | ACL username/password; private CA, wrong identity/CA/password rejection | Discards connection, explicit fresh reconnect |

Private-CA tests verify SQL writes/rollback, parameter binding, two-connection
pools with four borrowers, slow-query timer responsiveness, explicit deadlines,
cleanup and reconnects. Redis tests cover RESP3 pipeline, MULTI/EXEC, Pub/Sub,
slow BLPOP and repeated cancellation/reconnection. The existing unencrypted
RESP2/RESP3 integration suite is preserved. Pool factories use the same drivers;
there is no separate hidden database pool or automatic replay of failed queries.

```sh
make build
python3 scripts/test-database-tls.py --output build/database-tls
# Separately extracted Oracle packages; never point at an installed data dir:
python3 scripts/test-database-tls.py --oracle-prefix /path/to/extracted/root \
  --output build/oracle-tls
ASYNCARA_TLS_ITERATIONS=200 python3 scripts/test-database-tls.py \
  --output build/tls-repeat
```

The expanded tests exposed and repaired three defects: MariaDB skipped local IP
identity verification; Oracle rejected START TRANSACTION in the prepared
protocol; and native readiness ignored data buffered by PHP's TLS stream cast,
causing intermittent Redis HELLO timeouts. The latter has a deterministic
`tests/stream_readiness.php` negative control plus 200 real TLS reconnect /
cancellation cycles. See MYSQL_COMPATIBILITY.md for strict TLS requirements.

PHP 8.3 and 8.4 pass the core and private-CA matrix, including 50 Redis repeat
cycles each. PHP 8.5 passes 200 Oracle/Redis TLS cycles, and instrumented PHP 8.5
passes 200 MariaDB/Redis cycles with module unloading disabled for diagnosis of
the upstream finding below. No ASan/UBSan invalid-access finding occurred.

## Unresolved upstream allocation finding

Normal library unloading after PostgreSQL TLS initialization reports **109 bytes
in two allocations** under LeakSanitizer. `tests/audit/libpq_tls_unload.c`
reproduces exactly the same result without PHP or Asyncara, after 20 connections.
libpq retains one process-global BIO_METHOD (`libpq socket`) and provides no
public cleanup API for it. Its root disappears at dlclose. The inspected
[libpq source](https://github.com/postgres/postgres/blob/REL_16_STABLE/src/interfaces/libpq/fe-secure-openssl.c)
only frees that object on initialization failure. The allocation is bounded per
loaded-library lifetime; repeated unloads accumulate it, as independently measured
in DEPENDENCY_FINDINGS.md. This is not zero-leak TLS certification. No suppression or private-pointer
cleanup was added. ZEND_DONT_UNLOAD_MODULES=1 keeps library globals reachable for
an additional diagnostic run; that run does not repair or erase the finding.

Remaining: real remote deployment certification, PostgreSQL acknowledged
cancellation, broader Oracle/MariaDB versions, certificate
rotation, broader MySQL/MariaDB mTLS deployments, server-disconnect fault matrix, Redis arbitrary pushes/attributes,
streaming aggregates, cluster/sentinel and complete notification buffering policy.

The 2026-11-10 continuation adds an opt-in bounded PostgreSQL server-preparation
cache; [POSTGRESQL_PREPARED.md](POSTGRESQL_PREPARED.md) records its exact semantics.
Local PostgreSQL 16.15, PHP 8.3/8.4/8.5 epoll/poll and private-CA verify-full cases
pass. DEBUG/GCC controls, query ordering, shared waiters, cancellation, LRU/byte
bounds and short four-worker FPM request isolation are tested. This does not
expand the remote server/authentication matrix above. Fatal-bailout Valgrind
still reports losses, reproduced independently in PHP and unchanged by this
cache in matched controls. It is not a clean universal memory result.


Phase 4 expanded mixed smoke separately exercises PostgreSQL16.15,
MariaDB11.8.9, Oracle MySQL8.4.13 and Redis7.0.15 alongside worker SQLite3.45.1.
Both epoll and poll60-second runs passed. The Oracle fixture is private Unix
socket transport; this does not expand the existing remote TLS/authentication
matrix. Standalone libpq16.15/17.11/18.6 lifetime probes test the dependency,
not Asyncara driver certification against newer PostgreSQL servers. Unresolved
findings and reproduction are in DEPENDENCY_FINDINGS.md.

The f2abb55 checkpoint has no database implementation changes from the preceding
native repair; all four hosted regression/database lifecycle jobs pass. Its
local frozen mixed campaign includes the separate Oracle and MariaDB fixtures,
while hosted mixed omits Oracle. These plain/local workloads do not
extend the TLS/authentication matrix above. No mTLS, WAN or acknowledged SQL
cancellation capability is newly advertised.

Frozen r4 mixed1h/6h/24h has now passed with PostgreSQL16.15, MariaDB11.8.9,
separate Oracle MySQL8.4.13, Redis7.0.15 and SQLite3.45.1. The SQL sockets are
local/plain and Redis uses local TCP; network TLS elsewhere in the workload is
not database TLS evidence. Existing private-CA results above retain their original
scope. Hosted r4 mixed1h passes with PostgreSQL/MariaDB/Redis and no Oracle;
its libpq18.6 dependency is recorded in the hosted environment, without implying
a new remote TLS/version matrix. See phase4/endurance-r4.json and hosted-r4.json.

## Database connection trace redaction (2026-11-10)

IMPLEMENTED_AND_TESTED, scoped to connection and task-callback argument traces.
Native `asyncara_pg_open` / `asyncara_mysql_open`, the SQL factory, public SQL
connection options and Redis's password use PHP SensitiveParameter. Spawned
callback arguments are also redacted: Closure debug output can reveal captured
connection options even after the public password parameter is protected.
No scheduling, connection, cancellation or result-ownership behavior changes.

A synthetic-marker negative control exposed9 cases before the correction.
The final11-case regression passes PHP8.3/8.4/8.5, DEBUG and GCC ASan/UBSan on
both readiness backends. Gate A224.319s and both full PHP85 suites pass.
Scoped unsuppressed DEBUG Valgrind reports zero errors and lost bytes.
[Commands and retained failures](phase7/credential-traces.json).

Redaction is not memory erasure or protection against explicit application
logging, inspecting SensitiveParameterValue, dumping captured objects, caller
frames, SQL/Redis command payloads or messages received from external servers.
Historical memory findings remain separate; no universal secret/memory guarantee.

## Redis notification association continuation (2026-11-11)

The original parser could deliver an invalidation push as a PING reply after
CLIENT TRACKING. The correction distinguishes out-of-band frames, bounds the
notification queue and preserves command/transaction result ordering. Dedicated
RESP2/RESP3 Pub/Sub remains a separate read mode. See [REDIS_NOTIFICATIONS.md](REDIS_NOTIFICATIONS.md)
for actual server/build/backend evidence and the explicit no-idle-pump limit.
This does not add attributes, streaming aggregates, Cluster, Sentinel or automatic
reconnection, and does not certify complete client-side caching semantics.

Explicit SQL TLS filename admission and handshake-resume ownership is now verified in [SQL_TLS_PATHS.md](SQL_TLS_PATHS.md). This scoped correction does not broaden the server/version matrix or erase the PostgreSQL ordinary-unload memory failure. Native Server is the primary development mode; existing FPM compatibility remains.
