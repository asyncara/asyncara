# SQL TLS filename ownership

Status: **IMPLEMENTED_AND_TESTED** for the explicit filename and request-policy
boundaries below. Native fixes `3ea26e1` and `6ed922b`. Release remains
**EXPERIMENTAL**; the PostgreSQL TLS unload memory finding remains **FAIL**.

| Driver | Explicit filenames | Verified libraries/servers |
|---|---|---|
| PostgreSQL | sslrootcert, sslcert, sslkey | libpq16.15 / PostgreSQL16.15 |
| MySQL / MariaDB | caFile, certFile, keyFile | Connector/C3.4.10; MariaDB11.8.9 and Oracle MySQL8.4.13 tested independently |

## Reproduced defects and corrections

The previous source resolved filenames after public Task admission or during a
later native handshake continuation. A chdir between submission and completion
could select a different CA, certificate or key. Nineteen original negative
controls failed: eighteen relative-path connections across the three servers and
PostgreSQL's native root-CA policy check without a client certificate. The same
controls pass after the correction. No failure report was overwritten.

`Connection::connect()` captures explicit string filenames before scheduling,
without rewriting referenced elements in the caller's options array. The existing
`asyncara_file_path()` helper pins PHP virtual cwd using a bounded absolute name.
Native `asyncara_pg_open()` and `asyncara_mysql_open()` independently validate and
capture names before handing options to the library. Malformed or forbidden
explicit names can throw before a Future is returned. Filename length is bounded
by MAXPATHLEN, including the cwd prefix. Empty and NUL-containing names reject.

A separate follow-up reproduced all three native clients completing TLS despite
open_basedir being tightened between native open and the next poll/continue.
`asyncara_pg_poll()` and `asyncara_mysql_step()` now recheck explicit filenames
before advancing a pending connection handshake. The exact failing controls now
reject with the original warning-handler exception. Dedicated CA/cert/key tests
cover each continuation boundary. These changes do not alter readiness masks,
connection deadlines, authentication or certificate verification.

PostgreSQL's request-local resource owns at most three bounded filename copies
until handshake completion or close. Registered ownership covers partial
allocation failure; completion and destructor cleanup clear each pointer once.
These strings use Zend memory, not Asyncara's native aggregate ledger. libpq
owns its separate option copies. MySQL reuses its existing resource-owned option
strings. The public wrapper closes on handshake failure; raw native callers
remain responsible for close, with request-resource destruction as the fallback.

Bare `sslrootcert=system` retains libpq's system-root selector; `./system` remains
an explicit filename. Tests require libpq to reject an incompatible sslmode with
the selector and successfully connect with the explicit filename. See the
[libpq16 connection parameter contract](https://www.postgresql.org/docs/16/libpq-connect.html).
No engine-backed key support is added.

## Boundaries

This owns a **filename**, not its inode, contents or trust-store snapshot. Policy
checks are not an atomic sandbox against symlink/file replacement. Environment
and default library trust files, including libpq's system-root selector, are not
all mediated by PHP open_basedir. Library file reads and cryptographic setup
remain synchronous on the interpreter thread; verified asynchronous behavior
covers socket waits and pending queries. No filesystem worker or TLS backend was
replaced. Existing cancellation, connection affinity, query serialization and
pool ownership remain unchanged. Local cancellation is not remote acknowledgement.

## Executed evidence

[Machine-readable attestation](phase7/sql-tls-paths.json) records exact commands,
source manifests, module fingerprints, reports and raw-log hashes. Evidence root:
`/home/coder/Asyncara-priority-20261110T205459Z`.

| Validation | Actual result |
|---|---|
| Original source admission controls | 19 FAIL retained; identical after-controls PASS |
| Intermediate source native-resume controls | Three FAIL retained; identical final-source controls PASS |
| Final PHP8.3.36/8.4.27/8.5.12 NTS and DEBUG8.5.12; epoll/poll | PASS, including references, malformed paths, public admission, native CA/cert/key resume and warning identity |
| Final five-build matrix, including scoped GCC ASan/UBSan | PASS50 commands, 482.356s; 208 path-test PHP processes, 4,606 path cases |
| Existing mTLS behavior | PHP85 both backends/all three servers PASS; independent held-lock two-tick progress, rejection, encrypted keys, transactions and cleanup retained; GCC MySQL/MariaDB PASS |
| Unsuppressed DEBUG Valgrind ordinary unload | 40 policy/MySQL processes PASS, zero errors and definite/indirect/possible losses; 3,888 bytes reachable each |
| PostgreSQL positive TLS under ordinary Valgrind unload | Two FAIL: 96 definitely +13 indirectly lost bytes in libpq BIO_meth_new; functional12-case controls PASS separately; no suppression or module-retention workaround |
| File descriptors at process exit | No application descriptors remain; three standard descriptors plus the exact inherited Valgrind log descriptor |
| Gate A | PASS 51 commands, 210.593s |
| Complete PHP85 suites and HTTP gate | epoll/poll PASS; HTTP 97 commands, 166.005s |
| Plain DB regressions, result bounds and allocation audit | PASS |
| Native Server | Both backends: protocol/router, TLS/mTLS, response/input streaming, four-worker TLS/input checks PASS |

The first memory review remains FAIL: its observer expected only three standard
FDs and rejected Valgrind's own inherited log descriptor. Independent empty PHP
controls, with and without Asyncara, reproduce the exact fourth FD. The corrected
review accepts only that exact inherited path and rejects extra, mismatched or
non-inherited descriptors; the original reports and reviewer controls remain.
The first empty-PHP control also retains its startup failure from a missing local
debug-symbol argument; the second run uses the original matching debug-symbol
path. No runtime assertion or memory error was suppressed.

GCC PostgreSQL coverage here deliberately exercises policy rejection before TLS;
it is not a clean positive-TLS leak certification. The two ordinary Valgrind
TLS failures reproduce the previously isolated libpq-only finding documented in
[DEPENDENCY_FINDINGS.md](DEPENDENCY_FINDINGS.md). Distribution-PHP cyclic shutdown
and historical FPM zero-tick findings remain separate. FPM compatibility remains;
this continuation prioritizes Native Server and runs no new FPM campaign.

Private test database data is regenerable and removed after its server exits;
cleanup manifests preserve configuration, inventory and hashes of retained logs,
certificates and reports. No historical source, archive or failure log is removed.

## Reproduce

```sh
python3 tests/database/mtls_pg.py --paths --php "$PHP_BIN" --module "$MODULE" --backend epoll --out "$OUT/pg-paths"
python3 tests/database/mtls_mysql.py --paths --php "$PHP_BIN" --module "$MODULE" --backend poll --out "$OUT/maria-paths"
python3 tests/database/mtls_mysql.py --paths --oracle-prefix "$ORACLE_ROOT" --php "$PHP_BIN" --module "$MODULE" --backend epoll --out "$OUT/oracle-paths"
```

Repeat each family with both backends. Omit `--paths` to run the existing complete
mTLS suite. `--paths invalid policy late-policy native-policy-ca native-policy-cert
native-policy-key` selects controls that reject before TLS negotiation. Existing
output directories are refused. Independent packaging is attested separately.
