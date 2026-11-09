# HTTP buffer capacity correction

Native source: `b1cfd18a97eb9962761ba1c4c2fad0bad3ef25bb`.
Test source: `9a59bef23a584ed1b6a0702f380be5ba5c690b19`.
Base: `c2dc5594b6f0d0b60e94a73f1209cda3e03b0142`.
Worktree: `/home/coder/Asyncara-http-capacity-20261109T190638Z`. Evidence: `/home/coder/Asyncara-http-capacity-evidence-20261109T190638Z`.
Release remains EXPERIMENTAL. Performance acceptance: **NOT_MET**.
[Machine-readable evidence](phase6/http-capacity-evidence.json) records hashes,
commands, manifests and explicit retained failures.

## Native behavior and ownership

`ext/backend.c::receive_buffer` formerly included the NUL terminator in geometric
payload growth. A complete4096/65536/1048576-byte body could reserve8192/131072/
2097152 bytes. The corrected capacity is payload capacity plus one:4097/65537/
1048577. Small initial allocations cost one extra byte. The16MiB buffered-body
and64KiB stream/header limits are unchanged. Failed realloc preserves the old
pointer and ledger; cancellation, pause/redelivery, completion and request owners
are unchanged. The native ledger excludes curl, OpenSSL, Rust and allocator/kernel
caches. Less tracked capacity is not automatically less RSS or higher throughput.

`tests/http/buffer_capacity.c` executes the production callbacks on binary data,
varied boundaries/chunks,16MiB rejection,64KiB pause/redelivery, cancellation,
overflow and allocation failure. The old implementation records23 excessive
power-of-two capacities and accepts0/4 final bytes under a3MiB aggregate limit.
The corrected implementation records zero excessive capacities and accepts4/4;
tracked bytes2100804 remain below3145728 and return to zero after collection.

`tests/http/capacity.php` and `capacity_server.py` independently exercise public
HttpClient calls over real sockets. Four512KiB responses are held one byte short
until all prefixes are sent and the caller releases them. Two timer ticks must
occur while all four Futures remain pending. Same test/limit on curl and Hyper,
epoll and poll: baseline exits255 on all four; candidate passes all four and
returns pending operations/tracked bytes to zero. No timer-only replacement,
increased limit or elapsed-time release was used.

## Retained failures and test-peer repair

The initial PHP8.3/poll `connections.php 32` execution failed `Progress or cleanup`.
The old message did not identify whether ticks, pending operations or tracked
bytes violated the predicate. Ten alternating old/new repetitions each passed
(minimum10/15 ticks); later complete passes do not explain this failure. Commit
`3efdab9` adds actual values only after the unchanged assertion fails. The failure
remains unresolved; no host-only attribution or native fix is claimed.

A later PHP8.5/poll Hyper ownership test failed `Hyper partial transfer` after
15.850s. The original peer cleared its Event when `/headers-first` arrived,
losing a prior `/release-body`. `gate_protocol.php` deliberately orders those
requests and reproduces the exception on the old fixture after16.126s. The test
peer now consumes a counting-semaphore permit per body, retaining early release
without leaking it into later bodies. The15s guard and two-tick assertions remain.
Four backend/transport combinations and ten Hyper ownership repetitions pass.
The original failed request did not log peer ordering, so that specific failure
is consistent with the proven race, not retrospectively reconstructed. The
fixture fix does not explain the separate connection-progress or historical B2
failures. Original raw logs remain unchanged.

The initial full suite also retains an environment failure: Nyholm\Psr7 was
not installed in the new worktree (43 pass, one missing-class failure). Installing
the six unchanged composer.lock packages repairs that setup; full-r2 checks are
separate results. The first Composer attempt lacked Phar under -n; the retry
loads matching isolated extension modules. No tracked dependency file changed.

## Completed validation

| Check | Result | Wall time |
|---|---|---:|
| http-85-r3 | PASS | 93.976s |
| http-83-r3 | PASS | 67.628s |
| http-84 | PASS | 64.967s |
| http-debug | PASS | 72.308s |
| http-gcc | PASS | 173.661s |
| gate-A | PASS | 201.822s |
| full-r2-epoll | PASS | 36.101s |
| full-r2-poll | PASS | 38.359s |
| headers-debug-valgrind | PASS | 36.967s |
| capacity-native-valgrind | PASS | 2.049s |
| capacity-asan-run | PASS | 0.216s |
| http-buffer-fuzz | PASS | 41.909s |

PHP versions:8.3.36,8.4.27,8.5.12 NTS. HTTP gates include both epoll/poll,
curl default and optional Hyper. DEBUG/GCC use8.5.12. The native callback test
also passes ASan/UBSan and Valgrind without suppressions. DEBUG header conversion
has zero errors and zero definite/indirect/possible loss. Rust machine code is
not ASan-instrumented. The historical distribution-PHP discrepancy is unchanged.
The short HTTP buffer fuzzer tests owned callbacks, not curl's protocol parser.

Short separate feature FPM checks:

- fpm, A-lifecycle: 20 responses, 1 worker(s), minimum 10 ticks; idle RSS [20844, 21220]KiB.
- fpm, HTTP-four-workers: 40 responses, 4 worker(s), minimum 9 ticks; idle RSS [18952, 20796]KiB.
- hyper_fpm, A-lifecycle: 20 responses, 1 worker(s), minimum 21 ticks; idle RSS [17556, 18004]KiB.
- hyper_fpm, Hyper-four-workers: 40 responses, 4 worker(s), minimum 23 ticks; idle RSS [15648, 17516]KiB.

All main responses satisfy the original overlap/two-tick predicate, native
pending/bytes zero before/after, and idle6fds/1thread/1socket. These60-response
runs do not replace the failed mixed B2 or long-duration endurance evidence.

