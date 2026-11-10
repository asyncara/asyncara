# MySQL and MariaDB client certificates

Status: IMPLEMENTED_BUT_NOT_FULLY_TESTED pending the compatibility, memory and
FPM matrix. Release remains EXPERIMENTAL. This extends the existing native
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
MARIADB_OPT_TLS_PASSPHRASE option. Plain keys ignore it; encrypted keys fail
without prompting on the PHP thread. No global OpenSSL callback is replaced.
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
