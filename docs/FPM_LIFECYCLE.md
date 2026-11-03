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

[Durable summary](phase3/fpm-summary.json) links this historical run's raw
evidence. Later patched-PHP and hosted results are recorded below. Longer FPM
soak, other FPM versions and real framework request lifecycles remain unverified.

## Streaming continuation

The existing A/B/C audit is preserved. D-streaming adds 12 concurrent requests
through four FPM workers, each containing four streamed curl operations. It
checks the same barrier-established overlap, timer progress, clean request state
and idle resources. All four cases pass. This is separate short evidence; the
earlier five-minute expanded FPM run predates HTTP streaming. Paused transfer
normal/fatal cleanup is additionally exercised in CLI subprocesses.

## Expanded streaming soak: historical RSS finding

The first five-minute expanded run at cdea225 passed A/B/C/D, recycling,
reload/replacement, request state and idle fd/thread checks, but **FAILED** the
unchanged8,192KiB growth-over-warmup assertion. One worker peaked27,256KiB above
its recorded warmup. The failure prevented this run's final timeout case; prior
timeout evidence remains separate. Raw ranges and samples are preserved in
phase3/fpm-stream-investigation.json. The threshold was not raised or removed.

A separate100-cycle diagnostic (80 fatal-burst requests,320 streamed requests,
400 allocator observations) measured glibc in-use bytes2,136,832–2,225,472 while
free arena bytes reached113,636,704. Native request accounting was zero and idle
workers had6fds/1thread. Test-only malloc_trim reduced RSS substantially without
freeing additional application objects; exact per-worker values are retained.
This is strong evidence of allocator caching/fragmentation under the deliberate
fatal-allocation workload, not proof that every RSS fluctuation is harmless.
No allocator settings or production trimming were introduced. The failed RSS
criterion required the dedicated-mapping repair described below. Size FPM
process limits/recycling from measured RSS, not only Asyncara's native ledger.

`tests/audit/fpm_allocator.py --output NEW_DIR` compiles a separate test-only
PHP8.5/glibc diagnostic module. Its functions are absent from production builds.
The ordinary FPM soak now writes a failure.json on unsuccessful runs so partial
successful cases and provenance survive. Longer mixed/FPM certification remains
incomplete regardless of earlier passing short runs.

An unchanged300-second repeat also failed: growth beyond recorded warmup peaks
was0/27,340/12,600/14,356KiB across four workers, with4,692 idle samples.
This reproduced the failure under the then-current fatal-allocation mix; it was
not marked passed based on a lucky rerun. The later repair retained the same
assertion and workload.

## PHP8.5.12 and mapped-buffer follow-up

The October2026 PHP security releases defer soft OOM and terminate the process at
the hard limit. Asyncara's native batch conversion could continue allocating
after soft OOM and kill a worker (exit137). A four-result negative control
reproduces it. Fallible string conversion and pending-OOM checks now preserve
ordinary request fatal teardown on8.3.36/8.4.27/8.5.12. Genuine hard-limit process
termination still skips cleanup; it is never advertised as recoverable.
[PHP memory-limit semantics](https://www.php.net/manual/en/ini.core.php#ini.memory-hard-limit-percent).

The first8.5.12 five-minute rerun retained the RSS failure: one worker exceeded
warmup by14,792KiB. Dedicated mappings for Asyncara buffers >=1MiB then passed
the unchanged five-minute test:3,912mixed requests/300.193s, all lifecycle faults
passed, idle RSS16,968–19,420KiB, maximum growth beyond warmup2,968KiB. All native
threads were reaped and descriptors returned to six. Earlier failed reports stay
valid historical evidence. This fixes the reproduced allocator-retention case;
it does not establish a universal process-memory bound or6h/24h certification.
Evidence: phase3/deferred-oom-and-mappings.json. The soak now snapshots the selected
module so subsequent builds cannot change the artifact during a graceful reload.

## Final native source verification

Frozen commit `e5d94fd8dd959b88474332a05aaa1c9eb61f318f`, PHP 8.5.12 NTS,
passed A/B/C/D, 25 recycling requests, and a separate 300.106-second mixed run
with 3,756 main requests. Graceful reload, abnormal worker replacement, fatal
requests, pending shutdown and timeout recovery passed. Maximum growth beyond
warmup was 2,888 KiB; idle workers returned to six descriptors and one thread.
This is the third five-minute pass after the mapping repair; the intervening
frozen `df7d061` run handled 3,716 requests in 300.492 seconds with a 2,904 KiB
maximum. All use the unchanged 8,192 KiB investigation limit.

The installed r5 archive separately passed A/B/C/D. Hosted database-lifecycle CI
also passed at e5d94fd. Full provenance is in
[final-native-validation.json](phase3/final-native-validation.json) and
[package-final.json](phase3/package-final.json). No final-source one-hour,
six-hour or 24-hour FPM run has completed; these short tests establish the
reproduced repair and lifecycle behavior, not long-term deployment capacity.

## Phase 4 collector bounds

The FPM soak previously retained every response and sample in Python lists. This
would make the observer itself consume increasing memory over six-/24-hour runs.
Responses and samples now go to append-only `mixed-responses.jsonl` and
`mixed-samples.jsonl`. The report links those files and includes `sampleCount`;
it no longer duplicates all samples in JSON summaries. All observations remain
available. Aggregation is constant space per warmed worker and diagnostic log
tails use a bounded deque. Evidence disk usage still grows with test duration.

The 8,192 KiB strict growth bound, original warmup peaks, first-three-sample
range exclusion, idle six-fd/one-thread requirement and unexpected-worker check
remain unchanged. Random-trace regression comparisons match the old formulas.
A real 60.161-second FPM smoke passed 780 main requests plus A/B/C/D and lifecycle
faults, with 772 steady samples and maximum 2,788 KiB beyond warmup. This tests
the collector; the separate baseline one-hour campaign uses frozen old harness
source and must complete before receiving a passing status.