## Matched public HTTP comparison

Five rotated samples per client/body;16000 measured responses per sample plus
320 warmups; PHP8.5.12, matching curl/Swoole6.2.3, tracing JIT, poll,32 rolling
connections, four isolated nginx workers. Public API responses/status/bodies
are checked. No raw-native or standalone Rust rate is substituted.

| Body | Public Hyper median/s (minimum) | Previous Hyper median/s | Public curl median/s | curl_multi median/s | Swoole median/s |
|---|---:|---:|---:|---:|---:|
| 1KiB | 9301.92 (4257.91) | 7147.15 | 6762.67 | 8730.99 | 7813.95 |
| 64KiB | 4970.34 (3626.29) | 3639.10 | 2860.03 | 5537.47 | 5792.46 |

All samples, client/server CPU, latency percentiles, RSS and native peaks remain
in `comparison-data/report.json`. The13,000/s floor and4x/3x comparisons are
separate requirements; a functional PASS does not satisfy them. No fastest-PHP
claim is established. Hyper remains a restricted numeric plaintext GET prototype;
curl retains TLS, DNS, uploads, streaming and the broader tested API.

## Earlier experiments retained

`c2dc559` changes only bounded header metadata searching in C and adds85 boundary
cases (10090 total). Its five-sample converter CPU ratios are1.177x for common
headers and10.127x for a long8KiB value; those are not HTTP response ratios.
Its full matrix/Gate A/short FPM pass, but public medians7401.79/s (1KiB) and
3866.62/s (64KiB) do not show a general speed gain over the previous source.
Evidence: `/home/coder/Asyncara-http-headers-20261109T185417Z`.

The separate Rust short-read experiment `af89cdb` reduces unnecessary recv/EAGAIN
calls in its scoped control, but the matched public comparison is slower. It
remains DO_NOT_ADOPT on `phase6-http-short-read`; it is absent from this source.
Native Future and hook-removal experiments also remain rejected.

## Reproduce and boundaries

Build with the matching PHP patch SDK using `scripts/build-local.sh`, or the
optional `scripts/build-hyper-local.sh`. Generate local private test certificates
using `scripts/prepare-test-certs.sh`, then run:

```sh
python3 tests/http/run.py --php /absolute/matching/php --extension /absolute/module.so --output /new/http-results --hyper
python3 tests/fast/gate_a.py --php /absolute/matching/php --extension /absolute/module.so --output /new/gate-A-results
```

Omit `--hyper` for the default curl-only build. Public build dependencies and
optional Rust lockfiles remain as documented; no global PHP configuration changed.
A newly packaged archive must have its own source/hash/build attestation; this
validation does not retroactively certify an older archive. Remaining priorities:
explain any recurrence of connection progress with the recorded counters, retain
B2 failure attribution work, and profile public-path cost before another native
performance experiment. Neither thresholds nor resource limits may be relaxed.

## Later test-fixture cleanup and rejected C experiment

Test checkpoint `f26ad6b71a20c55bc452e80d77672d37499f3c82` repairs capacity_server.py EOF cleanup only.
The peer now unblocks held handlers after the test owner closes stdin, so Python
Server.wait_closed can join them. Normal body release/progress assertions remain
unchanged. An independent no-PHP partial-connection regression fails before the
repair and passes after; public capacity controls pass on all12 PHP/backend/HTTP
transport combinations. [Exact review](phase6/http-peer-eof-evidence.json).
Native source remains b1cfd18 and all existing module hashes are unchanged.

The isolated smaller-initial-buffer experiment9eea780 is DO_NOT_ADOPT. It reduces
1KiB tracked peak294656→73472 bytes but measured Hyper median8826.87/s versus
8783.95/s baseline;64KiB4519.07/s versus4990.34/s, CPU201.199 versus181.212us.
No useful overall speed improvement is established. Source, full measurements
and failed fixture/build controls remain at
`/home/coder/Asyncara-http-small-buffer-20261109T194455Z` and its separate evidence
directory. The C tier change is absent from this capacity source.

## Independently built source archive

`build/asyncara-0.1.0-dev-http-r5-capacity.tar.gz` contains506 tracked files from
source `f12146a1c74e6c5247425d7653738eaeb98d1f5d` (native b1cfd18).
SHA-256: `62390a6f482f8d070a0a8e677e5deaa337d4ba84b436107b74719d2c431aa26e`.
Two source packages are byte-identical; every archive file matches Git and the
clean extracted tree after validation. Fresh curl-only PHP83/84/85 builds and
HTTP gates pass on epoll/poll; a separate new Cargo/Hyper85 build and HTTP gate
also pass. Package HEAD/feature FPM passes20 single-worker and40 four-worker
main responses, minimum17/21 ticks, checked overlap, zero native pending/bytes
before/after and idle6fds/1thread/1socket. No project binaries or generated source
were copied into the extraction. Public SDK/dependency headers and host libraries
are external build prerequisites.
[Build and independent review](phase6/http-r5-capacity-package.json) retain all
module/report hashes. These fresh binaries have their own identities; workspace
benchmark numbers are not claimed as new measurements of package binaries.
No archive was published to GitHub. The rejected small-initial-buffer9eea780 is
not included. EXPERIMENTAL, with performance NOT_MET and previous failures retained.

The scoped native Valgrind control has0 errors/definite/indirect/possible lost
bytes and56 still-reachable bytes; DEBUG header conversion has0 errors/lost bytes
and3888 still-reachable bytes. No suppression was used. This is not a universal
leak guarantee or resolution of the distribution-PHP report. The callback fuzzer
executed486795 inputs in31s (seed20261102), peak RSS145MiB, zero crash artifacts;
it does not fuzz curl's parser or certify Rust machine code.
