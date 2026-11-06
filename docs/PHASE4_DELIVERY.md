# Phase 4 engineering evidence

**EXPERIMENTAL — work in progress.** This report separates completed checks
from failed campaigns and shorter diagnostic runs. Mixed endurance is verified
for the configuration below; broader FPM and compatibility gates remain incomplete.

## Native API lifecycle continuation

Starting source: rewritten `phase4-endurance`
`8b6537409c08bcdf7fe7b0e47899fca0ef0f5b54`. Official direction and implemented
semantics are in [NATIVE_API_FIRST](NATIVE_API_FIRST.md) and
[IO_LIFECYCLE_CONTRACT](IO_LIFECYCLE_CONTRACT.md). This checkpoint changes two
PHP runtime classes, tests and documentation; native C, timer hooks, scheduler,
existing FPM acceptance fixture and thresholds are unchanged.

| Check | Actual result / scope |
|---|---|
| Ownership regressions before fix | SQLite copy guard and buffered HTTP completion-drain assertions FAIL; logs preserved |
| Ownership fixes | SQLite rejects cloned/serialized native IDs; buffered HTTP retains Task/permit through cancellation collection |
| PHP8.5.12 epoll/poll | Full scripts/test.sh PASS on both:44 core cases plus all auxiliary suites including6 new lifecycle cases |
| PHP8.3.36/8.4.27 and8.5.12 DEBUG | Fresh modules and6 lifecycle regressions PASS on both epoll/poll |
| Independent audit | All scheduler/pool/transaction regressions,1k/10k Tasks, slow-I/O and fatal-memory controls PASS |
| FPM functional | Original A/B/C/D PASS, PHP8.5.12 |
| Database suite/bounds | PostgreSQL/MariaDB/Redis PASS; Oracle fixture startup was not an Oracle query test; no new remote TLS claim |
| GCC ASan/UBSan | Fresh matching instrumented PHP/module lifecycle tests PASS |
| Valgrind-aware DEBUG PHP | Lifecycle suite PASS;0 errors and0 lost bytes, no suppressions |
| Distribution PHP under Valgrind | Exit99,904 direct/120 indirect lost bytes plus unregistered Fiber-stack warnings; old source also reports losses (2312/360). Plain PHP Fiber control has stack warnings but no losses. Exact difference unresolved |
| Deliberate FPM worker-stop controls | epoll7/8 and poll8/8 injected cases fail original two-tick predicate; ordinary/gated/recovery controls PASS. These are diagnostics, not endurance acceptance |
| Original local FPM failures | Raw logs rechecked; exact zero-tick cause remains unresolved |

[Durable evidence](phase4/lifecycle-hardening.json) records commands, raw log
hashes, binary identities, preserved failures and narrower instrumentation scope.
The initial progress-control assumption and malformed serialization test literal
also have retained failed reports. No warning or existing assertion was suppressed.
Native TSan was not rerun for these PHP-only runtime changes; historical TSan
results keep their original scope. Broader HTTP/API expansion and benchmarks are
deferred behind reliability rather than added speculatively.

The next gate is a frozen, independently packaged uninstrumented FPM1h attempt,
followed by6h and24h only after each preceding stage passes. Before its launch,
the new campaign remains **NOT_STARTED**, not certified by this test matrix.
Package/launch evidence will be recorded separately so implemented, tested,
packaged and endurance-tested source remain distinguishable.

## Historical evidence at 2026-11-06

