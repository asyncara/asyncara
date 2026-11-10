# Dependency diagnostics

## 2026-11-10 PHP fatal bailout during prepared PostgreSQL work

This is a new diagnostic scope, separate from distribution-PHP cyclic shutdown,
libpq TLS unloading and TLS-provider reports below. The DEBUG PHP 8.5.12 build
with Valgrind 3.22, `USE_ZEND_ALLOC=0`, full leak reporting and no suppressions
reports lost Zend allocations after `E_USER_ERROR` inside a Fiber. It is a
retained **FAIL**, not a clean memory pass.

Raw root: `/home/coder/Asyncara-pg-cache-20261110T061903Z`.
The complete commands, binary/source fingerprints, original reports, allocation
stacks and PHP-only source controls are retained in
[postgresql-prepared.json](phase7/postgresql-prepared.json).

| Matched control | Exit | Definitely / indirectly / possibly lost bytes |
|---|---:|---:|
| PHP only, plain E_USER_ERROR | 99 | 256 / 620 / 0 |
| PHP only, Fiber E_USER_ERROR | 99 | 640 / 1848 / 0 |
| PHP only, Fiber exit(0) | 0 | 0 / 0 / 0 |
| PHP only, uncaught Fiber exception | 255 | 0 / 0 / 0 |
| New module/source, prepared cache2, pending query + fatal | 99 | 1104 / 5841 / 872 |
| New module/source, cache0, identical pending query + fatal | 99 | 1104 / 5841 / 872 |
| Previous d0c270a source/module, identical pending query + fatal | 99 | 1104 / 5841 / 872 |
| Normal pending exit, each of the preceding three controls | 0 | 0 / 0 / 0 |

The primary test fixture has a different local graph and reports
1160 / 6296 / 872 bytes on both epoll and poll. Its normal full-cache workload,
pending exit and PHP allocation-exhaustion cases report zero errors/lost bytes
with4041 reachable. OOM uses `USE_ZEND_ALLOC=1` to exercise PHP's actual8MiB
limit; that does not expose every PHP allocation as an individual malloc.
All observed application PostgreSQL sessions disappear after each child exit.

The independently failing PHP-only Fiber source is:

```php
<?php
$fiber = new Fiber(function () {
    $value = ["a" => str_repeat("x", 32)];
    trigger_error("Intentional control fatal", E_USER_ERROR);
});
$fiber->start();
```

Run it without `-d extension=...` under the recorded Valgrind/allocator settings.
The fatal stacks allocate in `__zend_malloc`, compiler argument structures,
`zend_closure_new`, arrays and JSON-decoded PHP values. Inspection of the local
PHP8.5.12 source finds `_zend_bailout` sets `CG(unclean_shutdown)`, protects GC,
clears `EG(current_execute_data)` and longjmps. The Fiber bailout catch forwards
that condition instead of performing the ordinary callback-return cleanup;
DEBUG shutdown's cycle collection is guarded by `!CG(unclean_shutdown)`.
These facts explain why ordinary exit/exception results cannot certify fatal
bailout cleanup. The precise remaining PHP graph is not fully reconstructed.

Conclusion: no added lost bytes were detected from prepared caching in the
identical old/new/cache-disabled fatal workload. The broader fatal memory gate
remains FAIL with a reproduced PHP bailout component; this is not proof that
every Asyncara allocation path is leak-free, a harmless tool false positive,
or an upstream fix. No suppression, private Zend-state modification or production
GC workaround was introduced. The earlier distribution-PHP finding remains
independently classified below.

## 2026-11-10 server TLS: rustls/ring AES definedness finding

The new server TLS implementation was tested with rustls0.23.46 and
ring0.17.14. Unsuppressed Valgrind3.22 reports undefined-value use in AES-GCM
authentication and ciphertext writes. The functional exchanges return the exact
expected bytes, but these memory-check runs are **FAIL**, not clean passes.
This is separate from the PHP cycle-shutdown, libpq unload and curl trailer
findings below.

