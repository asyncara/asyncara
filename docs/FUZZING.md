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
PHP values through Queue and has no wire framing parser. WebSocket is absent.
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