See the [Native API lifecycle continuation](#native-api-lifecycle-continuation)
for the subsequent checkpoint. The following earlier source hashes, archives,
completed campaigns and failures remain historical evidence.

| Check | Tested source | Result |
|---|---|---|
| Reproducible r4 installed-package tests, PHP 8.3/8.4/8.5 NTS | `f2abb55` | PASS; 355 source files match Git |
| Hosted regression and database lifecycle | `f2abb55` | All four jobs PASS; [run 41675874564](https://github.com/asyncara/asyncara/actions/runs/41675874564) |
| Local mixed one hour | frozen `f2abb55` | PASS: 3,600.231s / 8,488 cycles |
| Local mixed six hours | same source/binary | PASS: 21,600.161s / 69,836 cycles |
| Local mixed 24 hours | same source/binary | PASS: 86,400.268s / 294,899 cycles |
| Local FPM one hour, concurrent with mixed | frozen `f2abb55` | FAIL: responsiveness assertion; last sample 364.506s / 3,104 recorded main requests |
| Hosted mixed one hour | `f2abb55` | PASS: 3,600.016s / 28,358 cycles |
| Hosted FPM one hour | `f2abb55` | PASS: 3,600.055s / 67,824 main requests |
| Local FPM-only retry | unchanged frozen `f2abb55` | FAIL: responsiveness; last sample998.680s /12,568 recorded main requests |
| Latest hosted verification | `67e4530`; runtime unchanged from r4 | Four PHP/database jobs PASS; mixed hour27,694cycles; FPM hour67,740mainrequests |
| Direct wait-probe diagnostics | runtime unchanged from r4; instrumented test tools | PASS180.039s/2,348requests and900.052s/12,152requests; full lifecycle smoke60.170s/836mainrequests |
| Instrumented full FPM hour | frozen `f2abb55` runtime/binary; tools `7e104c0` | RUNNING from November6,17:49:09Z; no completion claimed |
| Six-/24-hour FPM | — | NOT EXECUTED; local preceding stage must pass |

The local mixed scope includes separate Oracle and MariaDB fixtures. Hosted mixed
omits Oracle. Hosted results are [run 41675874490](https://github.com/asyncara/asyncara/actions/runs/41675874490),
with ZIPs verified against official GitHub digests and embedded source archives
byte-identical to r4. Earlier r3 hosted mixed/FPM jobs also passed one hour, but
remain historical evidence because they lack the destination repair.

The mixed stages do not erase the local FPM failure: the original supervisor
correctly reports overall FAIL. The hosted FPM pass is scoped to that host and
workload. All reports, failures and thresholds are retained. Release remains
**EXPERIMENTAL**.

The latest [hosted review](phase4/hosted-67e4530.json) verifies the official ZIP
digests, all366 source files, the mixed source manifest, FPM response counts,
idle fd/thread checks and independently recalculated resource trends. These runs
are [verification42125157943](https://github.com/asyncara/asyncara/actions/runs/42125157943)
and [endurance42125158090](https://github.com/asyncara/asyncara/actions/runs/42125158090).
The hosted archive SHA-256 is
`522d75cb34bd08ef9e2e6fcd1befc6ca138bfa47244f25c2a4101213ab636984`.
It includes later tools/documentation and differs from the r4 distribution;
native/public runtime bytes are identical. No replacement release was published.

## Independent review of completed endurance

[Local evidence](phase4/endurance-r4.json) preserves complete reports, source and
binary fingerprints, raw-file hashes, last samples and the local FPM failure.
[Hosted evidence](phase4/hosted-r4.json) includes official artifact metadata,
verified ZIP hashes, exact source identity, reports and host/dependency details.
Every local stage matches the frozen 355-file manifest and native binary. Raw
samples have increasing timestamps/cycle counts, zero unexpected errors, empty
stderr and a single clean completion record. Independent centered least-squares,
min/max and endpoint-median calculations reproduce the reported resource trends.

| Local mixed stage | Steady RSS endpoint change | Steady RSS slope | Maximum timer lag |
|---|---:|---:|---:|
| 1 hour | +1,772 KiB | +2,889.137 KiB/hour | 0.987s |
| 6 hours | +332 KiB | +71.277 KiB/hour | 1.474s |
| 24 hours | +260 KiB | +14.414 KiB/hour | 1.264s |

Steady calculations discard the first fifth of samples under the unchanged
harness policy. The full 24-hour sampled RSS range is 41,212–47,908KiB; its steady
range is 47,524–47,784KiB. Both ranges are preserved. Sampled native bytes are zero
at completed-cycle boundaries; steady fds 23–24 and threads five. All final
Tasks/Futures/timers/watchers/native operations are zero. Sampling omits temporary
within-cycle peaks and cannot establish absence of all leaks. The measured timer
lags prevent any hard latency guarantee or broad performance claim.

The last 24-hour sample records 294,891,000 burst Tasks, 294,891 expected
cancellations and timeouts each, 2,948 ProcessPool crash recoveries, 294,891 DNS
lookups, 589,782 UDP datagrams and verified TLS connections each. The final
completion has eight more cycles; last-sample counters are not silently
extrapolated. WebSockets reconnect periodically: this is extended ws/wss workload
coverage, not a single uninterrupted 24-hour connection test. SQL uses isolated
local socket fixtures; WAN/database TLS/mTLS coverage is unchanged.

Hosted r4 FPM passes A/B/C/D, recycling, reload, forced replacement, fatal/pending
cleanup and request timeout after its complete steady hour. Maximum RSS growth
beyond warmup is 3,120KiB; per-worker steady slopes 50.673–82.479KiB/hour, with
strict idle six-fd/one-thread checks. Its parallel hosted mixed stage also passes.

The failed local FPM request has four overlapping HTTP intervals, zero ticker
progress, 352.592ms elapsed, 14.489ms scheduled CPU and 47.566ms runnable wait;
maximum timer lag is 333.027ms. The remaining interval is not uniquely attributed.
Earlier independent native-wait controls demonstrate host timing effects, but
this individual request was not traced. No speculative runtime repair or relaxed
assertion is justified by these observations.

The FPM-only attempt removed the concurrent local mixed workload while retaining
all original four-worker, within-request and lifecycle assertions. It failed
before one hour; six/24-hour stages never started. [Launch provenance](phase4/fpm-solo-r4-launch.json)
records the exact supervisor; terminal state is
`/tmp/asyncara-phase4-nrtjh3t1/campaign-f2abb55-fpm-solo-r1/state.json`.
[Result attestation](phase4/fpm-solo-r4-result.json) preserves the failure,
12,568 main responses, raw hashes and observer window. The request had zero ticks,
273.372ms elapsed, 2.023ms scheduled CPU, 0.195ms runnable wait and267.219ms maximum
timer lag. Its four HTTP intervals overlapped. The external observer missed
361.764ms across this interval; the exact wait site remains unproven.

## Observer repair and targeted FPM diagnostics

The historical [external observer](phase4/fpm-scheduling-observer.json) also
failed on runner exit: Linux clears zombie command lines before reaping. A real
child-process regression reproduces that old ownership mismatch. The repaired
helper recognizes zombie/reaped processes, checks identity before and after argv
reads, and still rejects live ownership/PID mismatches. The reusable observer
follows actual cgroup v2 membership and all mounted ancestors. Root `cpu.stat`
was readable in the old samples; root `cpu.max` was absent. The old launch note
incorrectly described both as unavailable and is preserved with this correction.
No signals, application instrumentation or host settings are changed by this
read-only observer. Sampling gaps and shared counters limit attribution.

Independent three-minute C controls requested5ms waits without PHP or Asyncara:

| Control | Completed waits | Waits above50ms | Maximum measured wait |
|---|---:|---:|---:|
| epoll | 26,016 | 106 | 610.321ms |
| poll | 26,013 | 104 | 498.451ms |
| nanosleep | 25,969 | 113 | 508.557ms |

They ran concurrently and show host timing variability. They do not identify the
sole cause of the earlier FPM failure. No latency threshold was relaxed.

The FPM diagnostic now supports `--instrumentation both`, correlating the existing
test-only poll wrapper with strace. A four-worker30s attempt failed responsiveness
on request82/PID67187. Its4ms and5ms native calls measured95.514ms and157.237ms;
the corresponding captured epoll waits measured0.102ms and0.313ms. The native-call
duration includes more than the readiness syscall. Ptrace/logging alters timing;
this instrumented failure cannot retrospectively attribute the untraced one.
The subsequent single-worker wiring smoke passed5.476s/10requests. Failure reports
now retain response counts and elapsed time through cleanup. Full results, source
hashes and later poll-only diagnostics are in
[scheduling evidence](phase4/scheduling-diagnostics-r2.json) and
[FPM lifecycle documentation](FPM_LIFECYCLE.md).

Poll-wrapper-only four-worker testing passed180.248s/2,500requests, retaining134
native calls above50ms (maximum164.260ms). Full FPM lifecycle with the repaired
external observer passed60.088s/864mainrequests plus all fault cases; the observer
completed70.132s/620samples. These are diagnostic/smoke results, not substitutes
for a full one-hour or longer FPM gate.

All19 monitor, scheduling-observer, metric, HTTP-peer, poll-wrapper and wait-control
regressions pass locally. New observer/control tests are included in fast CI.
These changes affect test tools only. Native C stays76e6557 and public runtime
staysf2abb55; the preserved r4 archive does not contain these later tools.

## Direct wait probe checkpoint

The new test-only Linux libc probe measures `poll`/`epoll_wait` boundaries with
monotonic and per-thread CPU clocks. It forwards readiness, errors and signal
interruption without Zend access or persistent descriptors. PHP poll-profile
records now carry matching monotonic intervals. A bounded offline analyzer joins
only same-process, main-thread waits contained within a native call; raw logs are
preserved. This is instrumentation, not a production hook or a runtime rewrite.

The three-minute run recorded235 slow native calls,209 containing a long wait.
The15-minute run recorded992 slow native calls,864 containing a long wait. Its
largest matched5ms wait took659.389ms with0.028ms thread CPU. CPU-heavy waits also
occur: the earlier run recorded317.356ms with311.782ms thread CPU. Both patterns
occur in the independent C controls without PHP. These measurements narrow the
observed delay to the libc wait interval, including nearby descheduling; they do
not establish a kernel defect or retrospectively identify the earlier failure.

Full lifecycle instrumentation was checked against the original frozen f2abb55
source and exact native binary:60.170s/836mainrequests, all A/B/C/D, recycling,
reload, forced replacement, fatal/pending and timeout cases pass. Idle workers
return to six descriptors/one thread; maximum growth beyond warmup is2,692KiB.
Expected fatal requests remain fatal; this is not PHP memory-exhaustion recovery.

Probe controls pass normal forwarding, EBADF/EINTR, concurrent processes/threads,
record integrity and descriptor checks. GCC ASan/UBSan, TSan and static analysis
pass for the probe/control. Three native-probe and five analyzer regression cases
pass; the PHP wrapper regression passes8.3.36/8.4.27/8.5.12. Actual FPM probe tests
use8.5.12 NTS/epoll. These do not constitute a new full-runtime sanitizer campaign.
[Probe evidence](phase4/wait-probe.json) preserves82raw-file fingerprints, build
commands, exact tool hashes, results and boundaries; [FPM_LIFECYCLE.md](FPM_LIFECYCLE.md)
documents reproduction. Native/public runtime and historical artifacts remain
unchanged. The interrupted session had not launched the intended instrumented
hour. After checkpointing the tools at `7e104c042a31f628aa47c406f6d8af380b40d17c`,
a detached supervisor launched that diagnostic at November6,17:49:09Z.
[Launch attestation](phase4/fpm-wait-hour-launch.json) preserves its exact source,
wrapper, source manifest, binaries, environment and initial process identity.
Live state: `/tmp/asyncara-phase4-nrtjh3t1/fpm-full-wait-probe-r2/state.json`.
This single instrumented hour does not automatically advance to uninstrumented
six-/24-hour FPM stages. The supervisor checks the original duration/lifecycle
assertions, source and binary fingerprints, probe errors and completed-log analysis.

An independent follow-up reproduced two diagnostic acceptance defects: ignored
PHP profile write failures and an analyzer that did not inspect FPM error logs
for failed instrumentation. Both are repaired with negative controls and PHP
8.3/8.4/8.5 plus seven analyzer tests. Existing180s/900s/60s reports retain exactly
the same correlation results under the stricter checks. The active hour retains
its frozen private tools; a new full FPM run of the changed profiler is not yet
claimed. [Logging-error evidence](phase4/profile-log-errors.json) records the
scope and hashes. Native/public source and the r4 distribution remain unchanged.

The latest push is verified by the Git remote. Subsequent unauthenticated GitHub
API reads returned404 on November6; new hosted job status is not verified. This
does not revise the already downloaded and checked67e4530 hosted results.

A detached post-run checker started at18:05:21Z, in
`/tmp/asyncara-phase4-nrtjh3t1/fpm-full-wait-probe-r2-review`. It waits for the
original terminal state without altering that run. It then verifies the frozen
artifacts and52 library hashes, rejects original profiler-write warnings, and
runs the hardened analyzer to a new result. Only a passing instrumented hour
allows the separate60s full lifecycle regression at `fpm-full-wait-probe-r3`,
using checkpointa3d6f7d instrumentation and the original frozen runtime/binary.
[Follow-up launch provenance](phase4/profile-log-review-launch.json) retains the
exact checker and supervisor sources. The checker is WAITING_FOR_HOUR; the new
smoke has not started. This does not schedule or certify longer endurance.

## Corrected r4 distribution and current freeze

Implemented, independently tested, packaged and pushed source:
`f2abb555caf8914821f648f8213ce7a433693685` on `phase4-endurance`.
Native C remains `76e6557`; r4 also contains strict PHP UDP destination validation.
Archive: `build/asyncara-0.1.0-dev-phase4-r4.tar.gz`, 355 source files.
SHA-256: `5f479eb313a4b9a82aa8d8425d59724bbe9359c39181ef4d81d033cadc8a8cb8`.
All Git paths/bytes match; private-umask clean packaging reproduces identical
bytes. Fresh extracted phpize/build/install/load, Composer, complete epoll/poll,
WebSocket and network tests pass PHP 8.3.36, 8.4.27 and 8.5.12 NTS, including both
UDP regressions and child-only EMFILE. Native compiler diagnostics are clean;
PHP's generated configure.ac retains the upstream obsolete AC_PROG_LIBTOOL
notice, without suppression. [Build attestation](phase4/package-r4.json) records
commands, dependency package hashes, binary/log hashes and all four hosted CI passes.

All earlier archives are preserved with their original checksums. r3 and earlier
contain the destination-validation defects; r2 and earlier also contain the
ownership defect. No GitHub release is published. Later evidence/documentation
and test-tooling commits do not retroactively become part of the archive or tested freeze.

The independent build at `/tmp/asyncara-phase4-nrtjh3t1/frozen-f2abb55` completed
before launching supervisor 3124165 on November 3 at 16:44:17Z. That campaign
finished on November 4 at 23:46:50Z: all mixed stages passed; its FPM stage failed.
Native/public runtime source remained frozen through the isolated FPM failure. [Launch attestation](phase4/campaign-f2abb55-launch.json)
records the exact supervisor, environment, source and binary/archive fingerprints.
Completed campaign state: `/tmp/asyncara-phase4-nrtjh3t1/campaign-f2abb55-r1/state.json`.
Independent mixed/FPM chains request 3,600 → 21,600 → 86,400 seconds; only a full
clean pass with unchanged fingerprints advances that workload. All original
assertions/resource thresholds remain. Overall success needs all six passes;
one failed chain cannot be certified by success in the other. The completed
mixed results and remaining FPM gates are recorded above.

## Historical r3 distribution and frozen source

The ownership repair was implemented, packaged and pushed at:
`76e6557bf3925e58e6db3dee07b30aac33a9f9c1` on `phase4-endurance`.
Archive: `build/asyncara-0.1.0-dev-phase4-r3.tar.gz`, 349 source files.
SHA-256: `b307ea18d785a74236b236974207878e19fca0c484c62472093eee452408c79a`.
Every path/byte matches Git; private-umask clean packaging produces identical
bytes. Independent phpize/build/install/load, Composer, epoll/poll functional,
WebSocket and network tests pass PHP 8.3.36, 8.4.27 and 8.5.12 NTS. The UDP
ownership and isolated descriptor-exhaustion regressions run in that suite.
[Build attestation](phase4/package-r3.json) records individual results and hashes.
Historical archives remain intact; r2 and earlier contain the UDP ownership bug.
The r3 archive still contains the later-reproduced destination validation defects.
It is not the corrected destination-source distribution.
No GitHub release has been published. Later evidence-only commits do not become
part of this archive retroactively.

The interrupted campaign used `/tmp/asyncara-phase4-nrtjh3t1/frozen-76e6557` and
retains reports at `/tmp/asyncara-phase4-nrtjh3t1/campaign-76e6557-r1/state.json`.
[Launch provenance](phase4/campaign-76e6557-launch.json) includes the exact
supervisor source and hashes. Mixed and FPM now have independent 1h → 6h → 24h
chains, retaining all original workload assertions/duration/resource limits.
A failed workload cannot advance; an independently passing workload may continue
collecting longer evidence. This replaces the previous cross-workload stage gate,
not any test threshold. Overall campaign success still requires all six passes.
An active campaign with a failed child reports `RUNNING_WITH_FAILURE` explicitly.

## Verified baseline

The initial branch and remote were `phase3-hardening` at
`179aa54e473f644f009114ca47e3dd19fd46b8fd`, with a clean working tree. The native/test
commit `e5d94fd8dd959b88474332a05aaa1c9eb61f318f` is an ancestor of archive source
`f3d63ac9abf528af0cd5b24086976912c28e4a75`, which is an ancestor of initial HEAD
`179aa54`. Those two intervening ranges change only documentation/evidence. There is one canonical
`docs/ROADMAP.md`; no `ROUDMAP.md` duplicate was found.

Preserved archive: `build/asyncara-0.1.0-dev-phase3-r5.tar.gz`.
SHA-256: `b0f544b3da61789a9cc649a8bc24869308787c9c63ca37358afd69e39ef1ec38`.
All 304 regular source files match the archive commit byte for byte. New clean
extractions independently built, installed, loaded and passed core/poll,
WebSocket and network tests on PHP 8.3.36, 8.4.27 and 8.5.12 NTS.

Before source edits, `make verify`, `make audit`, `make audit-fpm`,
`./scripts/audit-allocations.sh`, the full poll runner and native mapping checks
passed with isolated PHP 8.5.12. The system PHP and global configuration were
not changed. [Baseline attestation](phase4/baseline.json) retains command results,
log hashes, archive build steps and source ancestry.

## Endurance campaigns

The first baseline one-hour attempts were interrupted when the agent turn ended.
The mixed run retained 1,195.491 seconds and the FPM run 1,166.910 seconds of
observations; neither has a completion report. Both are **INTERRUPTED**, not
passed. Two remaining project-owned fixtures were stopped after verifying their
private data/configuration paths. [Interruption evidence](phase4/interrupted-campaigns.json)
retains the exact partial durations. All original files remain intact.

Raw evidence: `/tmp/asyncara-phase4-nrtjh3t1`, also recorded by `build/phase4-path`.
At the early checkpoint neither one-hour attempt passed, so six-/24-hour stages
were not started. Later corrected-source results are above. Through `624cbe9`, native and public runtime source were
unchanged. The later UDP repair below is the first Phase 4 runtime change. Every retry uses
a new output directory and a detached process.

## Harness hardening and coverage

The FPM observer now streams every response/sample to JSONL and aggregates in
constant space per worker. Mixed analysis uses two streaming passes. The original
warmup, endpoint median and resource thresholds are unchanged; six regression
cases verify their equivalence. Whole-series mean replaces the descriptive median.

The process observer records RSS, virtual memory, threads, descriptors, sockets
and child identities, including private PostgreSQL daemons after reparenting.
Inaccessible descriptor metrics are null with an explicit reason, never zero.
Six process-monitor cases now cover descriptors, ownership/exit checks, permissions
and preservation of evidence, with four additional scheduling-observer cases.
Existing strict FPM idle checks remain.

Optional `--extended-network` adds DNS, IPv4/IPv6 UDP, verified TLS1.2/1.3 with
SNI/ALPN checks, buffered/streamed HTTPS and persistent ws/wss, ping, reconnect and
receive cancellation to each mixed cycle. `--oracle-prefix` starts a private
Oracle MySQL fixture alongside MariaDB. Exact server versions are recorded.
These are workload additions exercising existing implementations, not new runtime
features or remote database TLS certification.

The expanded epoll workload passed 60.260 seconds / 115 cycles. It exercises
SQLite, PostgreSQL16.15, MariaDB11.8.9, Oracle MySQL8.4.13 and Redis7.0.15 separately.
Two preceding smoke runs completed their PHP workloads but failed the new OS
observer on a permission race; both failures remain preserved. Permission handling
has a regression test and missing metrics are now explicit. The corrected poll smoke also passed60.156s/136cycles. FPM passed60.597s/416main
requests plus A/B/C/D and lifecycle faults. A failed-barrier bookkeeping leak in
the test HTTP peer was reproduced, fixed with guaranteed cleanup, and its
regression and original FPM audit passed. Subsequent endurance failures are
recorded below.
[Harness evidence](phase4/harness-validation.json) preserves reports and log hashes.

## Remaining engineering work

Resolve the local FPM responsiveness gate using preserved failures and targeted
diagnostics before another uninstrumented attempt. Mixed1h/6h/24h and hosted1h
FPM have passed; longer FPM stages remain gated. Preserve the frozen runtime
source and original assertions; any justified native fix requires a new freeze.
Standalone controls reproduced the libpq unload allocation on16.15/17.11/18.6
and PHP callback-type findings with and without Asyncara. These remain unresolved
dependency findings; see [DEPENDENCY_FINDINGS](DEPENDENCY_FINDINGS.md).
Subsequent native/API work requires new checkpoints and relevant repeated soaks.

Existing compatibility boundaries remain those in [PHASE3_DELIVERY](PHASE3_DELIVERY.md).
No new HTTP, WebSocket, database, hook, framework, io_uring or ZTS capability is
advertised at this checkpoint. Userland ThreadPool remains **BLOCKED**.


## Frozen distribution and failed endurance stage

Source2e2f84f4c07dde3f1412fd40fcd905916611ea56 is packaged as
`build/asyncara-0.1.0-dev-phase4-r1.tar.gz` (323files), SHA256
`bcaa6b88f0438ac797840b70a16ca4b43b2c92e2e270d24a0fd8b9a7a90ed898`.
Every file matches Git. Independent builds, isolated installs, loading, Composer,
core/poll, WebSocket and network tests pass8.3.36/8.4.27/8.5.12. See
[package-r1.json](phase4/package-r1.json). This is an experimental source
checkpoint, not a GitHub production release.

The detached campaign ran from 2026-11-03T13:50:42Z to 14:24:29Z. Both requested
one-hour jobs failed. [Complete stage evidence](phase4/campaign-failure.json)
retains the source/archive identity, raw artifact hashes, resource trends and
failure details. The stage gate prevented six-/24-hour execution.

- **FPM:** last steady observation 651.064 seconds, 6,208 recorded main responses.
  Four upstream HTTP operations overlapped, but one request's independent ticker
  advanced once instead of the required two times. Original assertions remain.
- **Mixed:** process exit 255 after 2,023.583 wall seconds including fixture setup
  and teardown. Last completed sample: 1,940.227 workload seconds, 5,517 cycles,
  5.517 million burst Tasks and 55 ProcessPool restarts. A timeout exception
  escaped the workload. The added network work is a suspect, but the original
  trace identifies the timer callback, not the waiting operation or deadline.
  Stage diagnostics now preserve more detail on a recurrence.
  Steady RSS endpoint medians increased 380 KiB; tracked native bytes remained
  zero at sampled cycle boundaries, threads stayed at five, fds at 23–24.
  These observations do not establish successful shutdown or endurance.

A separate diagnostic FPM run passed 600.145 seconds and 7,520 main requests plus
all lifecycle cases. A traced single-worker run passed 300.074 seconds / 693
requests, while recording a 5 ms `epoll_wait` lasting 341.487 ms. Tracing changes
timing. An independent C control without PHP/Asyncara also reproduces substantial
oversleep. This establishes host-level timing effects, but neither proves the
cause of the original failed request nor explains the mixed timeout. No runtime
fix, threshold relaxation or endurance pass is inferred.

The standalone control completed 300.004 seconds / 42,164 waits: 197 exceeded
50 ms, worst 489.310 ms. The mixed diagnostic passed 600.175 workload seconds /
2,179 cycles; final scheduled work and tracked native bytes were zero. RSS
endpoint medians increased 2,472 KiB in this shorter window; its extrapolated
23,740 KiB/hour slope is retained and is not a measured long-term growth rate.
The deliberate TLS-stall diagnostic/cleanup test passes all three PHP branches
with epoll and poll and is added to CI. Complete diagnostic provenance:
[timing-diagnostics.json](phase4/timing-diagnostics.json).

Raw directories and commands are retained beneath
`/tmp/asyncara-phase4-nrtjh3t1`. The initial source-cleanliness preflight failure
is also preserved. [Launch attestation](phase4/campaign-launch.json) records the
frozen supervisor and artifact identities.

Later connection-limit tests exercise the same unchanged ext/src: SQLite128
admission/recovery, HTTP8-per-host/64-total measured by independent peers. They
pass all three PHP branches with both backends and fully instrumented GCC8.5.12.
The current fixed native connection caps are not newly configurable; direct
SQL/Redis connection counts still require application/deployment containment.

Fresh fuzz runs:427,893native inputs/121s;678,148HTTP-buffer inputs/61s;
10,107instrumented RESP/IPC/header inputs/60.116s;11,631WebSocket inputs/60.187s.
No findings, no suppressions; scope remains bounded. Evidence:
[validation-r1.json](phase4/validation-r1.json). Existing benchmark suite retained
all three samples for14cases in [phase4-r1.json](../benchmarks/results/phase4-r1.json).
Shared soak/fuzz load makes these observations unsuitable for regression attribution
or a stable ranking; median CPU pool time exceeded sequential time.


## Validation and release boundaries

The r1 installed package independently passed the audit regression suite on all
three PHP branches. New connection-limit regressions pass epoll and poll on each
branch, full GCC ASan/UBSan PHP 8.5.12, and Valgrind with zero errors or lost bytes
(3,888 bytes remain reachable). Native TSan passed its existing worker workload.
Clang full-PHP function-type findings remain unresolved and are not reported as a
clean sanitizer configuration.

Fresh private-CA database tests pass PostgreSQL, Redis ACL/TLS and separate
MariaDB / Oracle fixtures, including rejection cases and 100 Redis TLS repeat
cycles in each fixture. Existing versions remain PostgreSQL 16.15, MariaDB
11.8.9, Oracle MySQL 8.4.13 and Redis 7.0.15; SQLite remains 3.45.1. These are local
isolated tests, not WAN, mTLS, certificate-rotation or cancellation-acknowledgement
certification. [Database matrix](DATABASE_COMPATIBILITY.md) records the boundaries.

Filesystem remains pthread-worker backed; io_uring file operations remain
NOT_IMPLEMENTED. Existing bounded HTTP/1.1 streaming, one-file multipart,
ws/wss clients and raw TCP WebSocket server remain available within their tested
limits. Multiple-file MIME, WSS server, browser/Autobahn certification, new hooks
and framework adapters have not been implemented in this phase. Only sleep/usleep
hooks are PARTIALLY_SUPPORTED; other transparent groups are UNSUPPORTED. ZTS
concurrent interpreter execution is unverified and userland ThreadPool is BLOCKED.

**Assessment at the r1 checkpoint: EXPERIMENTAL.** Its runtime source was unchanged
from `e5d94fd`; the r1 package and failed long campaign identify full tree `2e2f84f`.
Later test/diagnostic changes have separate manifests and are not retroactively
included in that package or campaign. A source archive is not a published GitHub
release. The next release gate is diagnosis and successful frozen endurance,
followed by the remaining resource, database, HTTP, WebSocket and integration
priorities in the canonical [roadmap](ROADMAP.md).

## Historical r2 source and distribution

Checkpoint `35877408ced8de47a2c270d8f73e3bf076d5dca4` is pushed on
`phase4-endurance`. It adds the tested limit regressions and timing diagnostics;
`ext/` and `src/` remain identical to the preserved final Phase 3 runtime.

Source archive: `build/asyncara-0.1.0-dev-phase4-r2.tar.gz` (336 files).
SHA-256: `3ef8e94b612daf4707bd85b979d63fd4dfae5186824a81b8e0ca61dfe5e4667b`.
Every path and byte matches that commit. A private-umask clean checkout produces
the identical archive. Independent phpize/build/install/load, Composer, core,
poll, WebSocket and network checks pass PHP 8.3.36, 8.4.27 and 8.5.12. Local
`make verify` passes, including database, stress and native sanitizer workloads.
All four [hosted CI jobs](https://github.com/asyncara/asyncara/actions/runs/41657094869)
passed at this source. [Build attestation](phase4/package-r2.json) retains commands,
dependency package hashes and individual outcomes. Historical r1/r5 archives are
unchanged. No GitHub release has been published.

A new detached campaign started at 2026-11-03T14:53:29Z using this clean frozen
source and a separately built native module. No concurrent local builds, fuzzing
or benchmarks are scheduled. Its current state is recorded in
`/tmp/asyncara-phase4-nrtjh3t1/campaign-3587740-r1/state.json`; the committed
[launch record](phase4/campaign-3587740-launch.json) is a snapshot, not live status.
One-hour mixed/FPM jobs must both pass before six-hour jobs start, and both
six-hour jobs must pass before 24-hour jobs start. Launch is not completion.
The prior failed campaign remains evidence; a retry does not erase it.

The FPM retry failed at14:58:45Z, after its last303.482-second steady sample and
2,676 recorded main responses. The failing request had one ticker step,547.966ms
wall duration,2.729ms CPU usage,57.048ms runnable wait and535.189ms timer lag.
No local build/fuzz/benchmark was running. [Retry failure](phase4/fpm-retry-failure.json)
preserves the new diagnostics and raw hashes. The mixed retry was subsequently
interrupted deliberately after reproducing the UDP defect: last completed sample
2,761.879 seconds / 6,524 cycles. Its full report is
[preserved](phase4/campaign-3587740-interrupted.json); it is not a one-hour pass.
The FPM failure already prevented progression to six-/24-hour stages.
Separate traced diagnostics investigate the wait; they do not replace this result.

## Poll timing investigation

The four-worker test-only PHP poll profile completed 600.256 seconds and 5,312
HTTP/stream/SQLite/files requests. It preserved the request assertions and logged
2,530 native calls with a requested timeout at most 5 ms that took over 50 ms.
The longest 5 ms call took 934.382 ms with 124 microseconds of CPU time. A
simultaneous standalone C control, without PHP or Asyncara, completed 120.001
seconds / 13,271 epoll waits; 194 exceeded 50 ms, with a maximum 623.114 ms.
Overlapping delays strengthen the evidence of host timing effects. They do not
resolve the exact cause of earlier untraced failures or the mixed timeout.

[Profile evidence](phase4/fpm-poll-profile.json) retains timings, overlap samples,
commands and hashes. [Syscall investigation](phase4/fpm-syscall-investigation.json)
preserves the failed traced runs, including a failed worker's requested 5 ms
epoll wait lasting 202.361 ms. No timeout, ticker assertion or resource threshold
was relaxed. Instrumentation changes timing; the profile pass is neither a full
FPM lifecycle pass nor one-hour endurance evidence.

## UDP endpoint ownership defect

Hosted mixed testing failed after 445.844 wall seconds, waiting for IPv4 UDP.
All other network stages completed and maximum timer lag was 9.486 ms.
[Failure evidence](phase4/hosted-mixed-failure.json) retains the original artifact
digest, report and operation state. A separate local churn control reproduced
duplicate live endpoints, and `tests/udp_ownership.php` deterministically fails
because a second bind to an owned address/port succeeds. PHP stream-server bind
enables `SO_REUSEADDR`; a new endpoint can receive the first endpoint's datagram.
Exact endpoint addresses were not captured in the hosted failure, so direct
attribution of that individual timeout remains unproven. The frozen local retry
was interrupted to repair this demonstrated defect and start a fresh campaign.

The repair adds exclusive native bind and preserves the existing UDP readiness,
stream and cancellation paths. A negative control on frozen `3587740` demonstrates
datagram delivery to the newer socket while the original times out, separately
for IPv4 and IPv6. Corrected ownership tests pass PHP 8.3.36/8.4.27/8.5.12 with
epoll and poll, DEBUG PHP and fully instrumented GCC ASan/UBSan. The 50,000-cycle
churn control passes. Valgrind reports zero errors, zero lost bytes and 3,888
reachable bytes without suppressions.

A child-only descriptor-limit test also found and repaired first-autoload failure
in exception/cleanup paths under `EMFILE`. It verifies errno, release/reopen and
descriptor cleanup on all three PHP branches and GCC instrumentation. A DEBUG
assertion rejected the first patch's resource return-type declaration; it was
corrected to PHP's untyped resource convention and all affected builds rerun.
Initial setup/stale-build failures remain in the evidence rather than being
discarded. [Repair evidence](phase4/udp-ownership-fix.json) distinguishes stages.

`make verify`, `make audit` and `make audit-fpm` pass the rebuilt module. A separate
FPM lifecycle run with UDP held through request teardown passed 60.491 seconds /
300 main requests plus all lifecycle/fault cases, maximum RSS beyond warmup
2,788 KiB and idle six descriptors/one thread. This is a regression smoke test,
not final-source endurance. The r3 local campaigns were later interrupted for
the destination repair below. Final `76e6557` FPM smoke separately passed 60.063
seconds / 432 main requests, maximum 2,820 KiB beyond warmup, with all lifecycle
cases passing; it includes the PHP dependency-preload adjustment.

## UDP destination validation and historical hosted FPM evidence

Independent controls against r3 reproduced a destination port wrapping through
PHP's 16-bit parser: a send to port 118356 reached an owned listener on 52820.
An invalid numeric-looking `[deadbeef]` address entered synchronous getaddrinfo.
A child-only resolver probe introduced a controlled 200ms delay; the old call
blocked for 204.374ms with no ticker progress. This is an injected control,
not a measurement of an external DNS server. No unrelated endpoint was targeted.

The public UDP send path now requires a valid literal IPv4/IPv6 address and
port 1..65535 before suspension or PHP parsing. Malformed addresses, overflow,
embedded NUL/newline, hostname input and oversized payloads raise ValueError.
The fixed resolver control returns in 0.248ms without entering getaddrinfo.
The existing native ownership helper is unchanged. The regression rejects bad
inputs at a test-only transport boundary before any network side effect and
also sends real empty/maximum-size IPv4/IPv6 datagrams.

Targeted tests pass PHP 8.3.36/8.4.27/8.5.12, epoll/poll, DEBUG and GCC ASan/UBSan.
Valgrind: zero errors and lost bytes, 3,888 bytes reachable, no suppressions.
Full verify/audit/FPM passes; expanded mixed smoke passes 60.119s / 166 cycles,
including separate Oracle and MariaDB fixtures and extended networking, with no
final pending work. The smoke overlapped a build and is not a performance result.
[Destination repair evidence](phase4/udp-destinations-fix.json) includes controls,
source hashes and logs. The local 76e6557 campaigns were intentionally interrupted
before changing public source; neither is a pass. [Interruption record](phase4/campaign-76e6557-interrupted.json)
retains every partial sample and artifact identity. A new frozen archive/campaign
is required; r3 must not be relabeled as containing this repair.

The independent hosted FPM job at historical 624cbe9 completed 3,600.036 seconds
and 67,520 main requests, plus lifecycle/concurrency/streaming/recycling/reload/
kill/fatal/timeout checks. Maximum growth beyond warmup was 3,116KiB; steady
worker RSS slopes were 44.954–79.177KiB/hour, with idle six-fd/one-thread checks.
Its artifact ZIP SHA-256 matches the official GitHub digest. See
[hosted FPM attestation](phase4/hosted-fpm-1h.json). This certifies that workload
on the pre-repair source only. Its parallel mixed job failed, so the workflow
as a whole did not pass. No six-/24-hour evidence or release promotion follows.
