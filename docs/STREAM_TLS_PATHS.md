# Explicit stream TLS trust paths

Native checkpoint `19a64d29fcaa1499e2d06f15cc24e8f2b0465658` repairs
explicit `caFile` handling in `TcpStream::connect()` and Redis `Connection::connect()`.
Status: IMPLEMENTED_AND_TESTED in the scope below. Release remains EXPERIMENTAL. This is a path ownership and policy repair, not a
replacement TLS engine or a claim of complete remote Redis certification.

## Reproduction and implementation

The preserved `tcp-trust-before-r1/r2` controls reproduce two failures on epoll
and poll: changing cwd after creating the Future changes the CA file selected;
and a forbidden explicit CA file loads despite PHP open_basedir. Both direct
TCP and Redis calls were exercised against a real private-CA Redis TLS listener.
The caller did not disable certificate or hostname verification.

In the inspected PHP8.5.12 source, `ext/openssl/xp_ssl.c` calls
`SSL_CTX_load_verify_locations()` before its PHP stream fallback. The direct
library path explains why Asyncara must enforce the policy before that call.
This does not establish a universal PHP sandbox guarantee or an upstream advisory.

`ext/php_asyncara.c:asyncara_tls_ca_path()` captures PHP virtual cwd into a bounded
stack buffer and returns an absolute PHP-owned filename. It rejects empty,
NUL-containing, CR/LF-containing, wrapper and overlong names. It applies
`php_check_open_basedir()` and preserves exceptions raised by warning handlers.
No Asyncara engine or native allocation is acquired by this helper, including
its warning and failure paths. Invalid input can now throw before a Future exists.

`TcpStream::connect()` captures before scheduling its Task and checks the absolute
name again immediately before starting TLS, after DNS/socket suspension.
`Redis\Connection::connect()` captures before its own enclosing Task. Ordinary
TCP close-on-error, certificate verification, hostname verification, nonblocking
socket readiness and cancellation remain in place. DNS remains worker-backed.
Trust-file loading and cryptography inside PHP/OpenSSL are synchronous; this does
not make every part of TLS initialization kernel-asynchronous.

The snapshot is a name, not a content or symlink snapshot. open_basedir is checked
at admission and TLS setup, not an atomic defense against hostile filesystem
mutation. Default system/environment/ini trust selection is unchanged. The
helper does not impose a CA-content limit on PHP/OpenSSL allocations. Other
wrappers or database-specific trust APIs have their own admission boundaries.

## Reproduce

```sh
python3 tests/database/redis_push.py --php /path/to/php \
  --module /path/to/asyncara.so --backend epoll --trust-paths --out /new/output
```

Repeat with poll. Fourteen fresh PHP processes cover malformed native input,
warning exceptions, relative/absolute paths, immediate and later open_basedir
restriction, forbidden symlinks and dot paths. Repeated failures retain no pending
native operations, tracked bytes, Tasks, Futures, timers or watchers.
The same runner's ordinary mode tests real RESP2/3, ACL and verified TLS; `--fpm`
uses the existing sequential/four-worker workload and fatal pending-query cleanup.
No host service or global PHP configuration is modified.

## Validation

Raw root: `/home/coder/Asyncara-priority-20261110T205459Z`.

* `tcp-trust-matrix-r1`: PASS25 build/test commands,160 PHP executions,
  84.161s. PHP8.3.36/8.4.27/8.5.12 NTS, DEBUG8.5.12 and GCC ASan/UBSan8.5.12,
  all on epoll/poll. C/PHP sanitizer instrumentation does not instrument the
  Rust/AWS-LC release static library.
* `tcp-trust-memory-r1`: PASS34 ordinary-unload DEBUG Valgrind processes,
  244.442s; zero errors and definite/indirect/possible loss,3888bytes reachable
  in each process. No suppressions or library-retention workaround.

* Gate A: PASS49 checks/240.463s outer time.
* Complete PHP8.5 suites: epoll PASS42.619s,
  poll PASS40.771s.
* Complete HTTP gate: PASS93 checks/200.378s.
* Four real Redis/plain-TLS FPM cases:240 main responses,960 overlapping HTTP
  operations,2160 counted Redis commands and240 notifications. Independent
  recomputation finds minimum8 ticks, idle6 FDs/1 thread/1 socket. Original
  two-tick, within-request and four-worker overlap assertions are unchanged.
  RSS ranges and the original conditional resource check are retained in the
  independent report; these short samples do not establish long-run trends.
* Database bounds, plain SQL/Redis integration and allocation audit: PASS.
* Independent C-only build and both-backend trust controls: PASS. The original
  IPv4/IPv6, TLS1.2/1.3, SNI/ALPN, partial I/O, handshake cancellation/deadline
  network fixture also passes with the normal module on both backends. Only
  private data/log paths were changed in its isolated copy; assertions are identical.

[Commands, manifests, module fingerprints and reports](phase7/stream-tls-paths.json)
retain every negative control. An early reviewer invocation refused a still
RUNNING outer report; it produced no review. Its precondition record remains,
and the unchanged reviewer ran successfully after the outer suite completed.
Independent source packaging is recorded separately after its build finishes.
Historical PostgreSQL unload, distribution-PHP shutdown and HTTP/2 proxy Valgrind
deadline findings remain separate. Short scoped passes are not endurance or
universal memory-safety certification.

## Independently built source distribution

Packaged commit `c7bbba4d2f541d7a5e2fd3ad510e06dd22e0b5d0`,680 files:
`build/asyncara-0.1.0-dev-stream-trust-r1.tar.gz`.
SHA-256 `1b3afaa00f785bbc530be840e1e19ebf864c2027a21b7ee71ef3457d364b96dd`.
The reproduction archive is byte-identical and every member matches its Git tree.
Fresh Cargo/C build158.861s and10 Redis/protocol/trust/FPM/corpus checks PASS.
[Independent module and exact commands](phase7/stream-trust-package.json).
This archive predates subsequent filesystem admission work; no GitHub release
was published and historical source distributions remain untouched.
