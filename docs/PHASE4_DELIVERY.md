# Phase 4 engineering evidence

**EXPERIMENTAL — work in progress.** This report separates completed checks
from running campaigns. No Phase 4 release or endurance certification is claimed.

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

The unchanged mixed harness and a separate frozen FPM checkout are running
one-hour campaigns against baseline `179aa54`. The requested workload durations
are 3,600 seconds each. A preflight rejection of an older copied binary is
preserved separately; the FPM checkout was rebuilt before its actual run.

Raw evidence: `/tmp/asyncara-phase4-nrtjh3t1`, also recorded by `build/phase4-path`.
Neither run has completed at this checkpoint. Six-hour and 24-hour runs have
not started. Native and public runtime source remain unchanged during the
campaigns. Later test-harness changes cannot alter their frozen source copies.

The baseline mixed workload includes Tasks/timers, TCP, buffered/streamed HTTP,
files, SQLite/PostgreSQL/MariaDB/Redis, Queue, cancellation/timeouts, ProcessPool
and crash recovery. It does not include UDP, TLS, DNS, WebSocket or Oracle MySQL
in the same mixed campaign. FPM separately exercises sequential, within-request,
multi-worker and streamed concurrency, SQLite/files, recycling/reload/kill,
fatal/pending shutdown and timeout replacement. Coverage expansion must retain
those distinctions and the existing thresholds.

## Immediate engineering work

The FPM harness retains every response and sample in Python memory. Replace this
unbounded test-runner retention with incremental evidence and bounded statistics
before six-/24-hour runs; preserve all raw observations and the unchanged growth
criterion. Add missing resource observations and reproducible campaign metadata.
Investigate the existing libpq unload allocation and Clang callback-type findings
without suppressions or changing the frozen runtime. Subsequent protocol/API work
requires new source checkpoints and relevant repeat validation.

Existing compatibility boundaries remain those in [PHASE3_DELIVERY](PHASE3_DELIVERY.md).
No new HTTP, WebSocket, database, hook, framework, io_uring or ZTS capability is
advertised at this checkpoint. Userland ThreadPool remains **BLOCKED**.
