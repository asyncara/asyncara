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
source and was interrupted after 1,166.910 seconds. It is not passed.


Phase 4 adds `afterState` PHP memory/peak, scheduler and pending-Future observations
before deliberately submitting teardown work. A separate OS process observer
records virtual memory, sockets and child identities. Idle assertions still require
six descriptors and one thread, and original cross-request isolation checks remain.
A 60.243-second monitor smoke passed 660 requests; final observer rerun/endurance
are tracked in PHASE4_DELIVERY.md. External process permission gaps are recorded
explicitly and never weaken the strict idle-worker checks.

## Phase 4 responsiveness failure

Frozen `2e2f84f` failed its requested one-hour test after the last 651.064-second
steady sample. Four HTTP responses overlapped, but the independent ticker
advanced once; the unchanged requirement is at least twice. All 6,208 recorded
main responses, failure logs and prior successful lifecycle cases remain intact.
This is a failed responsiveness test, not an RSS or request-isolation pass for
the full requested duration. The stage gate did not start six-/24-hour work.

Test requests now record CPU time, Linux scheduled/runnable-wait time and bounded
last-tick timestamps for diagnosis. A separate 600.145-second run passed 7,520
main requests and every lifecycle case. This shorter pass does not supersede
the failure. A syscall diagnostic passed 693 requests / 300.074 seconds but
recorded a five-millisecond `epoll_wait` lasting 341.487 ms. A standalone C control
also reproduces oversleep without PHP/Asyncara. Host-level effects are present;
the original failed request's exact cause remains unproven.

```sh
python3 tests/soak/fpm_trace.py --seconds 300 --output build/new-fpm-trace
```

The diagnostic starts only its own FPM process under `strace -D`. It records
readiness, synchronization and memory-management syscalls, not payloads or
credentials. It changes timing and is not endurance certification. No global
ptrace, scheduler or PHP settings are modified. Use a new directory every time.
See [PHASE4_DELIVERY](PHASE4_DELIVERY.md) for current gate status and evidence.

The diagnostic now also accepts `--workers 4 --instrumentation poll`. This uses
a test-only namespaced forwarding function to time the unchanged global native
poll call, without ptrace. It records calls over 50 ms to `native-polls.jsonl`
and uncaught fatal errors to `fatal-requests.jsonl`, retaining request/PID identity.
Logs are restricted to the private output directory. No payload syscalls or
application traffic are recorded. This adds CPU queries and synchronous log
writes: do not use it for performance certification or load it in applications.
Fatal memory exhaustion may prevent diagnostic logging and is not claimed safe.

```sh
python3 tests/soak/fpm_trace.py --seconds 600 --workers 4 --instrumentation poll --output build/new-fpm-poll-profile
python3 tests/soak/test_poll_profile.py
```

The original external profile completed 600.256 seconds / 5,312 requests. An
overlapping 120.001-second C control had 194 waits over 50 ms, maximum 623.114 ms
for a requested 5 ms wait. See [profile evidence](phase4/fpm-poll-profile.json).
The later repository diagnostic has separate smoke validation; it is not
retroactively identified as the source of that earlier run. Host delays are
demonstrated, while exact attribution of the earlier failures remains unresolved.
Original responsiveness assertions and growth thresholds are unchanged.

The UDP ownership repair adds a socket retained through each audit request,
including memory exhaustion, timeout and pending-work cases. With the corrected
native helper, A/B/C/D and a60.491s expanded lifecycle run passed300mainrequests,
recycling/reload/kill/fatal/timeout cleanup and strict idle6fd/1thread assertions.
This smoke preceded the separate first-autoload EMFILE adjustment; the latter has
isolated descriptor-limit tests and the next frozen/package validation. It does
not replace failed historical endurance or certify a full hour of the new source.

## Independent hosted one-hour result

