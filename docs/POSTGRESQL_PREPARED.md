# PostgreSQL bounded server preparation

Status: IMPLEMENTED_AND_TESTED for the bounded opt-in cache and its documented
functional/lifecycle scope. Fatal-bailout Valgrind remains FAIL as described below;
this is not universal memory certification. Release remains EXPERIMENTAL. This is a continuation of source
`1e725468f6c3e6ce1f487c4ab7b7f711480b145e`; historical packages are unchanged.

## API and compatibility

```php
$db = Asyncara\PostgreSQL\Connection::connect(
    $options,
    preparedCacheLimit: 64,
    preparedCacheByteLimit: 262144,
)->await();
$find = $db->prepare('SELECT name FROM accounts WHERE id=$1');
$first = $find->execute([1])->await();
$second = $find->execute([2])->await();
$db->close();
```

The example schema is illustrative. Executable tests use private PostgreSQL
fixtures. `prepare()` still creates a Statement immediately; its `execute()`
submits an eager Task. Preparation starts when that Task obtains the connection's
existing operation/transaction gates. A repeated exact SQL string and parameter
count reuse the named statement on that connection. Binding values are not part
of the cache and are never retained there. `query()`/`execute()` remain unnamed.

Caching is opt-in: default capacity **0** retains the previous parameterized
query behavior, including utility/DDL statements used through `prepare()`.
Enabled caching uses PostgreSQL's actual preparation semantics; arbitrary
utility commands are not promised to support server preparation. Names beginning
`asyncara_ps_` are reserved for the connection. They are generated from a fixed
ASCII prefix and a monotonically increasing integer, never from application SQL.
The generic Database factory is unchanged; configure this driver through
`PostgreSQL\Connection::connect()` or a ConnectionPool factory.

## Bounds and lifecycle

`preparedCacheLimit` accepts 0..256 entries. `preparedCacheByteLimit` accepts
1..1048576 bytes of retained SQL, default 262144. A query larger than the cache
byte budget uses the existing unnamed path, subject to its existing query limits.
When admitting a cacheable statement, least-recently-used owned entries are
asynchronously DEALLOCATEd until both bounds permit admission. Entry accounting
is released after the deallocation result is drained. There is no silent loss
of query results and no asynchronous cleanup queue that can grow without bound.

The SQL-byte budget excludes PHP array/string metadata, libpq buffers, server
parse trees/plans and kernel memory. Entry bounds constrain count, not the exact
memory cost of an individual PostgreSQL plan. Asyncara does not claim to account
for all of that external memory. `statistics()` adds preparedStatements,
preparedBytes, preparedCacheLimit/ByteLimit and cumulative hits/misses/evictions.

The canonical Connection owns names and entries. Cloned Statements share that
same connection; no second native statement owner is created. Statements cannot
be serialized. One operation gate spans eviction, preparation, drain and query
execution, retaining the existing callback transaction affinity and ordering.
Connections remain request-local and non-copyable.

Query/preparation errors, native owner cancellation and operation deadlines
close the connection and clear its cache as with existing query failure handling.
Ending the PostgreSQL session releases its server-side prepared statements.
Cancelling or timing out one `Future::await()` waiter does **not** cancel the
shared operation; `Async::await()` expresses owned cancellation/join semantics.
Connection discard is not a server cancellation acknowledgement. A blocked server
query may remain until PostgreSQL observes disconnect; tests distinguish that
from local cleanup. No automatic query replay or reconnect is introduced.

Manual DEALLOCATE/DISCARD or application changes that invalidate server plans
can invalidate cached entries. The next use fails and closes the connection;
the driver does not infer SQL side effects, hide that error, or retry a potentially
non-idempotent statement. Reconnect explicitly or disable caching when managing
prepared-statement state yourself. Existing PostgreSQL plan/type/search_path
rules remain applicable; no universal DDL-invalidation guarantee is added.

## Native implementation

