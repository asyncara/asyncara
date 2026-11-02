# Installation and verification

## Requirements

Supported source range: PHP 8.3–8.5, 64-bit Unix-like environment. Tested: Linux x86_64 NTS. Required libraries: pthreads, SQLite 3 and libcurl >= 7.85 with asynchronous DNS (threaded resolver or c-ares). Required PHP features: Fibers, streams, JSON, SPL, proc_open; OpenSSL for explicit TLS streams. Optional native drivers use libpq >= 12 and MariaDB Connector/C >= 3.1 plus OpenSSL >= 3.0 development libraries. Strict MariaDB TLS requires Connector/C with the TLS callback/status API (tested 3.4.10) and the OpenSSL 3 backend; older clients fail closed for TLS. See MYSQL_COMPATIBILITY.md. PostgreSQL/MariaDB PHP extensions are not required. ext-curl is not required by the runtime; benchmarks use it as a synchronous baseline.

You need development headers matching your PHP interpreter. Do not load a binary built for a different PHP module API, ZTS mode or architecture. A downloaded PHP 8.5.12 pgsql extension failed to load in the host's 8.5.11 CLI; matching module API alone is not proof of compatibility. Asyncara itself was compiled and executed on the versions in COMPATIBILITY.md.

## Standard phpize build

Install development dependencies using your own package policy, then:

```sh
cd ext
phpize
./configure --enable-asyncara
make -j2
php -n -d extension="$PWD/modules/asyncara.so" --ri asyncara
```

Add `--with-asyncara-pq --with-asyncara-mysql` to configure to include both optional SQL drivers. Their pkg-config metadata must be available. Configure fails when explicitly requested dependencies are missing. Warnings are treated as errors.

No `make install` is needed to test. For a system installation, an administrator can run `make install` from `ext/` and add `extension=asyncara.so` to the selected SAPI's configuration. Choose this explicitly; Asyncara's scripts never do it automatically. CLI and FPM have separate configurations. An installation check is `php -m` and `php --ri asyncara`; check the actual configuration with `php --ini`.

## Isolated build used on the development host

```sh
./scripts/bootstrap-local.sh
./scripts/build-local.sh
./scripts/phpize-local.sh
```

The bootstrap extracts apt packages under `.local/sysroot`, records their SHA-256 hashes under `.local/dependencies.sha256`, and relocates build tools under `.local/bin`. The direct compiler script is a transparent alternative to phpize on this Linux host; the phpize script also runs the real configure/build process. The scripts do not require sudo. Package repository availability and versions are external prerequisites; this is not a hermetic binary toolchain distribution.

Use `PHP_INCLUDE_DIR`, `PHP_BIN`, `CC`, `ASYNCARA_OUTPUT`, `EXTRA_CFLAGS` and `EXTRA_LDFLAGS` for alternate builds. Example:

```sh
PHP_BIN=/path/to/php8.4 PHP_INCLUDE_DIR=/path/to/include/php/20240924 \
  ASYNCARA_OUTPUT=build/asyncara84.so ./scripts/build-local.sh
PHP_BIN=/path/to/php8.4 ASYNCARA_EXTENSION=build/asyncara84.so ./scripts/test.sh
```

The locally extracted 8.3 and 8.4 binaries are not installed globally. Their package versions and results are recorded in WORKLOG.md.

## Composer and examples

```sh
php -d extension="$PWD/build/asyncara.so" /path/to/composer install
php -n -d extension="$PWD/build/asyncara.so" examples/hello.php
php -n -d extension="$PWD/build/asyncara.so" examples/python_concurrency_tour.php
```

Composer loads the public API; extension installation remains independent. The repository's `autoload.php` supports core examples without Composer. The PSR adapter and baseline comparisons require Composer dependencies. Production consumers can use `composer install --no-dev`. External dependency licenses remain separate.

## License

Asyncara is licensed under the PolyForm Strict License 1.0.0.

See the [LICENSE](../LICENSE) file for the complete license terms.

Official license:
https://polyformproject.org/licenses/strict/1.0.0