Historical source 624cbe9 (runtime e5d94fd, before UDP repairs) passed the complete
hosted lifecycle harness: 3,600.036s / 67,520 main requests plus all A/B/C/D,
recycling, reload, forced replacement, fatal and timeout cases. Maximum RSS
growth beyond warmup was 3,116KiB; steady slopes 44.954–79.177KiB/hour, per-worker
ranges 364–536KiB. Strict idle six-fd/one-thread assertions passed. Raw results
and the official artifact digest are in phase4/hosted-fpm-1h.json. This is an
actual one-hour FPM pass, not a corrected-source or combined-workflow pass: the
parallel mixed workload failed. The later local 76e6557 FPM attempt was deliberately
interrupted after the997.320s sample to repair destination validation. These
records predate the completed corrected-source results below.

Corrected f2abb55 r4 passes the ordinary A/B/C/D audit. Its new one-hour expanded
FPM attempt began November3 at16:44:17Z after package tests finished. It later
failed responsiveness as recorded below. The supervisor advances FPM only after
a complete FPM pass. Historical passes and timing failures remain separate.

## Corrected-source hosted hour and isolated local failure, November 5

Verified r4/f2abb55 hosted FPM completed3,600.055s/67,824mainrequests and every
A/B/C/D, recycling/reload/kill/fatal/pending/timeout case. Maximum growth beyond
warmup3,120KiB; steady worker slopes50.673–82.479KiB/hour, idle6fds/1thread.
The official artifact digest and exact byte-identical r4 archive are verified in
phase4/hosted-r4.json. Hosted mixed passed independently on the same source.

The simultaneous local FPM attempt still failed: last sample364.506s,3,104main
responses; a request had overlapping HTTP intervals but zero ticker progress,
352.592ms elapsed/14.489ms scheduled CPU and333.027ms timer lag. Its precise wait
site was not traced; earlier host controls cannot retroactively establish it.
Full failure/log hashes remain in phase4/endurance-r4.json. No threshold changed.

A new FPM-only1h→6h→24h chain began November5 at21:37:31Z using the same frozen
source and binary, without concurrent local mixed/build/fuzz/benchmark load.
It failed after998.680 sampled seconds and12,568 recorded main responses. The
failed request's four HTTP intervals overlapped, but ticks=0, elapsed273.372ms,
scheduled CPU2.023ms, runnable wait0.195ms and maximum timer lag267.219ms.
The hosted pass does not bypass this local gate; six-/24-hour FPM did not start.
[Failure attestation](phase4/fpm-solo-r4-result.json) retains source/binary/archive
identity, complete raw hashes, failure metrics and observer samples.

The external100ms observer had a361.764ms sampling gap across the failed request
and then falsely rejected the exiting runner's empty argv. Linux clears zombie
command lines before reaping. The new shared ownership helper checks process
state and PID/start identity before and after argv, reports zombie/reaped roots
as exited, and continues to reject live mismatches. Real-child and exit-race
regressions cover the repair. The original observer/report remains preserved.
Its root `cpu.stat` was readable; only root `cpu.max` was absent, correcting the
original launch note. The reusable observer now follows cgroup membership through
all mounted ancestors. This is diagnosis, not enforcement or attribution of
shared CPU pressure. See SOAK_TESTING.md for the exact CLI and boundaries.

## Correlated tracing and shorter diagnostic results

The existing `fpm_trace.py` can now use `--instrumentation both` to pair its
test-only PHP native-poll wrapper with strace. It traces readiness, waiting and
allocation-control syscalls only, without request-body payloads. The wrapper
logs slow native-call durations/request IDs; it does not replace the extension
or bypass the original responsiveness assertion. Failure reports now retain
recorded-response counts and elapsed time through cleanup. An intentional
`FPM_BIN=/bin/false` startup control verifies a failed report with zero responses.

```sh
FPM_BIN=/path/to/php-fpm8.5 python3 tests/soak/fpm_trace.py --seconds 30 --workers 4 --instrumentation both --output build/new-fpm-both-diagnostic
FPM_BIN=/path/to/php-fpm8.5 python3 tests/soak/fpm_trace.py --seconds 180 --workers 4 --instrumentation poll --output build/new-fpm-poll-diagnostic
```

Results on unchanged native/public runtime source:

