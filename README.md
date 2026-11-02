# Asyncara

**Independent audit:** [findings, repaired defects and evidence](docs/INDEPENDENT_AUDIT.md). Release assessment: **EXPERIMENTAL**. The preserved original source archive contains known defects; the corrected audit snapshot is separately named.

Asyncara combines a native PHP extension with a Fiber-based Composer library for asynchronous I/O and process parallelism. **This is an experimental development build.** It targets PHP 8.3–8.5 on Linux; the current test matrix is in [COMPATIBILITY](docs/COMPATIBILITY.md).

Implemented paths include native epoll/poll waits, bounded native filesystem/DNS/SQLite workers, curl multi HTTP, TCP/UDP/TLS streams, Tasks/Futures/cancellation, structured groups, synchronization, explicit context, process pools, optional libpq and MariaDB drivers, Redis RESP2/RESP3, and a PSR-18 adapter. Transparent hooks currently cover ordinary `sleep()` and `usleep()` calls inside the runtime. There is no universal PDO or PHP-stream hook claim.

## Build and run

On the initialization host, dependencies are extracted locally:

```sh
./scripts/bootstrap-local.sh
make build
php -d extension="$PWD/build/asyncara.so" /usr/local/bin/composer install
php -n -d extension="$PWD/build/asyncara.so" --ri asyncara
php -n -d extension="$PWD/build/asyncara.so" examples/python_concurrency_tour.php
make test
```

`bootstrap-local.sh` downloads development packages without installing them globally. It currently targets Debian/Ubuntu PHP 8.5. For normal `phpize` builds and optional driver switches, see [INSTALLATION](docs/INSTALLATION.md). No extension is installed or enabled system-wide automatically.

```php
<?php
require 'vendor/autoload.php';

use Asyncara\Async;
use Asyncara\HTTP\HttpClient;

Async::run(function (): void {
    $http = new HttpClient(concurrency: 8, timeout: 10);
    $responses = Async::gather([
        $http->get('https://example.com/'),
        $http->get('https://example.org/'),
    ]);
    foreach ($responses as $response) echo $response->status, "\n";
});
```

The [complete local concurrency tour](examples/python_concurrency_tour.php) exercises real HTTP, files, SQLite, task groups, context, queue backpressure, events, locks, semaphores, deadlines, cancellation and CPU work in distinct PHP processes. It starts its own local fixture and cleans up its temporary data.

## Guarantees and boundaries

- PHP callbacks and zvals stay on their interpreter thread. Native workers perform only C/OS operations. NTS PHP userland thread execution is unavailable.
- Idle waits block in epoll/poll. Regular file and SQLite calls run in native workers; they are not relabeled nonblocking kernel I/O.
- TLS verification is enabled. HTTP redirects are opt-in. Native buffers, rows, queues, tasks and connections have explicit limits, including a configurable aggregate budget for retained Asyncara native allocations.
- Cancellation is cooperative. It cannot undo committed writes, kill arbitrary native calls, or preempt CPU-bound PHP. Process-job cancellation can terminate that worker.
- Context isolates explicit key/value maps, not superglobals or arbitrary PHP extension state.
- io_uring has passed a separate kernel NOP probe but is **not an Asyncara backend**. WebSocket, HTTP streaming/multipart, ZTS interpreter threads and platform backends beyond Linux remain incomplete or unimplemented.

See [API and semantics](docs/API.md), [architecture](docs/ARCHITECTURE.md), [driver details](docs/DRIVERS.md), [security](docs/SECURITY.md), [limitations](docs/LIMITATIONS.md), [measured performance](docs/PERFORMANCE.md), and the precise [roadmap](docs/ROADMAP.md). [WORKLOG](WORKLOG.md) records commands, failures, fixes and evidence. All benchmark samples are retained in [the raw results](benchmarks/results/latest.json).

Phase 3 resource admission and buffering policies: [RESOURCE_LIMITS.md](docs/RESOURCE_LIMITS.md).

Phase 3 hardening adds bounded pending work/results, RESP/IPC fuzz regressions,
worker signal isolation, strict database TLS identity, separate Oracle MySQL
8.4.13 tests, buffered TLS wakeup handling and atomic filesystem replacement.
A frozen one-hour mixed baseline completed; six-/24-hour certification remains
outstanding. See [release readiness](docs/RELEASE_READINESS.md),
[database matrix](docs/DATABASE_COMPATIBILITY.md), [filesystem contracts](docs/FILESYSTEM.md),
[FPM lifecycle](docs/FPM_LIFECYCLE.md), [fuzzing](docs/FUZZING.md), and
[ZTS scope](docs/ZTS_COMPATIBILITY.md). HTTP streaming, WebSocket, expanded hooks,
and framework certification remain unavailable. Release status stays EXPERIMENTAL.
