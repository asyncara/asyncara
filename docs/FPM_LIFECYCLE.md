# PHP-FPM lifecycle verification

Commands (isolated loopback-only fixtures; no installed FPM configuration changes):

```sh
make audit-fpm
python3 tests/soak/fpm.py --seconds 300 --output build/fpm-soak-5m
```

Verified on PHP 8.5.11 NTS / Linux, native source commit `8461381`:

| Workload | Executed result |
|---|---|
| A: sequential lifecycle | 100 requests, one worker, pass |
| B: within-request overlap | 10 requests, four concurrent HTTP operations each, pass |
| C: incoming concurrency | 12 requests across four workers, pass |
| Recycling | 25 requests, three successive worker PIDs, pass |
| Mixed four-worker run | 300.157 seconds, 4,380 main requests, pass |
| Graceful reload | Four active requests finish; all old workers replaced |
| Abnormal termination | One explicitly killed worker replaced; healthy requests follow |
| Request timeout | One-second timeout terminates worker; replacement is healthy |
| Fatal allocation / pending I/O | Repeated deliberate fatal requests, pending handles/HTTP/Futures, healthy subsequent requests |

Request probes check scheduler, Context, Runtime configuration, globals, static
properties, pending Futures, native accounting and resource counters. Idle
workers have six descriptors and one thread. These tests establish request
isolation for the tested paths; they do not make arbitrary extension globals
Fiber-local within one request. Fatal memory exhaustion ends the request; no
in-request recovery is claimed.

Native workers originally inherited interpreter asynchronous signal handlers.
A targeted pthread signal regression reproduces this defect. Creation now blocks
asynchronous signals before starting each worker, restores the caller's exact
mask, and preserves synchronous fault signals. The regression passes ASan/UBSan;
native TSan and instrumented PHP tests pass. This is distinct from the FPM
configuration issue below.

FPM's default `process_control_timeout=0` escalated SIGQUIT to SIGTERM within
milliseconds in the first traced reload test. The fixture now specifies `5s`;
active requests finish before replacement. Configure a grace period appropriate
to real request deadlines. This is a deployment requirement, not an Asyncara
repair. See the [PHP configuration reference](https://www.php.net/install.fpm.configuration).

The first RSS assertions confused allocator cache release/reuse with growth.
All failed runs remain in the evidence directory. A native-only probe showed
zero outstanding tracked allocations every cycle and a substantial RSS drop
after diagnostic `malloc_trim`; no production allocator setting was changed.
The final fixture warms all native arenas with 96 fatal burst requests and
retains warmup and subsequent samples. Maximum growth beyond each worker's
recorded worst-case warmup peak was 384–2,476 KiB (8,192 KiB investigation limit).
Full post-warmup ranges reached 36,796 KiB as arenas were released/reused; these
ranges are retained in the report. Five minutes is not a long-term leak proof.

Linux occasionally reports a joined thread before final kernel task reaping.
The standalone `tests/audit/thread_reaping.c` probe saw 171 transient samples in
2,000 cycles, all returning to one thread within ten milliseconds. The FPM idle
check waits within its existing five-second deadline for both an accepting
worker and one thread. Persistent extra threads still fail. A four-party local
HTTP barrier establishes overlap deterministically under CPU load.

[Durable summary](phase3/fpm-summary.json) links raw evidence. Longer FPM soak,
other FPM versions, and real framework request lifecycles remain unverified.
The CI command is configured; hosted CI has not yet executed this change.

## Streaming continuation

The existing A/B/C audit is preserved. D-streaming adds 12 concurrent requests
through four FPM workers, each containing four streamed curl operations. It
checks the same barrier-established overlap, timer progress, clean request state
and idle resources. All four cases pass. This is separate short evidence; the
earlier five-minute expanded FPM run predates HTTP streaming. Paused transfer
normal/fatal cleanup is additionally exercised in CLI subprocesses.
