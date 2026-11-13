# Asyncara 0.10

**0.10.0 is an initial, EXPERIMENTAL release for early application development
and controlled deployments on Linux x86_64 / PHP 8.3–8.5 NTS.** It is a usable
checkpoint of the existing runtime, not completion of every roadmap item or a
production-readiness guarantee. Start with the [0.10 release guide](docs/RELEASE_0.10.md)
and [installation instructions](docs/INSTALLATION.md).

Asyncara combines a native PHP extension with a Fiber-based PHP library. Its
primary interfaces are its own APIs; transparent hooks are optional. Included:

- Tasks, Futures, cancellation, timers, groups, synchronization and process pools.
- epoll/poll TCP/UDP, worker DNS, verified TLS and WebSocket client functionality.
- The default curl multi HTTP client; opt-in Rust Hyper client with bounded
  bodies/streams, verified HTTPS, compression, redirects, proxies and scoped HTTP/2.
- `Asyncara\App`: HTTP/1.1 server, routed Fiber handlers, middleware, TLS/mTLS,
  bounded input/output streaming and optional prefork workers.
- Worker-backed files/SQLite; nonblocking libpq, Connector/C and Redis clients.
  SQL manual/callback transactions and savepoints are available.

Use [the runnable server](examples/server.php), [HTTP guide](docs/HTTP_STREAMING.md),
[database compatibility](docs/DATABASE_COMPATIBILITY.md) and
[resource lifecycle contract](docs/IO_LIFECYCLE_CONTRACT.md). Build the optional
Rust bridge to use `App` or select Hyper; curl remains the default client.

The [roadmap](docs/ROADMAP.md) retains broader gaps. HTTP/3, server HTTP/2,
Redis Cluster/Sentinel, io_uring and universal transparent hooks are unavailable.
Historical FPM responsiveness failures and scoped dependency/memory findings
remain in [release readiness](docs/RELEASE_READINESS.md). Faster-than-every-runtime
or all-workload benchmark claims are not made.

## Build and run

For the local Debian/Ubuntu build, dependencies are extracted without global installation. Select a PHP interpreter and development SDK with the same patch version; the final validation used PHP 8.3.36, 8.4.27 and 8.5.12. When using an isolated interpreter, set `PHP_BIN` to its absolute path first:

```sh
export PHP_BIN="${PHP_BIN:-php}"
./scripts/bootstrap-local.sh
make build
"$PHP_BIN" -d extension="$PWD/build/asyncara.so" /usr/local/bin/composer install
"$PHP_BIN" -n -d extension="$PWD/build/asyncara.so" --ri asyncara
"$PHP_BIN" -n -d extension="$PWD/build/asyncara.so" examples/python_concurrency_tour.php
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
- io_uring has passed a separate kernel NOP probe but is **not an Asyncara backend**. WebSocket certification, ZTS interpreter threads and platform backends beyond Linux remain incomplete or unimplemented.

See [API and semantics](docs/API.md), [architecture](docs/ARCHITECTURE.md), [driver details](docs/DRIVERS.md), [security](docs/SECURITY.md), [limitations](docs/LIMITATIONS.md), [measured performance](docs/PERFORMANCE.md), and the precise [roadmap](docs/ROADMAP.md). [WORKLOG](WORKLOG.md) records commands, failures, fixes and evidence. All benchmark samples are retained in [the raw results](benchmarks/results/latest.json).

Phase 3 resource admission and buffering policies: [RESOURCE_LIMITS.md](docs/RESOURCE_LIMITS.md).

Phase 3 hardening adds bounded pending work/results, RESP/IPC fuzz regressions,
worker signal isolation, strict database TLS identity, separate Oracle MySQL
8.4.13 tests, buffered TLS wakeup handling and atomic filesystem replacement.
A Phase 3 one-hour mixed baseline completed. Later Phase 4 results and the
remaining FPM gates are tracked separately. See [release readiness](docs/RELEASE_READINESS.md),
[database matrix](docs/DATABASE_COMPATIBILITY.md), [filesystem contracts](docs/FILESYSTEM.md),
[FPM lifecycle](docs/FPM_LIFECYCLE.md), [fuzzing](docs/FUZZING.md), and
[ZTS scope](docs/ZTS_COMPATIBILITY.md). [HTTP streaming](docs/HTTP_STREAMING.md) now covers bounded HTTP/1.1 bodies, single/multiple-file MIME and optional atomic downloads. [WebSocket](docs/WEBSOCKET.md) now has tested bounded framing and independent client/server interop. Expanded hooks
and framework certification remain unavailable. Release status stays EXPERIMENTAL.

Current validation targets patched PHP8.3.36,8.4.27 and8.5.12 with matching SDKs.
Later Phase 4 hardening repairs UDP endpoint collisions, descriptor-exhaustion
cleanup, destination-port wrap and implicit blocking resolver fallback. The
corrected r4 package is independently tested on all three PHP branches. Linux
PHP8.5.12 passed mixed1h/6h/24h and hosted FPM1h workloads. Both local FPM
attempts failed responsiveness; diagnosis and observer regression tests preserve
those failures. That r4 snapshot did not test six-/24-hour FPM. The later d74 lifecycle campaign passed one hour, failed its six-hour stage, and never started 24 hours. Release stays **EXPERIMENTAL**;
see [release readiness](docs/RELEASE_READINESS.md) for tested scopes, preserved
failures and remaining certification work.