| Run | Observed result | Scope |
|---|---|---|
| Both layers, four workers, requested30s | FAIL, request82/PID67187, ticks1 |4ms/5ms native calls95.514/157.237ms; captured matching-timeout epoll waits0.102/0.313ms |
| Both layers, one worker, requested5s | PASS5.476s/10requests | Instrumentation forwarding/shutdown smoke |
| Poll wrapper only, four workers | PASS180.248s/2,500requests |134native calls above50ms; largest164.260ms |
| Full lifecycle smoke with repaired external observer | PASS60.088s/864mainrequests plus A/B/C/D, recycle, reload, kill, fatal/pending and timeout | Observer COMPLETE70.132s/620samples; idle six fds/one thread, maximum2,800KiB growth beyond warmup |

Ptrace and synchronous diagnostic logging change timing. A native-call duration
includes allocation/completion work as well as readiness waiting. The wrapper's
wall timestamp is taken after measuring elapsed time; it is not an exact
monotonic boundary pair. No exact cause for the earlier uninstrumented failure
is inferred. Independent180s epoll/poll/nanosleep controls also observed long
waits without PHP/Asyncara; those support host timing variability, not a claim
that every runtime delay is external. Short smoke RSS slopes are retained and
must not be extrapolated to six hours. All original assertions remain intact.

[Diagnostic attestation](phase4/scheduling-diagnostics-r2.json) contains results,
selected correlated trace lines, tool hashes,19passing regression tests, raw-file
hashes and build commands. No new native/public runtime fix is claimed. The
corrected r4 archive remains immutable; these later diagnostic tools are in Git.

## Direct readiness-wait measurement, November 6

The `67e4530` hosted jobs passed independently: mixed 3,600.129s / 27,694 cycles;
FPM 3,600.051s / 67,740 main requests plus all lifecycle cases. The official
artifact digests, all 366 archived files, mixed source manifest, response counts,
idle resources and recalculated trends were verified. The hosted archive has
SHA-256 `522d75cb34bd08ef9e2e6fcd1befc6ca138bfa47244f25c2a4101213ab636984`.
It contains later diagnostic/documentation files; its native/public runtime is
identical to r4. It is not a replacement published distribution. See
[hosted evidence](phase4/hosted-67e4530.json). Local failures remain preserved.

`fpm_trace.py --instrumentation wait` builds and preloads a **test-only** Linux
libc interposer into its owned FPM process. It measures `poll` and `epoll_wait`
with `CLOCK_MONOTONIC` and per-thread CPU clocks. The normal PHP poll wrapper now
records the same monotonic start/end boundaries. No Zend APIs, engine function
pointers or runtime implementation are modified. The module uses supported
`LD_PRELOAD`/`dlsym` interposition and has no persistent descriptors. Only waits
longer than 50ms are logged, after their measured interval; no application payload
or socket contents are captured. Existing test assertions remain unchanged.

```sh
FPM_BIN=/path/to/php-fpm8.5 python3 tests/soak/fpm_trace.py --seconds 180 --workers 4 --instrumentation wait --output build/new-fpm-waits
python3 tests/soak/analyze_waits.py build/new-fpm-waits --output build/new-fpm-waits/analysis.json
python3 tests/soak/test_wait_probe.py
python3 tests/soak/test_wait_analysis.py
```

The probe is not a production extension, transparent hook, or kernel off-CPU
tracer. Clock reads and post-measurement logging add overhead; preemption near
libc entry/exit is included. A long interval cannot by itself distinguish kernel
work, scheduler delay, or a dependency implementation. Successful calls may
retain a previous `errno`; only negative results make it an error indication.
The probe forwards return values, event data, `errno` and signal interruption.
Diagnostic logging/clock/initialization failure exits the owned process with125
and an explicit error, rather than silently producing unmeasured results. A
pre-existing `LD_PRELOAD` is rejected by the runner. Never enable this tool in an
application or a system PHP/FPM configuration.
Waits that never return, including some killed-process paths, cannot produce a
completion record. Waits below the50ms reporting threshold are not recorded.

