# Dependency diagnostics, 2026-11-03

These are unresolved dependency findings, not clean sanitizer passes. No
suppression, private-pointer cleanup, global allocator tuning or runtime change
was introduced. Raw reports remain under `/tmp/asyncara-phase4-nrtjh3t1`;
[durable results, source hashes and allocation stacks](phase4/dependency-findings.json)
identify each binary and run.

## libpq TLS method lifetime

`tests/dependencies/libpq_lifecycle.c` links only libc/dl, loads the named libpq
and performs verified localhost TLS connections plus `SELECT 42`. Every result
and connection is cleared/finished. PHP and Asyncara are absent. A private CA,
private PostgreSQL16.15 fixture and private output directory are used.

| libpq build | 500 connections, library retained | One unload after 1 or 500 connections | 20 load/unload cycles, five connections each |
|---|---|---|---|
| Ubuntu16.15, including system GSS dependencies | LSan no lost allocations; Valgrind resident100 also no losses | 109 bytes: 96 direct +13 indirect | 5,087 total lost; 2,180 BIO bytes plus GSS-related allocations |
| Upstream17.11, local OpenSSL build | Same resident result | Same109 bytes | 2,180 bytes /40 allocations |
| Upstream18.6, local OpenSSL build | Same resident result | Same109 bytes | 2,180 bytes /40 allocations |

The newer builds were downloaded from the official source archives, checked
against their SHA256 manifests and built locally. Configure used
`--with-ssl=openssl --without-icu --without-readline --without-zlib`, `CFLAGS=-O1 -g`.
They do not have the same optional GSS dependency configuration as the system16.15
build; the additional16.15 allocations are not evidence of a version regression.
No host packages or services were replaced. Official stable version information
was checked on the observation date; prerelease19 was not treated as stable.
[PostgreSQL version policy](https://www.postgresql.org/support/versioning/).

The17.11 allocation stack is `malloc → CRYPTO_zalloc → BIO_meth_new →
my_BIO_s_socket` (`fe-secure-openssl.c:2023`) `→ my_SSL_set_fd → initialize_SSL →
pgtls_open_client → pqsecure_open_client → PQconnectPoll → PQconnectdb`.
The18.6 equivalent uses `pgconn_bio_method` at line1882 and `ssl_set_pgconn_bio`.
Source inspection finds a static method pointer whose successful allocation is
retained for library lifetime; its error branch frees failed construction. This
explains reachability while loaded and loss after unloading. The tested18.6
release still reproduces it. No matching upstream fix or accepted issue was
verified; no claim is made that every PostgreSQL version is affected.
[Upstream TLS source](https://github.com/postgres/postgres/blob/REL_18_STABLE/src/interfaces/libpq/fe-secure-openssl.c).

Valgrind retains symbols across unload and reports zero suppressions. In resident
tests the method remains reachable, along with other library/loader globals.
Repeated unloads accumulate the method allocation; they must not be described as
a single bounded109-byte process cost. The additional16.15 reload stacks traverse
`libgssapi_krb5`, `gss_acquire_cred` and `PQconnectPoll`; their separate upstream
resolution remains unverified. Normal tested FPM workers retain libpq until
process shutdown. These short controls do not establish long-running RSS trends
or authorize arbitrary dynamic unloading/reloading.

Reproduce with a JSON object mapping version labels to absolute `libpq.so` paths:

```sh
python3 tests/dependencies/libpq_lifecycle.py --libraries /path/libraries.json \
  --output /tmp/new-libpq-observations
```

The runner builds ASan/UBSan and plain probes with warnings as errors, then runs
LSan and unsuppressed Valgrind. Leak exits are recorded as findings, never passes.
The system must provide the matching PostgreSQL server tools, public headers,
OpenSSL and local Valgrind/debug symbols. It does not install them. Fixture startup
now waits for `pg_isready`, rather than merely an open port. A separate negative
control reproduced `timeout expired` with a3s connect deadline under Valgrind;
the instrumented fixture uses30s. Earlier startup/timeout failures are retained.

## Clang18 / PHP8.5.12 callback types

The existing fully instrumented PHP8.5.12 DEBUG build was rerun independently.
Asyncara was rebuilt with Clang18, ASan/UBSan and that exact build's headers.
`php -n -v` reports seven incompatible-function-pointer diagnostics both without
Asyncara and with it loaded. Halting UBSan exits1 at `php_ini_parser_cb` in both
cases. The defined final parameter is `HashTable *`; the callback type uses
`void *`. The caller is PHP's generated INI parser. Other startup/shutdown
diagnostics identify PHP date, PCRE, Zend extension and hash-table callbacks.
[PHP8.5.12 source](https://github.com/php/php-src/blob/php-8.5.12/main/php_ini.c).

The matching loaded information probe and1,000-task audit also report PHP
callback diagnostics. Functional completion in recovery mode does not turn them
into passing sanitizer tests. Clang's function sanitizer explicitly checks calls
through incompatible indirect types; no evidence here justifies calling these
false positives. GCC does not provide equivalent coverage merely by accepting
the same run. [Clang UBSan documentation](https://clang.llvm.org/docs/UndefinedBehaviorSanitizer.html).

```sh
python3 tests/dependencies/clang_callbacks.py --php /path/clang-php/bin/php \
  --extension /path/matching/asyncara.so --output /tmp/new-callback-observations
```

The report retains commands, binary/log hashes, exit codes and function names.
No PHP source fix, suppression or upstream resolution is claimed. Matching PHP
and Asyncara builds isolate the observed call sites; all third-party libraries
have not been rebuilt with identical Clang instrumentation, so that broader
configuration remains unverified. Native Clang fuzzing and GCC instrumentation
results are separate evidence, not substitutes for this unresolved run.


## 2026-11-07 fast-reliability memory controls

The distribution-PHP discrepancy remains separate from the libpq TLS finding.
On source 8263fcd-equivalent runtime bytes, repeated lifecycle runs under
Valgrind exit 99: initially 1,128 direct/0 indirect bytes; after locked dependencies
were installed, 904/120 and 1,888/120 bytes on independent repetitions. DEBUG
Valgrind-aware PHP passes with zero lost/errors in the same workload. New native
wait/close checks also have zero lost/errors, with still-reachable allocations
reported separately. Zend/Fiber allocation stacks and variable loss totals do not
establish whether the cause is PHP, application-owned PHP objects, or tool/runtime
interaction. No suppression or ownership attribution is justified yet.
See [fast-reliability results](FAST_RELIABILITY.md) and the hashed command/log
inventory in [phase5/fast-validation.json](phase5/fast-validation.json).
