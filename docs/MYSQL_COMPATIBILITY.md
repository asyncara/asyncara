# MariaDB and Oracle MySQL

The driver uses nonblocking MariaDB Connector/C start/cont calls. Oracle MySQL
is now separately tested against **MySQL Community Server 8.4.13**, not inferred
from MariaDB 11.8.9. Oracle packages were extracted into a private directory from
its HTTPS APT repository; SHA-256 matched its package metadata. No server packages
or system service configuration were installed. Package hashes are recorded in
`docs/phase3/database-summary.json`.

Oracle's prepared-statement protocol rejects START TRANSACTION. Exact
parameterless BEGIN / START TRANSACTION / COMMIT / ROLLBACK now use the connector's
nonblocking text command path. Data queries still use prepared parameter binding.
No general SQL parser or escaping fallback was introduced. Other statements
unsupported by a server's prepare protocol remain errors.

## Strict TLS

Connector/C 3.4's default verifier intentionally skips identity checks on local
and private addresses. The private-CA mismatch test reproduced acceptance of a
wrong-name certificate despite MYSQL_OPT_SSL_VERIFY_SERVER_CERT. Asyncara now
installs the documented per-connection TLS verification callback, validates the
recorded chain status and OpenSSL verification result, and checks the numeric
host against the certificate's IP SAN **before authentication**. Failures force
an error status that forbids connector password-based verification fallback.
An encrypted cipher is also required after connection establishment.

Supported strict path: Connector/C >=3.4.4 (or 3.3.14 in that series), OpenSSL 3,
numeric IP host, matching IP SAN. Executed version: 3.4.10. Older connectors or
other TLS backends fail closed when TLS is requested; Unix sockets/non-TLS remain
available. DNS names and TLS on Unix sockets are not supported. The public API
does not offer a verification-disable switch. Explicit tls=false remains an
intentional plaintext option, unsuitable for untrusted networks.

The official installed `ma_tls.h` / `ma_pvio.h` supply callback layouts. Some
development packages omit an unused ma_hash.h include; a comment-only build shim
supplies that unused include. No opaque structure layout is copied, patched or
inferred. Optional MySQL builds now require OpenSSL 3 development headers/libs.

The default authentication plugin in Oracle 8.4.13 was checked as
caching_sha2_password; that plugin and deprecated sha256_password both passed
verified TLS, prepared query and reconnect tests. Cleartext/RSA password exchange,
Oracle 8.0/9.x and external authentication plugins are not certified.

For reproducible TLS CI on distributions with older connector packages:

```sh
./scripts/build-test-connector.sh "$PWD/build/tls-connector"
MARIADB_PREFIX="$PWD/build/tls-connector/install" \
  ASYNCARA_OUTPUT=build/tls.so ./scripts/build-local.sh
ASYNCARA_EXTENSION=build/tls.so python3 scripts/test-database-tls.py \
  --output build/database-tls
```

The helper pins upstream 3.4.10 and verifies its SHA-256 before extraction. It
builds only into the requested new directory. Hosted CI remains unexecuted.

References: [Connector verification API](https://mariadb.com/docs/connectors/mariadb-connector-c/api-functions/mysql_optionsv),
[actual local-address policy](https://github.com/mariadb-corporation/mariadb-connector-c/blob/v3.4.10/plugins/auth/my_auth.c),
[Oracle binary installation](https://dev.mysql.com/doc/refman/8.4/en/binary-installation.html).
