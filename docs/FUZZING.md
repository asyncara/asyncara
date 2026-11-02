# Protocol and allocation fuzzing

`tests/fuzz/` exercises production code, with deterministic regression seeds.
The first corpus run reproduced 39 failing prefix/input combinations across
integer range/sign handling, line bounds/control bytes, double syntax/overflow
and verbatim framing. The repaired Redis parser passes all 100 combinations.
These are protocol correctness findings, not native-memory crash discoveries.
Grammar expectations follow the [Redis RESP specification](https://redis.io/docs/latest/develop/reference/protocol-spec/).

`resp_harness.php` invokes the existing private parser through reflection, using
an actual TcpStream over a memory stream and varying its initial buffered prefix.
It does not maintain a second parser. Truncation reaches EOF; malformed lengths,
nesting, signed overflow and unsupported attributes are explicit errors. Network
cancellation/slow-peer behavior remains covered by integration tests. RESP3
attributes, streamed aggregates and non-scalar map keys remain unsupported.

The IPC codec is shared by the parent and worker. The grammar parser remains
PHP's JSON implementation. A bounded lexical admission scan prevents large
collections from allocating huge PHP arrays before validation, and uses the same
policy for encoding and decoding. `ipc_regressions.php` sends deliberately
malformed and truncated frames through real worker pipes in three-byte pieces,
then verifies replacement workers can execute a healthy job. No untrusted PHP or
object deserialization is used.

The native target uses [libFuzzer](https://llvm.org/docs/LibFuzzer.html), Clang 18,
ASan and UBSan. It mutates allocator sizes, overflow attempts, reallocations,
value/array/job ownership and allocation failure positions. It asserts the native
ledger returns to zero for every input. This target has no worker threads; the
separate existing native TSan harness checks threaded lifecycle paths. AFL++ was
not present and was not run. Third-party shared libraries were not rebuilt with
sanitizers.

Recorded initial runs on the Phase 3 Linux host:

| Target | Inputs / duration | Result |
|---|---|---|
| Native libFuzzer, seed 20261102 | 326,134 / 61 seconds | No ASan/UBSan finding; peak RSS 295 MiB including ASan quarantine |
| PHP deterministic mutation, same seed | 12,737 / 60.007 seconds | No warning, unexpected exception or sanitizer report under instrumented PHP 8.5.11 |
| RESP regression corpus | 100 prefix/input combinations | Pass after targeted repairs |
| IPC malformed framing / worker recovery | 7 malformed streams plus structure/round-trip checks | Pass |

The PHP mutation target exercises Redis, IPC JSON/length processing and Asyncara's
header-to-array conversion. This is deterministic mutation testing, **not**
coverage-guided PHP fuzzing. Native libFuzzer coverage is limited to the compiled
target's executed paths. Curl owns HTTP wire/chunked parsing; OpenSSL owns TLS;
libpq/Connector/C/SQLite own their protocol/file formats. AsyncStream transports
PHP values through Queue and has no wire framing parser. WebSocket framing and close validation are now separate PHP mutation targets, described below.
No fuzz coverage is claimed for these external or absent parsers.

Reproduce from a built checkout:

```sh
php -n -d extension=$PWD/build/asyncara.so tests/fuzz/resp_regressions.php
php -n -d extension=$PWD/build/asyncara.so tests/fuzz/ipc_regressions.php
./scripts/fuzz-native.sh 60 build/fuzz-native-local
php -n -d extension=$PWD/build/asyncara.so tests/fuzz/protocol_mutation.php 60 1000000 20261102
```

For instrumented PHP use the matching ASan/UBSan PHP binary and extension,
`USE_ZEND_ALLOC=0 ASAN_OPTIONS=detect_leaks=1:halt_on_error=1
UBSAN_OPTIONS=halt_on_error=1`. Building only the extension with ASan does not
instrument PHP's parser/Fiber code. `memory_get_peak_usage()` reports zero with
Zend allocation disabled; RSS must be obtained externally. The native runner
retains mutated corpus, log and crash artifacts in its output directory. Never
discard a crashing input: minimize it, add an expected-result regression and
rerun both the corpus and affected integrations. Current discovered regression
inputs are committed under `tests/fuzz/corpus/resp/`; no native crash was found.

CI runs regression seeds and short fuzz smoke tests. These runs are evidence of
bounded testing, not proof of parser completeness or production safety.

Final native ASan/UBSan rerun after the filesystem changes: 294,043 inputs in 61 seconds, 867 new corpus units, peak RSS 297 MiB, no finding. Raw log/corpus: final-native-fuzz under build/phase3-path. This target exercises allocator/value/job ownership; atomic syscall faults are a separate tests/audit/atomic_write.c harness, not falsely counted as coverage-guided filesystem fuzzing.

## HTTP buffer continuation

The new `tests/fuzz/http_buffers.c` compiles the production body/header/upload
callbacks directly. It mutates buffer fill/drain, pause/redelivery, EOF, partial
upload reads, cancellation, arithmetic overflow and allocation failures. It does
not implement or claim to fuzz curl HTTP/chunk/TLS parsing. Seed corpus is under
`tests/fuzz/corpus/http-buffers`. Run `./scripts/fuzz-http-buffers.sh 60 NEW_DIR`.
ASan/UBSan campaigns completed 704,679 and 602,547 inputs in 61s each, peaks 373/369MiB,
with no finding. Native targets exclude Zend; separately instrumented PHP and
real local HTTP tests cover the integration. No crashing corpus was produced.

## WebSocket framing mutations

`tests/fuzz/websocket_mutation.php` uses the production FrameCodec and ten seed
files, deterministic seed 20261102, truncated/duplicated/flipped/appended inputs
and partial feeds of 1–31 bytes. It checks successful round trips and close
payload validation. Input size is capped at 2048 bytes. Network handshake and
message-assembly states are integration tests, not this fuzz target.

```sh
USE_ZEND_ALLOC=0 ASAN_OPTIONS=detect_leaks=1:abort_on_error=1 \
  /isolated/php-sanitized/bin/php -n tests/fuzz/websocket_mutation.php 60 1000000 /tmp/new-ws-fuzz
```

Actual fully instrumented PHP8.5 campaigns: 24,933/60.001s and 47,262/60.002s,
no finding. Final RSS peak 322,620 KiB. PHP allocator peak is unavailable with
USE_ZEND_ALLOC=0; an earlier reported zero is not zero memory use. Current input
is written before each execution; unexpected failures preserve it. This is
bounded deterministic mutation testing under ASan/UBSan, not native libFuzzer
coverage or full RFC conformance certification.

The independent slow-path audit uses test-only syscall interposition. On PHP
builds that use RTLD_DEEPBIND, its loader shim clears that flag only for the exact
selected audit module so the actual installed binary reaches the delay hooks.
Production loading is unchanged. The file test fails within two seconds if the
injector never reaches open; no endless marker wait or weakened responsiveness
assertion is accepted. Normal/instrumented runtime tests remain separate.

## Hosted instrumented-memory budget

Hosted native smoke runs exhausted the existing512MiB RSS ceiling: diagnostics
showed25MiB live allocations,147MiB quarantined frees and sanitizer metadata.
The failures remain recorded. ASan quarantine is now explicitly64MiB rather than
its implicit256MiB default; leak checks, invalid-access checks, UBSan and the
512MiB process ceiling remain enabled. This bounds instrumentation retention and
shortens the stale-pointer detection window; it is not a runtime quota increase
or an error suppression. Reruns:306,396native inputs/61s/198MiB peak,803,874HTTP
buffer inputs/61s/147MiB peak, no findings. Both targets preserve crash artifacts
in CI. Mapped-buffer tail poisoning has an independent intentional-failure test.