Evidence root: `/home/coder/Asyncara-completion-20261110T013405Z`.

| Control | Observed result |
|---|---|
| DEBUG PHP server, TLS echo/stream/close, ring provider | exit99; undefined-value diagnostics; zero definitely/indirectly lost bytes |
| Same server with origin tracking and expensive definedness checks | exit99; retained in `memory-origins/` |
| Direct ring AES128/AES256/ChaCha seal/open, initialized and random keys, several payload layouts | exit0; zero errors/lost bytes |
| In-memory rustls client/server, verified CA, AES128 or AES256 only | exit99; no PHP, Asyncara, Hyper or socket adapter involved |
| Same rustls control with ChaCha20-Poly1305 | exit0; zero errors/lost bytes |
| Public rustls TLS13 AES256 AEAD with fixed key, IV and plaintext; no handshake | exit99; seven undefined-value write errors, zero lost bytes |
| Independently built Valgrind3.28, unchanged control binaries | Same AES failures; direct ring and rustls ChaCha controls pass |

`tests/server/rustls_aead_control.rs` is the smallest retained reproducer.
`rustls_control.rs` checks both directions and verifies plaintext; `ring_control.rs`
tests the lower-level primitive separately. The AES finding is reproducible
without the Asyncara adapter. That does **not** establish a harmless false
positive, an upstream fix, or absence of additional Asyncara defects. A debug
symbol build without LTO reproduces it as well. No suppression or allocator
workaround was applied. Precise attribution between rustls buffer handling,
generated code, the primitive and Valgrind definedness tracking remains open.

