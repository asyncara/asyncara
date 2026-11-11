# HTTP trust-path ownership

Status: IMPLEMENTED_AND_TESTED in the configurations below. Release remains
EXPERIMENTAL. Native source `30bdea1` changes ext/php_asyncara.c and the existing
HttpClient; no HTTP parser, connection pool or readiness backend is replaced.

## Reproduced defects and repair

Before-controls `http-ca-before-r1` and `http-ca-before-r2` prove that deferred
relative CA filenames could select a different trust file after cwd changed.
The expanded control fails five public scenarios on each client: immediate GET,
client configuration before a cwd change, queued admission, response streams and
upload streams. Direct native curl submission also fails. Explicit curl CA file
access bypassed PHP open_basedir; Hyper's worker file API already rejected it.
Copies of the before-control sources and all original FAIL logs remain preserved.

HttpClient captures an explicit relative caFile against cwd at construction.
The native HTTP entry point independently captures its owned CA filename at
submission and enforces php_check_open_basedir before libcurl can open it. The
option is absent from the borrowed fast option view: replacement memory belongs
to the native job and retains ordinary failure/cancellation cleanup. The PHP
caller array is not modified. The public path rule applies before queued permits,
Hyper preparation and producer Tasks can defer execution.

This snapshots a filename, not file bytes or symlink targets. It is not an atomic
filesystem sandbox: changing a file/symlink after validation remains a separate
boundary. Curl trust loading and TLS initialization retain the library's behavior,
including synchronous file/crypto work; socket waiting remains curl multi event
driven. Hyper still loads at most1MiB of trust through workers and retains the
client's existing byte snapshot. Default environment/system trust selection is
unchanged and not covered by the explicit-caFile claim. No verification bypass,
extra worker or replacement TLS implementation was introduced.

## Exact validation

Raw root: `/home/coder/Asyncara-priority-20261110T205459Z`.
[Attestation, commands and hashes](phase7/http-trust-paths.json).

| Check | Result |
|---|---|
| PHP8.3.36/8.4.27/8.5.12 NTS, DEBUG8.5.12, GCC ASan/UBSan8.5.12; epoll/poll; curl/Hyper | matrix-r1 PASS25 commands/107.397s,140 mode executions; repeated restriction failures leave native pending/memory and live Future registry at zero |
| DEBUG Valgrind3.22.0, ordinary unload, USE_ZEND_ALLOC=0 | memory-r2 PASS28 PHP executions/258.568s; zero errors and definite/indirect/possible loss;3888bytes reachable for curl,3888 or5306 for Hyper; no suppression |
| Gate A | PASS47 checks/199.542s outer wall time |
| Full PHP8.5 suite | epoll PASS38.855s; poll PASS41.534s |
| Complete HTTP gate | PASS88 commands/152.800s, including new path regressions |
| Private-CA FPM, both clients/backends | regressions-r2 PASS four cases:240 main requests/960 HTTP operations,20 sequential and40 four-worker requests per case, plus fatal pending-transfer recovery |
| Allocation audit | PASS5.861s |
| Independent C-only build and path regressions | conly-r1 PASS build + both backends; no Rust transport required |

Independent FPM recalculation checks response counts, actual worker identities,
overlapping peer intervals, unchanged two-tick threshold and idle resources.
Minimum observed ticks across all main responses:16. Idle samples have6 FDs and
1 OS thread. Every new request starts with0 native workers; after Hyper's CA
worker reads there are precisely4 request-owned workers, joined at RSHUTDOWN.
Curl has0 native workers in this fixture. RSS ranges and raw hashes are in
`http-ca-regressions-r2/independent-summary.json`. Four successful short cases
are not endurance certification or proof of the historical zero-tick cause.

The original memory-r1 remains FAIL: a test-only FPM helper changed while the
strict whole-test-directory manifest was frozen. All eight completed raw
memcheck children were clean, but that incomplete run is not the final gate.
The final frozen memory-r2 supersedes it without erasing it.

The original regressions-r1 remains FAIL after its completed Gate A/full/HTTP
passes: the new FPM helper incorrectly required no Hyper file workers before
request shutdown. `http-ca-fpm-worker-diagnostic-r1` records pending0/memory0/
futures0/workers4. Source ar_engine_free joins workers at RSHUTDOWN, matching the
older DNS FPM fixture. The corrected helper requires exactly4 before shutdown
and independently1 OS thread afterward; original ticker, overlap, FD and resource
assertions are unchanged. No runtime patch was made to accommodate this test.

## Reproduce

```
python3 tests/http/ca_paths.py --php /path/to/php --module /path/to/asyncara.so \
  --backend epoll --transport curl-multi --out /new/output
python3 tests/http/ca_fpm.py --php /path/to/php --module /path/to/asyncara.so \
  --fpm /path/to/php-fpm --backend epoll --transport hyper-prototype --out /new/fpm
```

Repeat with poll and each transport. The HTTP gate includes both path suites
when --hyper is selected; CI includes the separate TLS FPM variation. Only JSON
reports/logs are selected for upload, excluding fixture private keys. GCC tests
instrument C/PHP, not the Rust/AWS-LC release library. Upstream libpq unloading,
distribution-PHP cyclic shutdown and historical sanitizer findings retain their
separate status. No broad protocol/proxy or universal memory certification follows.
