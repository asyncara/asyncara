# Phase 4 engineering evidence

**EXPERIMENTAL — work in progress.** This report separates completed checks
from failed campaigns and shorter diagnostic runs. No endurance certification is claimed.

## Current evidence at 2026-11-03 15:40 UTC

| Check | Tested source | Result |
|---|---|---|
| Clean r2 package builds and installed tests, PHP 8.3/8.4/8.5 NTS | `3587740` | PASS; source/checksum below |
| Hosted regression 8.3/8.4/8.5 and database lifecycle | `624cbe9` | All four jobs PASS; [run 41662855774](https://github.com/asyncara/asyncara/actions/runs/41662855774) |
| Local one-hour mixed retry, including separate Oracle fixture | frozen `3587740` | Interruption requested after reproducing UDP ownership defect |
| Local one-hour FPM retry | frozen `3587740` | FAIL; ticker assertion, evidence below |
| Hosted one-hour mixed and FPM on separate runners | `624cbe9` | Mixed FAIL at UDP receive; FPM RUNNING; [run 41662855764](https://github.com/asyncara/asyncara/actions/runs/41662855764) |
| Six-/24-hour campaigns | — | NOT EXECUTED; failed local stage cannot advance |

The hosted mixed workload includes extended networking and local PostgreSQL,
MariaDB and Redis fixtures, but not the separate Oracle fixture. Hosted and local
scopes must remain distinct. Later CI/documentation changes do not alter the
runtime or packaged r2 test implementations. Running jobs are not passes.

## Verified baseline

The initial branch and remote were `phase3-hardening` at
`179aa54e473f644f009114ca47e3dd19fd46b8fd`, with a clean working tree. The native/test
commit `e5d94fd8dd959b88474332a05aaa1c9eb61f318f` is an ancestor of archive source
`f3d63ac9abf528af0cd5b24086976912c28e4a75`, which is an ancestor of HEAD. Both
intervening ranges change only documentation/evidence. There is one canonical
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
Neither final-source one-hour attempt passed. Six-hour and 24-hour stages were
not started. Native and public runtime source remain unchanged. Every retry uses
a new output directory and a detached process.

## Harness hardening and coverage

The FPM observer now streams every response/sample to JSONL and aggregates in
constant space per worker. Mixed analysis uses two streaming passes. The original
warmup, endpoint median and resource thresholds are unchanged; six regression
cases verify their equivalence. Whole-series mean replaces the descriptive median.

The process observer records RSS, virtual memory, threads, descriptors, sockets
and child identities, including private PostgreSQL daemons after reparenting.
Inaccessible descriptor metrics are null with an explicit reason, never zero.
Four observer regression cases cover descriptors, ownership checks, permissions
and preservation of existing evidence. Existing strict FPM idle checks remain.

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

Diagnose both failed one-hour attempts, preserving the original assertions.
Repeat the affected campaigns from a new frozen checkpoint before advancing to
six-/24-hour runs. Standalone controls reproduced the libpq unload allocation on16.15/17.11/18.6
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

**Release assessment: EXPERIMENTAL.** Implemented runtime source is unchanged
from `e5d94fd`; the r1 package and failed long campaign identify full tree `2e2f84f`.
Later test/diagnostic changes have separate manifests and are not retroactively
included in that package or campaign. A source archive is not a published GitHub
release. The next release gate is diagnosis and successful frozen endurance,
followed by the remaining resource, database, HTTP, WebSocket and integration
priorities in the canonical [roadmap](ROADMAP.md).

## Latest frozen source and distribution

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
preserves the new diagnostics and raw hashes. The mixed one-hour job continues;
this FPM failure prevents the campaign from advancing to six-/24-hour stages.
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
is interrupted to repair this demonstrated defect and start a fresh campaign.
