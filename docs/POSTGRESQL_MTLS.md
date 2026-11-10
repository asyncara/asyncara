# PostgreSQL client certificates

Status: IMPLEMENTED_BUT_NOT_FULLY_TESTED while the scoped memory, FPM and
regression checks finish. Release remains EXPERIMENTAL.

The existing PostgreSQL driver accepts `sslcert`, `sslkey` and `sslpassword`
through `Connection::connect()` and the existing SQL factory. This extends
`asyncara_pg_open()`; queries still use nonblocking libpq and the existing
connection/operation gates. No replacement driver or global OpenSSL/libpq
password callback is installed.

```php
$db = Asyncara\PostgreSQL\Connection::connect([
    'host' => 'db.example.test',
    'port' => '5432',
    'dbname' => 'application',
    'user' => 'application',
    'sslmode' => 'verify-full',
    'sslrootcert' => '/run/secrets/database-ca.pem',
    'sslcert' => '/run/secrets/client.pem',
    'sslkey' => '/run/secrets/client.key',
    // For an encrypted PEM key, supply its nonempty sslpassword as well.
])->await();
```

Certificate and key must be supplied together and must be nonempty. Explicit
client TLS requires `verify-full` (also the default), a TCP host and libpq13 or
newer for its public noninteractive key-password option. That API minimum is
not certification of every libpq version. OpenSSL engine key identifiers are
rejected. Explicit certificate/key/CA paths undergo PHP's open_basedir check
before library access. This is a path precheck, not an atomic filesystem sandbox.

For an unencrypted key, omit `sslpassword`. Asyncara supplies the public,
non-secret marker `asyncara:no-interactive-key-input` to prevent libpq/OpenSSL
from prompting on the PHP thread. Unencrypted keys ignore this value. For an
encrypted key, supply the actual password; a wrong password fails. An explicit
empty password is rejected. This does not erase secrets from PHP/libpq memory
or protect arbitrary application logging. The connection arguments retain their
SensitiveParameter trace protection.

Explicit client TLS also sets `gssencmode=disable` for that connection, so GSS
encryption cannot silently take precedence over the requested TLS transport.
Other connections retain their previous options. Trust/key file loading and
cryptographic setup remain synchronous inside libpq; slow files can block that
setup. Network waiting and pending queries retain descriptor readiness/Fiber
suspension. No claim of asynchronous disk reads inside libpq is made.

The server must require and verify the client certificate, for example through
its certificate authentication policy. Client options alone do not prove what
the server's authentication policy enforces. The private test cluster requires
certificate authentication and checks `pg_stat_ssl.client_dn` independently.

See the official [libpq connection options](https://www.postgresql.org/docs/16/libpq-connect.html)
for key password and TLS/GSS behavior. This driver exposes a bounded subset of
those options; it does not expose every libpq connection feature.

## Reproducible checks

```
python3 tests/database/mtls_pg.py --php /path/to/php --module /path/to/asyncara.so \
  --backend epoll --out /new/private/output
```

Repeat with poll. `--probe` runs only the positive certificate connection and
server identity check, providing the failing-before control against the old
whitelist. `--fpm /path/to/php-fpm` adds the existing sequential/four-worker
prepared-query fixture with its unchanged two-tick/HTTP overlap assertions.
An independent local Unix-socket observer checks server session cleanup; FPM
application queries still use verified TCP TLS and client certificates.

The fixture creates a private CA, server and client certificates, an encrypted
key, untrusted/client-name controls and an isolated PostgreSQL16 cluster. It
changes no installed service or trust store. The test covers 17 invalid cases,
both supplied/default verify-full, prepared reuse, transaction affinity,
operation cancellation, repeated close and credential redaction. An independent
connection holds a PostgreSQL advisory lock until two timers execute while the
other query remains pending; eventual timer execution after completion cannot
pass. Server-side session observation supplements native/Future counters.

The native ledger does not count all libpq/OpenSSL allocations. Ordinary GCC
library unloading still reports the historical 109-byte BIO-method loss;
diagnostic module retention is recorded separately. These results must not be
described as universal memory-clean certification. Remote cancellation
acknowledgement, WAN deployments, managed services and certificate rotation
remain separate unverified capabilities.
