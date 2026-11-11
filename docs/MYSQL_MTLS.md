# MySQL and MariaDB client certificates

Status: IMPLEMENTED_AND_TESTED for the configurations below. Release remains EXPERIMENTAL. This extends the existing native
Connector/C start/continue state machine; it is not a PDO or worker wrapper.

`Asyncara\MySQL\Connection::connect()` accepts `certFile`, `keyFile` and
`keyPassword` alongside the existing `caFile`. The nonempty certificate/key pair
is required together. The optional password must be nonempty and requires that
pair. Client authentication requires verified TCP TLS; Unix sockets and explicit
`tls: false` are rejected for these options. The existing strict IP SAN/chain
callback runs before authentication. The supported path still requires the
Connector/C callback/status API (tested3.4.10), OpenSSL3 and a numeric IP host.
DNS hostname/SNI support is not added by this change.

```php
$db = Asyncara\MySQL\Connection::connect([
    'host' => '127.0.0.1', 'port' => 3306,
    'user' => 'application', 'password' => $databasePassword,
    'database' => 'application', 'caFile' => '/run/secrets/ca.pem',
    'certFile' => '/run/secrets/client.pem',
    'keyFile' => '/run/secrets/client.key',
    // 'keyPassword' => $privateKeyPassword, // For an encrypted PEM key.
])->await();
```

No certificate option makes the server enforce client authentication; that
requires server account policy. Fixtures require the trusted issuer and client
subject, and verify the actual authentication plugin independently. MariaDB and
Oracle MySQL run as separate private server instances and retain separate logs.

The C resource owns copied option strings; Connector/C copies its own values,
which it releases with MYSQL. Existing connection and operation ownership,
serialization, cancellation, parameter binding and result limits are preserved.
No server cancellation acknowledgement or automatic retry is introduced.

When keyPassword is omitted, the public non-secret marker
`asyncara:no-interactive-key-input` is supplied to Connector/C's public
MARIADB_OPT_TLS_PASSPHRASE option. Plain keys ignore it; an encrypted key requires the matching password (including
this marker if somebody deliberately used it as a password). Tested differently
encrypted keys reject omission without prompting on the PHP thread. No global OpenSSL callback is replaced.
Connection options retain SensitiveParameter trace protection; this is not
secret erasure from PHP/library memory or protection against application logs.

Explicit CA, certificate and key paths undergo PHP open_basedir checks before
Connector/C access. These are prechecks, not an atomic filesystem sandbox.
File loading, key decryption and cryptographic initialization inside Connector/C
remain synchronous and outside Asyncara's memory ledger. Verified asynchronous
behavior concerns socket waits and pending queries, not arbitrary file loading.

## Reproduce

```
python3 tests/database/mtls_mysql.py --php /path/to/php \
  --module /path/to/strict-connector-asyncara.so --backend epoll --out /new/output
```

Repeat with poll. Add `--oracle-prefix /path/to/extracted/oracle/root` to test
Oracle instead of MariaDB. `--mismatch` requires a trusted but wrong-IP server
certificate to fail identity verification. `--probe` runs the initial positive
connection only, for the preserved failing-before control. `--fpm /path/to/php-fpm`
adds the existing audit lifecycle runner with sequential/four-worker mTLS queries,
original HTTP overlap/two-tick assertions and fatal pending-query cleanup.

The ordinary fixture covers encrypted keys,17 negative cases, binding,
transactions/rollback, pool release, waiter-only cancellation, operation
cancellation, timeout, repeat close and recovery. An independent connection
holds GET_LOCK until two timers run while another query is genuinely pending.
Cancelling one waiter must leave that query alive. Server process-list inspection
checks connection cleanup independently of Asyncara's native counters.
Private keys/passwords remain in the private output directory; CI uploads only
reports and logs, not the credential fixture files. None of these local tests
certifies WAN deployment, certificate rotation or other server/client versions.

## Verified checkpoint and evidence (2026-11-11)

Native source `94614a68b73226d39b97b2638b9cff45af3b5319` changes ext/mysql.c.
[Machine-readable attestation](phase7/mysql-mtls.json) records commands, hashes,
source manifest and independent FPM calculations. Raw evidence root:
`/home/coder/Asyncara-priority-20261110T205459Z`.

| Validation | Actual result |
|---|---|
| Before-control, strict Connector/C3.4.10, both server families | Certificate-required connection FAIL (Access denied), retained in mysql-mtls-before-r1 |
| PHP8.3.36/8.4.27/8.5.12 NTS, DEBUG8.5.12, GCC ASan/UBSan8.5.12; epoll/poll | mysql-mtls-matrix-r1 PASS34 commands,426.193s; five builds,20 positive/negative suites, four wrong-server-IP controls, five credential tests |
| Ordinary module unloading, DEBUG Valgrind3.22.0 with annotated Connector/C3.4.10 | mysql-mtls-memory-r1 PASS four server/backend controls; each zero errors and definite/indirect/possible loss,3888bytes/13blocks reachable, no suppressions |
| GCC ASan/UBSan with LeakSanitizer and ordinary unload | PASS in the matrix; no module-retention workaround. Rust release code is not instrumented by GCC |
| Gate A, complete PHP8.5 epoll/poll suites, SQL/Redis bounds, separate plain SQL fixtures, allocation audit | mysql-mtls-regressions-r1 PASS12 commands; Gate A47 checks,206.166s outer wall time |
| Four private mTLS FPM cases (two servers, two backends) | Each20 sequential +40 concurrent main responses;240 total and960 HTTP operations, original overlap/two-tick assertions preserved |
| Ubuntu Connector/C3.3.17 C-only | mysql-mtls-stock-r2 PASS six commands;400 explicit client-TLS rejection/FD cleanup cases per backend, ordinary database suite includes separate Oracle and MariaDB |

FPM independent raw-record review finds at least24 HTTP ticker observations and
11 database ticker observations across the four-worker cases; all expected idle
workers have6 descriptors/1thread, and server process-list observers see zero
remaining application sessions. Per-case RSS ranges and counts are retained in
`mysql-mtls-regressions-r1/independent-fpm-summary.json`. Deliberate fatal pending
query requests are followed by healthy recovery requests. These short checks
are not endurance certification or proof of all fatal-error paths.

The first stock-client control (`mysql-mtls-stock-r1`) failed because the default
system headers/runtime were3.4.10, contrary to the test's3.3 assumption. The
original FAIL is preserved; the corrected run explicitly selects the preserved
Ubuntu3.3.17 headers/library in a new private prefix. No system library changed.
This older connector still rejects verified TLS; it is not mTLS-certified.

The independent PostgreSQL/libpq109-byte unload loss remains a separate FAIL.
The MySQL scoped clean result does not erase it, the distribution-PHP cyclic
shutdown finding, or historical zero-tick failures. DNS/SNI, certificate rotation,
WAN behavior, more server/client versions and acknowledged server cancellation
remain outside this tested scope. No hosted-CI execution is inferred from local
results; CI now includes the separate MariaDB private-CA regression.

Independent package: source6dc68bd,639files, `build/asyncara-0.1.0-dev-mysql-mtls-r1.tar.gz`, SHA256`dc5e23b63604129e01611f9d29102cb99cc9886b28a05592807f7323976a020f`. Reproduced byte-for-byte and compared every tracked file with Git. Fresh Cargo/C build121.642s plus10 independent certificate/FPM/Hyper/credential checks PASS; module`c79ef0f16667eda4f56abb21a4acd120400eeed95e1e0db475bef4f0270be2f3`. See [package attestation](phase7/mysql-mtls-package.json). Not published as a GitHub release.
