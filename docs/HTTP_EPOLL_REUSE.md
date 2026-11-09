# Request-local epoll reuse: HTTP wait-cost investigation

Status: **IMPLEMENTED_BUT_NOT_FULLY_TESTED**. Performance acceptance remains
**NOT_MET**; this is not a claim of 13,000 public requests/second or superiority
over curl_multi/Swoole. Starting source: `bc156480595c3c0b7079f5099bc9a72c4388e767`.
Evidence root: `/home/coder/Asyncara-http-epoll-20261109T164557Z`.

## Native change and invariants

`ext/backend.c::wait_prepare` retains a request-local epoll instance and bounded
scratch storage. It reuses registrations only when the complete descriptor
vector (including order) is unchanged. Every reused descriptor receives
`EPOLL_CTL_MOD`, even if its numeric descriptor and interest mask are unchanged.
Vector changes or `ENOENT` during modification rebuild the complete instance.
Other registration errors invalidate it and retain the original errno.

This matters when a descriptor is closed/reused while `dup` retains the old open
file description: adding the new descriptor to the old instance could retain an
orphan readiness registration. Linux identifies registrations using both the fd
number and open file description. See the [epoll FAQ](https://man7.org/linux/man-pages/man7/epoll.7.html)
and [`epoll_ctl` error semantics](https://man7.org/linux/man-pages/man2/epoll_ctl.2.html).
The cache never owns or closes watched sockets. It owns only its epoll descriptor
and scratch allocations. No PHP references or worker-visible mutable state are added.

The original monotonic deadline includes preparation time. Kernel waits, masks,
level-triggering, EINTR handling, completion dispatch and the poll backend retain
their semantics. No busy polling, new timeout, or new backend is introduced.

`ar_engine_new` initializes the epoll descriptor; `ar_engine_free` releases it
after worker joins, before native resources are destroyed. The first candidate
allocated the descriptor lazily. Existing `tests/resource_copy.php` detected that
its count increased after an initialized-runtime baseline. This failure remains
in `copy-epoll-r1`; the corrected lifetime passes in `copy-epoll-r2`. No assertion
was changed. Initialization failures now have explicit rollback tests.

## Bounds and diagnostics

Scratch grows to at most `AR_MAX_WATCHERS` entries (16384). It is freed before
growth, so peak retained control storage is at most
`sizeof(ar_wait_cache) + 16384 * (sizeof(int) + sizeof(epoll_event))`: 262184 bytes
on the tested Linux x86-64 ABI. One additional engine-owned descriptor is retained
until request shutdown. Kernel epoll allocation is external to that byte count.
Like existing wait scratch, these control allocations are outside the payload
ledger, allowing operations to drain when their payload budget is full.
`asyncara_info()` exposes `waitControlBytes`, `epollCreated` and `epollReused`.
Poll-only engine initialization allocates no cache.

## Focused executable evidence

`tests/fast/epoll_cache.c` uses real socket pairs and production `ar_wait`.
It covers stable waits, mask changes, descriptor vector growth/reordering,
dup-held descriptor reuse, EINTR, registration failure, allocation failure,
invalid descriptors, recovery, exact final fd counts and unchanged socket ownership.
Link wrappers inject only one-shot failures and count actual epoll creation.
The new regression and initialization control are included in Gate A.

- Baseline: safety checks PASS, 20000 creations for 20000 stable waits; the new
  reuse invariant fails with exit 2 (`baseline-regression`). This is an efficiency
  regression control, not proof of a prior readiness correctness defect.
- Initial candidate: safety PASS, one creation for 20000 waits, five allocation
  failure positions (`candidate-regression`). Later initialization moves one
  allocation to engine creation; both boundaries are tested separately.
- Initial candidate HTTP suite: PASS on PHP 8.5.12, epoll and poll, default curl
  plus the optional limited Hyper prototype (`http-85-data`).
- Corrected initialized-resource lifetime: `copy-epoll-r2` PASS. Gate A PASS in
  212.141s (201.504s child CPU), three seeds, epoll/poll, including failure and
  initialization controls (`gate-A-r2-data/A/report.json`). Further compatibility
  and memory checks are pending.

## Measured performance, including the shortfall

`tests/http/wait_scaling.c` uses 1024 submitted HTTP jobs, 256 pending connections
and a separate ready socket, with 2000 real production waits. Three rotated
baseline/candidate samples give median epoll CPU 3.508225s versus 0.532759s
(6.58x reduction in this wait-cost control). Poll CPU is 0.180224s versus
0.177466s. All jobs are cancelled/collected with zero pending payload ownership.
This is not response throughput.

`comparison-r2-data/report.json` contains five rotated samples of public API
requests with identical verified bodies, PHP 8.5.12, tracing JIT, epoll,
concurrency32,16000 measured requests/sample and320 warmups. The same interpreter,
matching curl and Swoole6.2.3 builds, and one isolated nginx worker are used.

| Body | New public median/s | Prior public median/s | curl_multi median/s | Swoole median/s |
|---|---:|---:|---:|---:|
| 1KiB | 4714.42 | 5225.28 | 4603.80 | 5204.78 |
| 64KiB | 3062.36 | 2439.46 | 3333.30 | 3527.43 |

The small-body public path did not improve in this sample group. CPU/request
medians:151.87µs new versus146.55µs prior for1KiB;285.81µs versus348.93µs for64KiB.
The new public runs report two epoll creations and thousands of validated reuses,
so the optimization executes in the measured path. There is substantial sample
variation; a read-only host snapshot is `comparison-host.json`. It does not
attribute the shortfall to the environment or excuse the failed targets.
**13,000/s,4x curl_multi and3x Swoole remain NOT_MET.**

No result from this change resolves the historical Phase 4 zero-tick failure.
The existing HTTP r2 archive and its results remain attached to their original
source. This candidate is not in that archive.