Use is subject to the permissions and restrictions of PolyForm Strict 1.0.0.
The license does not grant permission to distribute the software or make
changes or new works based on it.

## Tests and optional tooling

`make test` runs local HTTP/TLS fixtures, core/IO/SQLite/process tests with an explicit 1 GiB PHP limit for the 10,000-Fiber case. Python 3 and the OpenSSL CLI are required. Generate the short-lived local TLS fixture if absent or expired:

```sh
mkdir -p build/test-certs
openssl req -x509 -newkey rsa:2048 -nodes -days 2 \
  -keyout build/test-certs/key.pem -out build/test-certs/cert.pem \
  -subj /CN=localhost -addext subjectAltName=DNS:localhost
```

`make databases` requires locally available Redis, PostgreSQL 16 binaries and MariaDB server binaries; it starts only project-owned fixtures. Override `POSTGRES_BIN` for another PostgreSQL installation. Private SQL sockets use build directories, not installed services. `make stress`, `make sanitize`, `make thread-sanitize`, `make fpm` and `make benchmark` run the corresponding checks. The ThreadSanitizer harness uses setarch only for its child process; the environment must allow that personality. FPM tests use a temporary local pool and do not modify installed configuration. The sanitizer harness tests native workers independently of a non-sanitized PHP interpreter. Instrumentation limits are in SECURITY.md.

## Source archive

A source archive can be produced from a Git checkout with `python3 scripts/package.py --output build/asyncara-NEW-NAME.tar.gz`. It packages tracked working-tree files (including staged new files), verifies their contents and writes a deterministic explicitly named archive plus SHA-256 sidecar. Tar modes normalize to 0644/0755 independently of checkout umask. Existing artifacts are never overwritten. It includes no local development packages, database data, TLS keys, vendor directory or compiled binaries. Recipients still need the documented PHP/library toolchain and Composer installation.

## Removal

For a local build, stop any fixture processes you started and remove the selected `build/`, `.local/`, `vendor/` and generated `ext/` artifacts as desired. They are ignored by Git. For an administrator-installed extension, remove its configuration entry from each SAPI, restart managed workers, then remove the corresponding installed `asyncara.so`. Do not delete unrelated PHP configuration or data directories.

Local bootstrap/tool relocation now selects the running PHP8.3/8.4/8.5 version,
or explicit `PHP_BIN` / `PHP_TOOL_VERSION`; it no longer hardcodes PHP8.5.
`build-local.sh` derives the default header API from that PHP executable.
`PHP_INCLUDE_DIR` remains an explicit override for separately installed SDKs.
A clean archive test uses each version's own phpize/configure and installs only
under its extraction's build/install directory:

```sh
python3 tests/audit/archive_install.py --archive build/NEW_ARCHIVE.tar.gz \
  --output /tmp/new-install-audit --dependencies /path/to/development-debs \
  --variants /path/to/variants.json
```

The variants JSON is an array of objects with `name`, absolute `php` executable
and `devPackage` Debian SDK archive path. Common dependency packages are freshly
extracted; generated extension/toolchain files from the working tree are not
copied. Composer and the pinned Python peer are installed inside each extraction.
The report records every step, dependency hash and installed-module hash. This
Linux/Debian-package harness complements the normal source installation steps.

Use current security patch releases and matching SDKs: the final Phase3 target
patches are8.3.36,8.4.27 and8.5.12. The October2026 PHP allocator changes expose
new functions without changing the major module API number. Both local build
runners therefore reject a different SDK patch version before compiling. On this
host, PHP8.5.12 CLI/FPM were extracted under the evidence directory; host8.5.11
was not replaced. Select PHP_BIN and FPM_BIN explicitly for those tests. Older
8.5.11 instrumentation results describe historical source configurations only.

Valgrind requires properly registered coroutine stacks. `build-test-php.sh`
builds pinned PHP8.5.12 DEBUG with --with-valgrind; set
ASYNCARA_CONNECTOR_VALGRIND=1 for build-test-connector.sh to enable its upstream
stack annotations too. Use the resulting PHP_BIN, PHP_INCLUDE_DIR and
MARIADB_PREFIX when compiling the tested module. No error suppression is used.
