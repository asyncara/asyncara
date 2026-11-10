# Database compatibility and TLS evidence

These are tested configurations, not universal server certification. Tests use
isolated processes, private CA keys, loopback TCP and per-run credentials.
TLS trust-file loading and cryptographic work run synchronously during connection setup; slow trust-store files can stall that thread. Verified nonblocking behavior covers socket waits and pending queries, not arbitrary library initialization.
No real WAN, managed service, certificate rotation or mutual TLS deployment has
been certified. Third-party allocations remain outside Asyncara's native ledger.

Explicit client-certificate configuration is not currently exposed: PostgreSQL's
option whitelist rejects `sslcert`/`sslkey`, and the Connector/C options expose
CA verification but no client certificate/key pair. Implicit libpq environment
or default-certificate behavior has not been certified as an Asyncara mTLS API.
These are implementation gaps, not merely missing remote test results. No
verification option was disabled to work around them.

| Driver / server | Backend | Verified authentication / TLS | Cancellation |
|---|---|---|---|
| SQLite 3.45.1 | Native worker, ordered connection queue | Local temporary databases | Cooperative interrupt; queued work and handles drained |
| PostgreSQL 16.15 / libpq 16 | Nonblocking libpq, descriptor readiness | SCRAM-SHA-256; verify-full; trusted private CA, wrong name/CA/password rejection | Discards connection; no server cancellation acknowledgement |
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
rotation/mTLS, server-disconnect fault matrix, Redis arbitrary pushes/attributes,
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
