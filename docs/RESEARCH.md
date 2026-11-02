# Research record

Initialization: 2026-11-02. This is an engineering development build, not a production release.

## Host evidence

Ubuntu 24.04.5, Linux 6.8.0-147, x86_64 KVM, six visible AMD EPYC cores. PHP CLI 8.5.11 NTS, API 20250925, no debug build. Old PHP 5.6/7.0/7.1/7.2 binaries also exist and are explicitly excluded. GCC 13.3, binutils 2.42, CMake/Make/Ninja available. phpize/php-config, autoconf and most development headers absent initially. OpenSSL 3.0.13 and libuv 1.48.0 headers present. Runtime libraries include curl 8.5.0, SQLite, libpq, MariaDB, c-ares, liburing and libevent. Swoole 6.2.3 is installed; Asyncara tests must isolate it using `php -n`.

Development packages are downloaded and extracted under `.local/sysroot`; no root installation or global settings changes. The available PHP development package is 8.5.12, the same module API as installed 8.5.11. This patch-level mismatch is recorded, not a substitute for testing exact release builds. pthreads, pipes, epoll, poll, nonblocking sockets and proc_open are available. io_uring is not advertised without a real probe.

## Supported PHP and the proposed scheduler ABI

[PHP's support table](https://www.php.net/supported-versions.php) identifies 8.4 and 8.5 as actively supported at initialization; 8.3 receives security support. Target source compatibility is 8.3–8.5; only configurations actually executed are certified in the compatibility matrix.

The [Concurrency Support RFC](https://wiki.php.net/rfc/async_scheduler_abi), version 0.9 dated 2026-07-23, says **Under Discussion** when checked. It is not an accepted baseline. Asyncara uses released Fiber APIs and does not require that ABI. [PHP Fiber documentation](https://www.php.net/manual/en/language.fibers.php) notes that destructor suspension was restricted before 8.4; cleanup here is explicit rather than relying on suspending destructors.

## Source investigation and design consequences

Upstream source snapshots were downloaded to ignored `.local/research` for study, not incorporated into this project's source. Failed downloads are not treated as inspected evidence.

| Project / primary source | Model and limits | Asyncara decision |
|---|---|---|
| [php-src](https://github.com/php/php-src/tree/php-8.5.11/Zend), zend_fibers.c and zend_execute_API.c | Native Fiber stacks and single interpreter thread; no scheduling policy | Use PHP Fiber objects; never move a zval to a native worker |
| [Swoole](https://github.com/swoole/swoole-src/blob/master/ext-src/swoole_runtime.cc) | Coroutine-aware replacements for specific internal functions and stream operations; process-wide extension coexistence concerns | Opt-in, narrow hooks with request-local dispatch, explicitly test return/error semantics |
| [OpenSwoole runtime source](https://github.com/openswoole/ext-openswoole/blob/748bdcbf2ccb960ad06bd94d099d9ab353a165ec/ext-src/openswoole_runtime.cc) | Coroutine socket stream ops and individually registered/restored internal function handlers | Found the renamed openswoole_runtime.cc after an initial 404; Asyncara keeps narrower dispatch hooks and does not copy source or assume arbitrary extensions are compatible |
| [AMP Future](https://github.com/amphp/amp/blob/3.x/src/Future.php) | Fiber-based awaiting, subscriptions and cancellation; requires cooperative drivers | Separate a result container from its executing task; removable wait subscriptions |
| [Revolt](https://github.com/revoltphp/event-loop/blob/main/src/EventLoop/Driver/StreamSelectDriver.php) | Pluggable watchers and timers; select fallback has platform descriptor limitations | Scheduler depends on one backend interface; native readiness avoids select's FD_SETSIZE |
| [ReactPHP](https://github.com/reactphp/event-loop/blob/1.x/src/StreamSelectLoop.php) | Callback/event-loop model with readiness and timers | Adapter work must avoid nesting independently driven loops |
| [parallel](https://github.com/krakjoe/parallel/blob/develop/src/scheduler.c) | Isolated interpreter threads, synchronization and transferred values; ZTS required | No userland ThreadPool on this NTS host; spawn PHP processes |
| [libuv](https://github.com/libuv/libuv/blob/v1.48.0/src/threadpool.c) | Shared native work queue, completion on loop thread | Reuse the ownership pattern conceptually; bounded request-owned native worker pool |
| [libevent epoll](https://github.com/libevent/libevent/blob/master/epoll.c) | Readiness backend with careful descriptor bookkeeping | epoll on Linux, poll fallback; level triggering |
| [liburing](https://github.com/axboe/liburing/blob/master/src/queue.c) | Kernel submission/completion rings; availability depends on kernel and sandbox | Optional capability, no dependency for filesystem correctness |
| [curl multi](https://curl.se/libcurl/c/libcurl-multi.html), lib/multi.c | Mature HTTP, TLS, redirects and connection reuse; resolver must be async | Native multi socket integration, HTTP(S) only, verify async DNS feature, bounded responses |
| [c-ares](https://github.com/c-ares/c-ares/blob/main/src/lib/ares_process.c) | Event-driven DNS, timeouts and retries | Initial fallback is getaddrinfo in native workers; do not invent TTLs |
| [OpenSSL](https://github.com/openssl/openssl/blob/openssl-3.0/ssl/statem/statem_clnt.c) | TLS state machine requires WANT_READ/WANT_WRITE handling | HTTP TLS through curl; explicit stream TLS through PHP/OpenSSL with verification |
| [SQLite](https://github.com/sqlite/sqlite/blob/master/src/prepare.c) | Blocking C API, transactions tied to connection; prepared binding avoids interpolation | Worker execution with connection serialization and native cancellation |
| [MariaDB Connector/C](https://github.com/mariadb-corporation/mariadb-connector-c/blob/3.3/libmariadb/mariadb_async.c) | start/cont APIs expose readiness and timeout states | Separate driver work; generic PDO cannot be made asynchronous by wrapping a Future |
| [libpq](https://www.postgresql.org/docs/current/libpq-async.html), fe-exec.c | PQsendQuery, PQconsumeInput, PQisBusy, PQgetResult must be sequenced | Separate driver with one active operation per connection; drain before reuse |

## Dependency licenses and risks

PHP: PHP License 3.01 / Zend Engine License. libcurl: curl license (MIT-like). SQLite: public domain. OpenSSL 3: Apache-2.0. libc: LGPL; pthread interfaces are platform facilities. Research-only: libuv MIT, libevent BSD, liburing LGPL-2.1/MIT portions, c-ares MIT, MariaDB Connector/C LGPL-2.1, PostgreSQL PostgreSQL License, AMP/Revolt/ReactPHP/parallel MIT, Swoole Apache-2.0, OpenSwoole Apache-2.0. Verify exact distributions before redistribution; extracted binaries are ignored and not vendored.

Implementation updates: libpq and MariaDB Connector/C are now optional linked dependencies; AMP/Revolt are installed development benchmark dependencies. PSR HTTP interfaces and Nyholm PSR-7 use MIT licenses. Composer.lock records tested userland versions. RFC status was rechecked at final validation and remains Under Discussion. OpenSwoole source was inspected at revision 748bdcbf2ccb960ad06bd94d099d9ab353a165ec; it is not vendored.

Risks: cancellation cannot undo committed writes; libc DNS may delay shutdown; blocking third-party extensions remain blocking; unbounded application CPU loops cannot be preempted safely; TLS and SQLite buffers consume native memory outside PHP's memory_limit; hook coexistence needs testing; process IPC must be bounded and accept data, not serialized objects. Production readiness requires multi-version, debug, sanitizer, sustained workload and platform validation beyond one host.
