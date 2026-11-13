# Asyncara 0.10 — initial release

Version **0.10.0**, classification **EXPERIMENTAL**. This is the owner's initial
usable milestone: native APIs for applications, a buildable source distribution,
explicit limits and a repeatable installation check. It is not a claim that every
roadmap entity is complete. Native API First — Optional Transparent Compatibility.

## Intended use and scope

Use Linux x86_64, PHP 8.3–8.5 **NTS**, an exactly matching PHP patch SDK, and CLI
applications or Asyncara Server. Pin this release, test your own application's
failure paths, keep data backups and supervise long-running processes. FPM
compatibility remains available but is not the preferred deployment target for
this milestone: the historical zero-tick endurance failure remains unresolved.

| Capability | Included scope | Boundary |
|---|---|---|
| Runtime | Fiber Tasks/Futures, timers, cancellation, groups, synchronization, bounded diagnostics, epoll/poll | No parallel Zend threads; explicit APIs do not make arbitrary PHP calls nonblocking |
| Server | `App`, routing/middleware, HTTP/1.1, TLS/mTLS, bounded streams, prefork workers | No HTTP/2/3 server or WebSocket upgrade; no universal Go performance claim |
| HTTP client | curl multi default, bounded response/upload streams, downloads and multipart | Curl HTTP/2 is buffered; HTTP/2 streaming and HTTP/3 are not certified |
| Hyper client | Opt-in Rust bridge, HTTP/1.1 and scoped ALPN HTTP/2, DNS, TLS/mTLS, compression, redirects, HTTP/HTTPS/SOCKS5 proxies | No HTTP/3 or universal proxy/authentication certification; option remains `hyper-prototype` for compatibility |
| SQL | SQLite, PostgreSQL, MySQL/MariaDB, parameters, transactions/savepoints, connection ownership | SQLite uses workers; libpq/Connector/C use nonblocking interfaces. Remote cancellation acknowledgement is not guaranteed |
| Redis | Nonblocking TCP/TLS, ACL, RESP2/RESP3 replies, pipelines and bounded Pub/Sub notifications | No Cluster/Sentinel, streaming aggregates or idle push pump |
| Files | `Files`/`File`, bounded streams, atomic replacement and optional sync | Four pthread workers; no io_uring or universal power-loss durability proof |
| Memory | Managed native ledger, bounds, cancellation/cleanup and copy policies | External library buffers, PHP allocations and process memory are not all covered by one limit |

Manual SQL transactions must finish within their owning Task and pool lease.
Prefer callback transactions when possible. Cancellation of a waiter is distinct
from cancellation of its operation; a remote server may still have acted.
See [lifecycle contract](IO_LIFECYCLE_CONTRACT.md) for complete semantics.

## Build, check and start

Use the supplied `asyncara-0.10.0.tar.gz` and checksum sidecar, or Git tag
`v0.10.0`. With the archive and checksum in the same directory:

```sh
sha256sum -c asyncara-0.10.0.tar.gz.sha256
tar -xzf asyncara-0.10.0.tar.gz
cd asyncara-0.10.0
```

Install the dependencies described in [INSTALLATION.md](INSTALLATION.md).
For **App and Hyper**, build the pinned bridge and extension:

```sh
export CARGO_TARGET_DIR="$PWD/build/hyper-cargo"
cargo build --release --locked --manifest-path ext/hyper/Cargo.toml
cd ext
phpize
./configure --enable-asyncara --with-asyncara-hyper="$CARGO_TARGET_DIR/release"
make -j2
cd ..
php -n -d extension="$PWD/ext/modules/asyncara.so" scripts/check-install.php --require-hyper
php -n -d extension="$PWD/ext/modules/asyncara.so" tests/release/smoke.php
php -n -d extension="$PWD/ext/modules/asyncara.so" examples/server.php 127.0.0.1:8080
```

In another terminal, request `http://127.0.0.1:8080/status`. Use the existing
[example](../examples/server.php) for dynamic routes, JSON, groups and middleware.
Server request/response classes live in `Asyncara\Server`; HTTP client response
classes live in `Asyncara\HTTP`. They are not interchangeable.

For a curl-only CLI library, omit Cargo and `--with-asyncara-hyper`. For native
PostgreSQL/MySQL add `--with-asyncara-pq --with-asyncara-mysql` and their development
libraries. Strict MySQL TLS needs the documented Connector/C/OpenSSL build and
IP-SAN certificate policy; hostname/SNI configurations are not certified.
Neither native SQL driver requires PDO, MySQLi or ext-pgsql interception.

Core examples work with the included `autoload.php`. For Composer/PSR integration,
run Composer with the newly built extension loaded:

```sh
php -d extension="$PWD/ext/modules/asyncara.so" /path/to/composer install --no-dev
```

No global configuration edits or privileged installation are required for these
steps. A source release contains no portable precompiled PHP module: rebuild for
the exact interpreter, architecture and dependencies you deploy. Keep TLS
verification enabled. Do not reuse fixture certificates in an application.

## Known retained findings

- Historical local FPM one-hour PASS and six-hour FAIL retain their original
  evidence. Current short tests do not certify long-duration stability.
- The PostgreSQL TLS unload report retains a 109-byte external-library allocation;
  distribution-PHP cyclic-shutdown/Valgrind differences remain separately scoped.
- A prior instrumented Native Server TLS slow-input reset and SQL-handler
  disconnect remain unattributed. Later scoped passes do not erase them.
- The system libcurl 8.5.0 trailer-allocation finding is dependency-specific.
  Use a maintained vendor build with applicable fixes; Asyncara does not patch
  the host curl library. See [dependency evidence](DEPENDENCY_FINDINGS.md).
- No universal memory-safety, latency SLA, fastest-PHP or 4x/3x benchmark guarantee.
  Broader platform, protocol and deployment coverage remains in [ROADMAP.md](ROADMAP.md).

These limitations constrain reliance on this initial release. Use it for early
applications and controlled evaluation, not as an assertion of production readiness.

## Validation and provenance

The predecessor `7ef1e2d` / final documentation `004306d` passed the scoped
PHP 8.3/8.4/8.5 epoll/poll SQL/server compatibility tests, full PHP 8.5 suites,
and an independent source build. Those are historical inputs, not substitute
tests for this version. The 0.10 release attestation records fresh builds,
executed tests, archive and binary hashes separately. Compiler/sanitizer and
endurance findings retain their original source identities.

The native C and Rust runtime behavior is unchanged for this packaging milestone;
only version metadata, release/install tooling and documentation change. License
remains the existing PolyForm Strict 1.0.0 in [LICENSE](../LICENSE), byte-for-byte.
Composer's `proprietary` metadata remains unchanged.
