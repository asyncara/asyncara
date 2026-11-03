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

The first baseline one-hour attempts were interrupted when the agent turn ended.
The mixed run retained 1,195.491 seconds and the FPM run 1,166.910 seconds of
observations; neither has a completion report. Both are **INTERRUPTED**, not
passed. Two remaining project-owned fixtures were stopped after verifying their
private data/configuration paths. [Interruption evidence](phase4/interrupted-campaigns.json)
retains the exact partial durations. All original files remain intact.

Raw evidence: `/tmp/asyncara-phase4-nrtjh3t1`, also recorded by `build/phase4-path`.
No final-source one-hour, six-hour or 24-hour campaign has completed. Native and
public runtime source remain unchanged. Restarts must use new output directories
and a detached process so an agent turn interruption does not terminate a run.

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
regression and original FPM audit passed. Long campaign restart is pending.
[Harness evidence](phase4/harness-validation.json) preserves reports and log hashes.

## Remaining engineering work

Complete the corrected harness verification, freeze it and restart staged
one-/six-/24-hour mixed and FPM campaigns. Standalone controls reproduced the libpq unload allocation on16.15/17.11/18.6
and PHP callback-type findings with and without Asyncara. These remain unresolved
dependency findings; see [DEPENDENCY_FINDINGS](DEPENDENCY_FINDINGS.md).
Subsequent native/API work requires new checkpoints and relevant repeated soaks.

Existing compatibility boundaries remain those in [PHASE3_DELIVERY](PHASE3_DELIVERY.md).
No new HTTP, WebSocket, database, hook, framework, io_uring or ZTS capability is
advertised at this checkpoint. Userland ThreadPool remains **BLOCKED**.
