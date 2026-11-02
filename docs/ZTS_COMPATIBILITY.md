# ZTS investigation

An isolated PHP **8.5.11 ZTS DEBUG** CLI was compiled from the same official
source tarball used for the NTS audit (SHA-256
`d9be75c08e8c316f4c8f4194d8fbe1750a15f6a6d9d4e3fe72082abeeb800360`).
Configuration:

```sh
./configure --prefix=/isolated/php-zts --disable-all --enable-cli \
  --disable-cgi --enable-debug --enable-zts --enable-filter --with-openssl
make -j2
make install  # only the selected isolated prefix
PHP_BIN=/isolated/php-zts/bin/php PHP_INCLUDE_DIR=/isolated/php-zts/include/php \
  ASYNCARA_OUTPUT=build/zts.so ./scripts/build-local.sh
PHP_BIN=/isolated/php-zts/bin/php ASYNCARA_EXTENSION=build/zts.so ./scripts/test.sh
PHP_BIN=/isolated/php-zts/bin/php ASYNCARA_EXTENSION=build/zts.so \
  ASYNCARA_BACKEND=poll ./scripts/test.sh
```

Compilation, module initialization, all 44 core cases, limits/resource/protocol
regressions, both event backends and PostgreSQL/MariaDB/Redis integration pass.
This includes native filesystem/SQLite/DNS workers and real ProcessPool jobs.
No compiler warnings were found in this PHP build or the extension build.

**This tests one interpreter execution thread in a ZTS CLI. It does not certify
simultaneous interpreter requests in multiple threads.** The native engine and
hook-enabled flag use ZEND_TLS; userland scheduler/context/configuration state
belongs to the request. Native worker jobs carry copied native values, never
zvals or PHP callbacks. Module hook installation and connector/curl global
initialization are process-wide. Interpreter-thread entry/exit, third-party
per-thread initialization, concurrent SAPI requests and coexistence with other
hooking extensions need separate tests and ownership review.

No ThreadPool was introduced. `Runtime::supportsUserlandThreads()` remains false.
Userland ThreadPool: **BLOCKED** pending a separate safe interpreter lifecycle,
thread-affine request state, callable/result transfer and concurrency certification.
ZTS CLI smoke compatibility: **IMPLEMENTED_AND_TESTED** for the configuration
above; general multithreaded ZTS compatibility: **NOT_IMPLEMENTED**. NTS native
workers and ProcessPool remain independently functional.