The analyzer uses a temporary SQLite index and attributes only main-thread waits
wholly contained in a matching process's native-call interval. It detects duplicate
or overlapping waits and changed input logs. It preserves all raw input, retaining
only the largest20 waits in its summary. Hard failure limits are256MiB/file,
one million records/file,64KiB/line,4,096 native process IDs,4,096 fatal records
and1,024 fatal-call summaries. Its2MiB SQLite page cache is not a total process
memory limit. The index is explicitly closed even when garbage collection is
disabled; analyze completed diagnostic logs, not a live stream.

The first direct-probe run passed180.039s /2,348 requests. There were235 slow
native calls;209 contained a recorded long readiness wait. The largest matched
5ms wait took317.356ms with311.782ms thread CPU. Another took294.997ms with0.054ms
thread CPU. Both low-CPU and CPU-heavy delays also occur in the independent C
controls; neither class is omitted. These observations narrow the measured delay
to the wait interval, but do not establish the exact site of the earlier untraced
failed request or certify a latency guarantee.

Real native controls cover return values/readiness, EBADF, EINTR, concurrent
processes and threads, complete log records, and descriptor cleanup. GCC
ASan/UBSan, TSan (child-only non-PIE/no-ASLR configuration) and static analysis
pass for this diagnostic module/control. The PHP wrapper forwarding test passes
8.3.36,8.4.27 and8.5.12. These are scoped tool checks, not new sanitizer or ZTS
certification of the production runtime.

The longer diagnostic passed900.052s /12,152 requests. Of992 slow native calls,
864 contained a recorded long wait. The largest matched5ms wait took659.389ms
with0.028ms thread CPU. A separate full lifecycle smoke on frozen f2abb55 and its
original binary passed60.170s /836mainrequests plus all lifecycle/fault cases,
with six idle fds/one thread and at most2,692KiB growth beyond warmup. No probe
initialization, clock or logging error was found. Its107 fatal records are
deliberate fault cases; fatal PHP memory exhaustion does not become recoverable.
These checks are in [probe evidence](phase4/wait-probe.json). They do not satisfy
the one-hour or longer uninstrumented FPM endurance gates.

A one-hour instrumented full lifecycle diagnostic started November6 at17:49:09Z,
using the original frozen f2abb55 runtime, original native binary and checkpointed
tools7e104c0. It retains all original assertions and records call/wait intervals
through recycling, reload, fatal requests and shutdown. A detached supervisor
checks immutable source/binaries, completed duration, probe-error logs and offline
analysis before reporting a result. [Launch provenance](phase4/fpm-wait-hour-launch.json)
includes its exact code and environment. Live state is
`/tmp/asyncara-phase4-nrtjh3t1/fpm-full-wait-probe-r2/state.json`.
It is currently RUNNING, with no completed-hour claim; the six-/24-hour
uninstrumented stages remain unstarted.

While that frozen campaign runs, independent negative controls exposed a
diagnostic error path: the PHP profiler ignored a failed `file_put_contents()`.
A directory at the log pathname caused a warning but the measured call still
returned successfully. A second control showed the offline analyzer accepted
explicit instrumentation failures in FPM error logs. These are measurement-tool
defects, not newly demonstrated runtime defects.

The profiler now checks the complete write count and throws an explicit
`Asyncara poll profile failed:` error on failure. It preserves PHP's warning;
instrumentation failure may replace the application's return/exception and is
always a failed diagnostic. The analyzer and trace runner scan known FPM error
logs for both PHP-profiler and native-probe failure markers, including failures
during intentional OOM requests. Scanning is bounded and recorded by hash.
Seven analyzer tests and both profiler tests on PHP8.3.36/8.4.27/8.5.12 pass;
the new regressions fail against the preceding tools. CI already runs these
test files. [Evidence](phase4/profile-log-errors.json) retains the negative
controls and exact source hashes.

Reanalysis of the completed180s,900s and60s full-lifecycle diagnostics preserves
all original wait matches and finds no profiler-write warnings. The active
one-hour run still uses its unchanged7e104c0 private tools. Once it finishes,
review the original warning paths and run the stricter analyzer into a new
output file. No FPM campaign with the newly changed PHP profiler is claimed yet.