`ext/postgresql.c::asyncara_pg_prepare` uses `PQsendPrepare`; `asyncara_pg_execute`
uses `PQsendQueryPrepared` through the same bounded parameter-conversion helper
as ordinary queries. Both retain the registered connection resource and pending
flag. `asyncara_pg_read` remains the shared PQflush/PQconsumeInput/PQisBusy/
PQgetResult loop; execution retains single-row mode and bounded PHP conversion.
Prepare completion is drained before execute. No PQexec, blocking network wrapper
or extra worker pool is introduced. Socket waits suspend the current Fiber.
See the [libpq asynchronous API](https://www.postgresql.org/docs/16/libpq-async.html)
and [session/deallocation semantics](https://www.postgresql.org/docs/16/sql-deallocate.html).

## Tests and preserved failures

Raw root: `/home/coder/Asyncara-pg-cache-20261110T061903Z`.
`before-r1` fails against the preceding native build: pg_prepared_statements
contains no named statement after execute. This is an absent capability, not a
claim that the preceding parameterized queries were incorrect.

The first focused run retains its FAIL: the test assumed an outer waiter timeout
must close the shared operation. Source inspection showed the intentionally
separate waiter/owner semantics. The corrected test uses `Async::await()` for
owner cancellation and separately proves another waiter survives a local timeout.
No runtime cancellation workaround or reduced assertion was introduced.

`tests/database/prepared_pg.py --php PHP --module MODULE --backend epoll --out NEW`
(and poll) starts/stops its own PostgreSQL cluster. It checks actual server state,
binding/reuse/LRU/SQL-byte bounds, repeated eviction, cloned statements,
transactions, concurrent submissions, two ticks during pending queries, direct
native admission/invalid arguments, server-lock-blocked preparation cancellation,
independent connections and request exit/fatal cleanup. It observes remaining
server sessions independently after shutdown; local counters alone are insufficient.
Final matrix, regression and memory results follow.

## Completed targeted matrix

`matrix-r1/report.json`:15 commands PASS, comprising five builds and ten actual
fixture runs: PHP8.3/8.4/8.5 NTS, DEBUG and GCC ASan/UBSan, each epoll/poll.
PostgreSQL16.15 is isolated per run. Ordinary query waits, LRU eviction, preparation
blocked by a real table lock, cancellation, result conversion and request-fatal
cleanup all execute. C/PHP GCC instrumentation does not cover all Rust or foreign
library internals. The matrix's shutdown `instrumented:false` field means the
child command was not wrapped in Valgrind; the GCC binary is still instrumented.
Later report metadata names that field `valgrindInstrumented` explicitly.

The runner subsequently adds opt-in `--tls` for a private localhost certificate
and verify-full trust. It does not change the original plaintext matrix's scope
or imply new TLS authentication or remote-deployment certification.

## Completed regression and lifecycle review

- Gate A: PASS, 47 commands / 209.591282 seconds; full PHP 8.5 epoll and poll
  suites pass. Stock SQLite/PostgreSQL/MariaDB/Redis, independent database audit,
  resource bounds and allocation audit pass. No new Oracle compatibility claim.
- Six private-CA TLS runs: PHP 8.3/8.4/8.5, epoll/poll, verify-full and actual
  `pg_stat_ssl` verification. The new OOM child releases its PostgreSQL session.
- Final GCC ASan/UBSan: epoll/poll PASS, including deliberate fatal/OOM exits.
  This does not instrument every dependency allocation.
- Real FPM: 20 sequential and 40 four-worker main requests per backend, plus
  fatal and follow-up probes. Each main request reuses a prepared statement,
  serializes two pending queries, and retains the original four-HTTP-operation
  barrier/overlap/two-tick requirement. A separate two-tick requirement applies
  while SQL is pending. Independent server observations find no retained session.
  Idle workers have six descriptors and one thread. This short run cannot
  establish a long-term RSS trend or replace historical endurance results.
- Optional build without libpq: PASS with warnings treated as errors; MariaDB
  remains available and PostgreSQL functions remain absent.

The first broad database run failed before PHP execution because the new private
output directory made the Unix socket name exceed Linux's limit. The fixture now
uses a short owned temporary socket directory while preserving logs/data in the
chosen output directory. The failed run and the passing unchanged workload are
both retained. No runtime or timeout adjustment was required.

## Memory results: keep fatal bailout separate

Unsuppressed DEBUG PHP 8.5.12 / Valgrind 3.22, both readiness backends:

| Workload | Actual result |
|---|---|
| Full prepared-cache workload | PASS; zero errors and lost bytes |
| Exit with pending query | PASS; zero errors and lost bytes |
| PHP 8 MiB allocation exhaustion during result conversion | PASS for observed native cleanup; zero errors/lost bytes, server session gone; Zend allocator remains enabled to trigger its limit |
| E_USER_ERROR inside a Fiber with pending query | FAIL / exit99; 1,160 definite, 6,296 indirect, 872 possible bytes; session gone |

The clean cases retain 4,041 reachable bytes in 17 blocks. OOM with Zend allocation
enabled does not expose every individual PHP allocation to Valgrind. The fatal
result is not suppressed, accepted as a clean exit, or erased by later runs.
The runner records every independent shutdown case and returns FAIL if any case
fails, allowing OOM evidence to be collected without hiding the fatal failure.

Independent PHP-only fatal controls also fail without loading Asyncara; ordinary
Fiber exit and uncaught-exception controls have no losses. An identical pending
query/fatal fixture reports exactly 1,104 definite / 5,841 indirect / 872 possible
bytes with the cache enabled, disabled, and the previous source/module. Normal
pending exit is clean in all three controls. These comparisons detect no added
lost bytes from this cache in that workload; they do not prove that every driver
allocation is safe. PHP bailout/unclean-shutdown skips normal frame unwinding,
and the reported allocation stacks belong to Zend values/closures/compiler state.
The precise retained graph remains a limitation, separate from the older
distribution-PHP cyclic-shutdown finding and libpq's TLS unload allocation.
See [dependency findings](DEPENDENCY_FINDINGS.md) and
[durable report](phase7/postgresql-prepared.json).

Reproduce the additional FPM scope without changing global configuration:

```sh
python3 tests/database/prepared_pg.py --php /path/to/php --module build/asyncara.so \
  --backend epoll --fpm /path/to/php-fpm --out /new/private/output
```

Local PostgreSQL 16.15 only. No remote WAN/mTLS/rotation certification, explicit
server-cancellation acknowledgement, automatic replay, or performance claim.

## Independent PostgreSQL source distribution

Source: `891a1761cf73cd1814b02a99b4609c5e10d3ef7f`. Archive:
`build/asyncara-0.1.0-dev-postgresql-r1.tar.gz`, 606 files. SHA-256:
`3a7aff2d2a6f43b2ff7c9426b772731b335b059d1ade2fe3c1dc4b4969fde0da`.
Two archives are byte-identical; all entries match the Git source tree. A fresh
Rust/C build succeeds, followed by15 validation commands including locked Composer
installation, PostgreSQL plain/TLS/FPM, full PHP85 suites, Hyper requests and
server protocol/API on both epoll and poll. Independent module SHA-256:
`2b97c8d10211d9b9b22f1d4fd0e3836a4c78a87418a3549fdca58cb8d8fa5a6e`.

The first full-suite run failed because the clean extraction lacked the declared
Nyholm development dependency. That report remains FAIL. Installing unchanged
composer.lock dependencies fixed the test environment; the module and all tracked
source remained byte-identical. [Build attestation](phase7/postgresql-r1-package.json)
retains both reports. The fatal-bailout memory finding is unchanged and separately
reported above. No release or universal performance/endurance claim.
