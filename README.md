# Asyncara

**Native Async API First — Optional Transparent Compatibility.**

Asyncara provides asynchronous PHP APIs for HTTP clients and servers, databases,
files, networking and parallel process jobs. Version **0.10.0 is EXPERIMENTAL**:
it is an initial usable release, not a production-readiness guarantee.

**[Developer documentation](https://asyncara.github.io/docs/)** ·
**[العربية](https://asyncara.github.io/docs/ar/)** ·
**[Installation](https://asyncara.github.io/docs/guide/installation/)** ·
**[Runnable examples](https://github.com/asyncara/docs/tree/main/examples)**

## Install a prebuilt bundle

For a published matching Linux/PHP package:

```sh
curl -fsSL https://github.com/asyncara/asyncara/releases/download/v0.10.0/install.sh | sh
asyncara --check
asyncara --example 127.0.0.1:8080
```

The installer detects the platform, PHP version and NTS/ZTS mode, selects an
available bundle, verifies SHA-256 and installs a launcher without changing the
original php.ini. It does not compile locally or require Python. Follow the
printed PATH instruction when necessary. A missing supported tuple is an error,
not an implicit source build.

The initial Linux bundles target Ubuntu 24.04 x86_64 and PHP 8.3.36, 8.4.27 or
8.5.12 NTS. Android 64-bit preview assets have separate requirements and a later
source identity; see the [compatibility matrix](https://asyncara.github.io/docs/guide/operations/compatibility/).
Windows and 32-bit packages are deferred. PIE metadata exists, but a complete
`pie install asyncara/asyncara` installation is not yet verified.

Anonymous downloads require public release access. For an authorized private
repository installation:

```sh
gh release download v0.10.0 --repo asyncara/asyncara --pattern 'install.sh*'
sha256sum -c install.sh.sha256
sh install.sh
```

## Start a server

Save as `server.php` and run `asyncara server.php`:

```php
<?php
use Asyncara\App;
use Asyncara\Async;
use Asyncara\Server\{Request, Response};

$app = new App();
$app->get('/status', fn() => Response::json(['status' => 'OK']));
$app->get('/work/{id}', function (Request $request): Response {
    Async::sleep(0.01); // Seconds; yields while other requests make progress.
    return Response::json(['id' => $request->param('id')]);
});
$app->post('/data', fn(Request $request) => Response::json($request->json(), 201));
$app->listen('127.0.0.1:8080');
```

Native Server uses HTTP/1.1 and Fiber handlers. Routing, middleware, bounded
streaming, TLS and worker options are explained in the
[server guide](https://asyncara.github.io/docs/guide/server/routing/).

## Choose explicit asynchronous APIs

| Capability | Execution backend |
|---|---|
| HTTP client | Event-driven curl multi; optional Rust Hyper |
| TCP / UDP / TLS | Native nonblocking socket readiness |
| PostgreSQL | Nonblocking libpq |
| MySQL / MariaDB | Connector/C asynchronous state machine |
| Redis | Nonblocking TCP and RESP |
| Files / SQLite / DNS | Bounded native workers |
| ProcessPool | Separate PHP processes |

A native API is not necessarily kernel-level async I/O. Files use pthread
workers; io_uring filesystem operations are not implemented. Optional timer
hooks do not make PDO, cURL or ordinary PHP filesystem calls asynchronous.
ProcessPool requires the complete runtime worker entry point, which the first
Linux binary bundle omits; see the
[package limitation](https://asyncara.github.io/docs/guide/io/processes/).

## Build and validate from source

Use matching PHP headers, phpize and php-config, plus C11, libcurl and SQLite
development dependencies. Optional libpq, Connector/C and Hyper need their
corresponding libraries. The Hyper-enabled server requires the matching Rust
bridge built from the committed Cargo.lock.

```sh
cd ext
phpize
./configure --enable-asyncara --with-php-config="$(command -v php-config)"
make -j2
php -n -d extension="$PWD/modules/asyncara.so" --ri asyncara
```

This minimal build does not enable optional database drivers or Hyper. Use
`--with-asyncara-pq`, `--with-asyncara-mysql` and
`--with-asyncara-hyper=/absolute/path/to/bridge/release` only with their required
dependencies. Install Composer dependencies from the repository root and load
the extension explicitly. No global PHP configuration change is required.

Tests, deterministic local fixtures, allocation audits and CI remain in this
repository. Developer guides live exclusively in
[asyncara/docs](https://github.com/asyncara/docs).

## Branches and release identity

`x.0` is the default release line; `x.0-dev` carries subsequent development.
The public Git history was cleaned of internal engineering reports. Existing
release assets retain their original source manifests and checksums; a rewritten
Git tag does not rebuild or change those assets. Short validation gates are not
long-duration endurance certification. Historical FPM reliability and memory
investigation limitations are not erased by documentation cleanup.

## License

Copyright © 2026 Ajmal Alkhaledi. Asyncara is licensed under
[PolyForm Strict License 1.0.0](LICENSE). See the complete license for permissions
and restrictions. Composer's `proprietary` value is metadata only. Third-party
libraries retain their own licenses and notices.