Raw reports: `extra-r1/`, `memory-origins/`, `ring-control-r2/` through
`ring-control-r5/`, `rustls-control-r3/`, `crypto-debug-control/`,
`rustls-aead-control/`, and `valgrind-328-controls/report.json`.
Valgrind3.28 was built in a private directory from the
[official release archive](https://valgrind.org/downloads/current.html), with
SHA256 `9f7a18d128f05d3a80fea53e6953f34aad7200a425ceaee46099a8a798a7dd9c`.
The host installation was not changed. The alternative
[official rustls crypto provider](https://docs.rs/rustls/0.23.46/rustls/crypto/index.html)
was then tested independently: aws-lc-rs1.19.0/aws-lc-sys0.47.0 passes the same
AES128/AES256/ChaCha exchange controls under Valgrind3.28, with zero errors or
lost bytes (`aws-control/report.json`). The new server TLS implementation uses
this provider. It retains rustls, the existing reactor, all ciphers and certificate
verification; no provider change is made to preexisting curl/OpenSSL clients.
The final server DEBUG PHP TLS echo/stream/close workload passes unsuppressed
Valgrind3.22 on epoll and poll: zero errors/definite/indirect/possible loss,
5,306 bytes still reachable. See `memory-aws-r1/report.json` and its full logs.
This scoped result is the concrete reason for the provider choice. It does not
resolve the historical ring finding, certify every crypto path, or imply FIPS
certification. Pure-library controls are reproducible using the separate locked
manifest in `tests/server/crypto-control/README.md`.

## Historical 2026-11-03 investigations

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

## 2026-11-07 native-program follow-up: distribution PHP cycle shutdown

This supersedes the *attribution uncertainty* in the preceding historical
entry, not its failing results. Valgrind 3.22.0, distribution PHP8.5.12 NTS,
`USE_ZEND_ALLOC=0`, `--leak-check=full --show-leak-kinds=all
--errors-for-leak-kinds=definite,indirect --error-exitcode=99`, no suppressions:

| Independent control | Distribution result | Matching DEBUG result |
|---|---|---|
| Original six-case lifecycle, old `fast-full.so`, two repetitions | exit99; 1128/0 and 1224/0 direct/indirect bytes | zero lost/errors |
| Same workload, wait-budget native candidate | exit99; 1888/120 bytes | Separate final-source validation indexed below |
| Isolated file producer early-close case | exit99; 712/120 bytes | Not substituted for full workload |
| Separate full-workload copy adding explicit collection before process exit | two repetitions: zero lost/errors; 117 and105 cyclic PHP values collected | No production change |
| PHP-only20 ordinary object/closure cycles, no extension or Fibers | exit99; 8480 direct bytes /40 blocks | zero lost/errors |
| Same PHP-only cycles created inside completed Fibers | same8480 bytes /40 blocks | zero lost/errors |
| Both PHP-only variants with explicit collection | zero lost/errors; zero live weak references | zero lost/errors |

`tests/dependencies/php_cycle_shutdown.php plain shutdown` is the independent
reproducer; `plain collect`, `fiber shutdown`, and `fiber collect` are controls.
The plain case proves that unregistered Fiber-stack warnings are not necessary
for these losses. Fiber warnings remain separately recorded in Fiber workloads.

Exact matching PHP8.5.12 source in
`/tmp/asyncara-phase3-cQBA3H/php-8.5.12-source/php-8.5.12` explains the build
difference: `Zend/zend_execute_API.c:zend_shutdown_executor_values` calls
`gc_collect_cycles()` under `#if ZEND_DEBUG` when shutdown is not unclean.
`Zend/zend_objects_API.c:zend_objects_store_free_object_storage` intentionally
releases object contents without freeing surviving object allocations, so they
are visible as leaks. The uncollected cyclic objects and closures appear in
Zend allocation stacks; DEBUG's additional collection eliminates them.
[Matching PHP source](https://github.com/php/php-src/blob/php-8.5.12/Zend/zend_execute_API.c).

Conclusion: the reported distribution/DEBUG discrepancy is reproducible PHP
cyclic-object shutdown behavior, demonstrated independently of Asyncara and
without Fibers. It is not evidence of an Asyncara C payload leak, nor merely a
Valgrind stack-warning artifact. The original no-explicit-GC runs remain FAIL;
the underlying cyclic allocations exist and must not be called harmless or
universally bounded. Normal cycle collection during long-running execution is
a separate question covered only by the explicit operation-count checkpoints.
No claim of an upstream fix or accepted bug report is made. The libpq TLS
library-unload allocation is unrelated to these stacks.

Raw commands, environment, binary hashes, full allocation stacks and exit codes:
`/home/coder/Asyncara-native-program-20261107T173550Z/memory-identities.json`,
`memory-summary.json`, `original-distribution-*`, `distribution-case-6`,
`distribution-gc-*`, `cycles-*`, and their `*-memory.log` files. Final-source
instrumentation is indexed in [NATIVE_RELIABILITY_PROGRAM.md](NATIVE_RELIABILITY_PROGRAM.md).
No production GC calls, weakened assertions, or suppressions were introduced.

## Resource-provenance continuation: matched controls

At native trace checkpoint `9c8c369`, Valgrind3.22.0 / PHP8.5.12 NTS /
USE_ZEND_ALLOC=0 / full unsuppressed definite+indirect checking was repeated.
All original no-collection failures remain FAIL (exit99).

| Matched workload | Direct / indirect lost bytes |
|---|---|
| Current PHP source, new module (two repetitions) |1784 /648 each|
| Same PHP source, prior program-r2 module (two repetitions) |1888 /120 each|
| Old packaged PHP source, prior module |1128 /0|
| Same old PHP source, new module |752 /120|
| Current workload, explicit GC in separate diagnostic copy, either module |0 /0|
| PHP-only plain object cycles, extension absent or loaded idle |8480 /0 in both|
| PHP-only Fiber-created cycles, extension absent or loaded idle |8480 /0 in both|
| Those cycles explicitly collected, or matching DEBUG PHP |0 /0|
| Plain Fibers without cycles |0 /0|
| Raw native worker submit/collect, prior/new module or new enabled trace |0 /0|
| DEBUG lifecycle, resource-copy, trace controls; epoll and poll |0 /0|
| Native concurrent ring under Valgrind |0 /0|

Lost-allocation stacks in the rich workload originate in Zend object, Fiber and
closure allocation. None of those lost records identifies an Asyncara native
payload/ring allocation. Matching PHP source confirms the DEBUG-only shutdown
collection and object-store behavior described above. The independent plain
cycle control has exactly the same losses with Asyncara absent and loaded.

The rich workload totals do differ between module/source combinations. This
experiment does **not** prove that Asyncara adds no PHP cyclic retention or
explain every object's identity and classification. Explicit collection removes
the observed losses, but was not added to production. The defensible conclusion
is reproducible PHP shutdown-cycle behavior, with unresolved per-workload
retention/classification differences; scoped native/DEBUG results remain clean.
The distribution workload remains FAIL, not a universal memory-safety pass.
Fiber-stack warnings and still-reachable memory are retained separately. No
connection to the earlier libpq TLS unload allocation was observed.

Evidence: `/home/coder/Asyncara-resource-provenance-20261107T212909Z/` contains
`memory-identities.json`, `memory-complete-results.json` (full loss stacks and
hashes), all `memory-*-memory.log`, launch/results and the exact differential
scripts. Independent sources are `tests/dependencies/php_cycle_shutdown.php`,
`native-memory-control.php`, and `plain-fiber.php`.

The final `resource-provenance-r2.so` repetition (native checkpoint b7ea081)
retains the same1784 direct/648 indirect loss,0 possible loss,77515 reachable
bytes and exit99. `final-distribution-memory.log` is the full unsuppressed report;
`final-distribution/result.json` records binary path, environment and command.
`final-distribution-native` reports zero loss for raw worker operations. Final
DEBUG lifecycle/copy/trace controls and native ring/index/worker controls also
report zero loss/errors. These results preserve the distinction above.

## 2026-11-09: repeated chunked trailers in system libcurl8.5.0

A newly expanded real HTTP framing regression exposed a dependency leak. This is
separate from PHP Fiber/cyclic-object shutdown and libpq TLS findings above.

Installed library: Ubuntu `libcurl4t64 8.5.0-2ubuntu10.16`,
`/lib/x86_64-linux-gnu/libcurl.so.4`, SHA256
`3e51df20a6b52879ee83a207115a9bc6891092741ce2f5ec791606e6caf859fa`.
`tests/http/transfer_geometry.php` sends80 chunked/trailer responses among160
binary responses. Both previous capacity source and the new candidate report
**2304 definitely lost bytes in72 allocations** under GCC LeakSanitizer. DEBUG
Valgrind independently reports2304 definite,0 indirect,0 possible,3888 reachable,
zero suppressions. The Asyncara native ledger returns to zero: this does not
account for libcurl's allocations and does not make the workload memory-clean.

A dependency-only C control, [curl_trailer_control.c](../tests/http/curl_trailer_control.c),
links libcurl and no PHP/Asyncara code. Ten repeated chunked responses over a
reused connection lose288 bytes in9 allocations under Valgrind3.22.0 (exit99).
The same control with `CURLOPT_FORBID_REUSE=1` loses0 definite/indirect/possible
bytes (exit0). Both retain20893 reachable bytes in library/loader state, not lost
blocks. The control does not reset easy handles, so Asyncara's easy-handle cache
and its recent receive-buffer change are not required to reproduce the leak.
The no-reuse case is a diagnostic control, not a production workaround or speed
comparison. Normal Asyncara connection pooling has not been disabled.

The observed pattern agrees with curl's
[upstream trailer-leak issue17749](https://github.com/curl/curl/issues/17749) and
[maintainer-vetted legacy patch](https://curl.se/mail/distros-2025-07/0001.html).
The public [8.5.0 source](https://raw.githubusercontent.com/curl/curl/curl-8_5_0/lib/http_chunks.c)
initializes the connection trailer buffer again and resets rather than frees it
at trailer completion. The repeated32-byte allocation stacks and independent
control establish external-library ownership here. The upstream report concerns
7.88.1 and notes newer releases changed the parser; it is not evidence that every
8.5 distro package is affected or that every newer build is fixed. This exact
installed binary demonstrably is affected. At dd63396 no patched/newer library
had been certified here; the later matched control below qualifies its own scope.

Evidence root:`/home/coder/Asyncara-http-transfer-evidence-20261109T211228Z/`:
`gcc-epoll-curl-multi-transfer_geometry`, `geometry-asan-symbols`,
`geometry-baseline-gcc`, `geometry-debug-valgrind`, and
`curl-trailer-control-r2-{0,1}`. The symbolization control sets
ZEND_DONT_UNLOAD_MODULES=1 only for that diagnostic process and still reports the
same loss; ordinary reports retain the original unload configuration. Initial
native Valgrind invocations lacked the existing debuginfo path and could not
start; those logs also remain. No suppressions or global dependency updates.

Status: **REPRODUCED_UPSTREAM_DEPENDENCY_DEFECT** for this workload/library.
Memory acceptance of the affected curl path remains **FAIL**. Deployments need a
vetted package containing the trailer fix or a separately validated newer curl,
then must rerun the exact regression. A clean scoped Hyper result cannot erase
this default-backend dependency failure. No universal memory-clean claim follows.

### Matched isolated trailer correction (continuation)

Evidence: `/home/coder/Asyncara-http-continuation-20261109T224615Z`.
Official curl8.5.0 archive SHA256:
`42ab8db9e20d8290a3b633e7fbb3cec15db34df65fd1015ef8ac1e4723750eeb`.
Its detached signature verifies against the publisher's HTTPS-delivered key
`27EDEAF22F3ABCEB50DB9A125CC908FDB71E12C2`; no local web-of-trust claim.
The two extracted trees differ only by `Curl_dyn_reset` → `Curl_dyn_free` for
`conn->trailer` in `lib/http_chunks.c`, as in the maintainer-vetted patch linked
above. Configuration/compiler settings are identical apart from separate prefixes.
Both include OpenSSL3.0.13, zlib1.3 and threaded DNS. LDAP, PSL, IDN2 and HTTP/2
are absent in this **diagnostic configuration**.

The standalone reuse control fails on unpatched upstream8.5.0 with288 definitely
lost bytes/9blocks, then passes with zero definite/indirect/possible losses after
the single-line correction. Both no-reuse controls pass. All four retain56
reachable loader bytes; no suppressions. Reports: `curl-{upstream,patched}-control-data/`.
Patched library SHA256:
`1700e28526f921a11cb80e07ef90bdbd0d4f2587173631d60219b754efe53e17`.

The real Asyncara160-response binary/chunked/trailer regression also passes
scoped GCC ASan/UBSan and DEBUG Valgrind on both epoll/poll with that isolated
library (`gcc-*-curl-multi-transfer_geometry`, `valgrind-*-transfer_geometry`).
The Valgrind scope reports zero lost bytes; exact remaining reachable allocations
are retained in each log. Rust machine code and the dependency are not compiled
with ASan merely because Asyncara's C/PHP test binary is instrumented.

`scripts/build-local.sh` accepts explicit `CURL_PREFIX=/absolute/prefix`; the
control runner accepts `--curl-prefix`. Missing headers/library reject. `ldd`
records confirm the actual selected dependency. The system library is untouched
and its previous FAIL remains valid. This experiment proves the trailer repair;
it does **not** recommend deploying an otherwise unpatched old8.5.0 tarball or
certify all TLS/proxy/security behavior. Use a maintained vendor/upstream build
and revalidate the required configuration before deployment.
