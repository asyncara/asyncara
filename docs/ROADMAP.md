# Implementation status

Latest priority source package:707 verified files, fresh Rust/C build and29
commands PASS; [current scope](PRIORITY_CAPABILITIES.md) and
[package attestation](phase7/proxy-services-package.json). Historical failures
remain separate; broad protocol certification remains partial.

## Current priority contracts (2026-11-11)

[Priority capability matrix](PRIORITY_CAPABILITIES.md) is the current lookup for
Rust Hyper, Native Server, both HTTP clients, HTTP/proxy certification, SQL, Redis,
filesystem and managed-memory hardening. It defines each tested API contract and
keeps broader unsupported certification separate from implementation status.
Historical tables below retain their original source/date.

The new native continuation adds [bounded Basic proxy authentication](PROXY_AUTHENTICATION.md)
and repairs reproduced native/root-callback trace disclosure and pre-runtime
connection-pool configuration. Final service/memory/regression evidence is
recorded in that continuation; no universal protocol or memory guarantee and
no production release promotion is made.

## Development priority (2026-11-11)

The owner selected **Asyncara Server as the primary development and validation
mode**. FPM compatibility and existing tests remain available. New FPM-specific
failures are retained and deferred instead of blocking native-server progress;
a defect reproduced in shared runtime ownership, memory or I/O paths still
requires correction. Existing FPM results and historical failures keep their
original status. This priority decision is not a claim of new compatibility or
production readiness.


## Verified SQL TLS filename ownership (2026-11-11)

Native `3ea26e1` / `6ed922b` repair reproduced deferred CA/cert/key selection and
open_basedir admission/resume bypasses in PostgreSQL, MariaDB and Oracle MySQL.
The explicit filename policy is **IMPLEMENTED_AND_TESTED** in [the defined scope](SQL_TLS_PATHS.md):
PHP83/84/85, DEBUG/GCC, both readiness backends, Gate A, complete PHP85/HTTP and
Native Server checks. Unsuppressed policy/MySQL memory controls pass; positive
PostgreSQL TLS unload still FAILs109 bytes and remains separately documented.
Independent696-file packaging, fresh Cargo/C compilation and27 SQL/Native Server
checks PASS; [package attestation](phase7/sql-tls-paths-package.json).
No FPM endurance, universal DB compatibility or production-readiness claim is added.

## Verified deferred filename ownership (2026-11-11)

Native `141174a` fixes reproduced cwd-dependent filename selection in filesystem,
SQLite and HTTP transfers. The scoped policy is **IMPLEMENTED_AND_TESTED**:
five-build epoll/poll matrix, ordinary DEBUG Valgrind, Gate A, full PHP85/HTTP,
original and supplementary FPM checks, C-only build and independent raw FPM
review pass. [Exact results and boundaries](FILESYSTEM.md#filename-capture-at-admission-2026-11-11).
Filename ownership does not pin an inode; file I/O remains worker-backed.
Historical failures and the earlier stop checkpoint are preserved. The 691-file source package has a verified fresh Cargo/C build and 25 checks,
including Native Server on both backends; [package attestation](phase7/filesystem-admission-package.json). Release remains EXPERIMENTAL.


## Verified TCP/Redis trust-path ownership (2026-11-11)

Native `19a64d2` repairs reproduced relative-CA selection and explicit
open_basedir bypasses in TCP/Redis TLS. Filename capture before Task scheduling,
policy recheck before TLS, failure ownership and warning exceptions are
IMPLEMENTED_AND_TESTED in [the defined scope](STREAM_TLS_PATHS.md).
PHP83/84/85, DEBUG/GCC epoll/poll, scoped ordinary Valgrind, Gate A, complete
PHP85/HTTP and short four-worker FPM checks pass. No trust-content snapshot,
atomic filesystem sandbox, broad remote certification or universal memory-safety
claim is made. Historical failures remain intact; release stays EXPERIMENTAL.


## Verified Redis push/reply ownership (2026-11-11)

Redis7.0.15 RESP3 command reply association, bounded parsed notification queues,
explicit drain, dedicated RESP2/3 Pub/Sub and failure cleanup are
IMPLEMENTED_AND_TESTED in [the documented scope](REDIS_NOTIFICATIONS.md).
Runtime `fdc3fc3` preserves native readiness backends; the PHP parser no longer
returns an invalidation push as a PING reply. PHP83/84/85, DEBUG/GCC epoll/poll,
scoped ordinary DEBUG Valgrind, full PHP85 suites, Gate A and four-worker
plain/TLS FPM pass. No idle background notification pump, Cluster/Sentinel,
attributes, streaming aggregates or complete caching certification is claimed.
Historical failures and EXPERIMENTAL classification remain unchanged.

## Verified buffered HTTP/2 continuation (2026-11-11)

Native `acda044` fixes a reproduced curl easy-handle cache ownership failure
while sibling HTTP/2 streams remain active. Buffered curl HTTP/2 over verified
TLS, directly and through HTTPS CONNECT, is IMPLEMENTED_AND_TESTED within
[the explicit scope](HTTP2_CLIENT.md). Five-build/both-backend functional and
GCC matrix, full PHP85 suites, Gate A49, HTTP93 and short four-worker FPM pass.
Independent recomputation covers240 main/960 HTTP operations, minimum13 ticks
and idle6 descriptors/1 thread. Full proxy Valgrind remains FAIL at the unchanged
5s deadline; an independent libcurl-only control reproduces that instrumented
cost. Direct DEBUG and native cache memory controls pass separately.
HTTP/2 streaming, Hyper/Server HTTP/2, HTTP/3 and universal proxy certification
are not included. Historical failures remain intact; release stays EXPERIMENTAL.

## Verified HTTPS proxy continuation (2026-11-11)

Native `2a5224f` adds independent `proxyCaFile` trust to the existing curl multi
client. Verified HTTP/1.1 forward/CONNECT, TLS identity rejection, streaming,
uploads, cancellation and request cleanup are IMPLEMENTED_AND_TESTED in the
[documented scope](HTTPS_PROXY.md). PHP83/84/85, DEBUG/GCC, epoll/poll, ordinary
DEBUG Valgrind, Gate A, full PHP85/HTTP and four-worker FPM checks pass.
Hyper proxies, HTTP/2/3 proxy certification, proxy authentication and SOCKS remain
outside this result. Historical failures remain preserved; EXPERIMENTAL stays.


## HTTP trust-path repair (2026-11-11)

Native `30bdea1` fixes a reproduced deferred relative-CA lookup defect on both
clients and a curl open_basedir bypass. Explicit trust-path ownership is
IMPLEMENTED_AND_TESTED: PHP83/84/85, DEBUG/GCC, epoll/poll, scoped Valgrind,
Gate A, full PHP85/HTTP and private-CA FPM checks. [Exact scope](HTTP_TRUST_PATHS.md)
preserves failing-before controls and test-helper failures. No atomic path sandbox,
new protocol/proxy certification or historical endurance resolution is claimed.
Release stays EXPERIMENTAL.

## Connector/C verified client TLS continuation (2026-11-11)

Paired certFile/keyFile and optional encrypted-key password are
IMPLEMENTED_AND_TESTED through the existing native start/continue driver.
MariaDB11.8.9 and Oracle MySQL8.4.13 have independent private-CA/authentication,
PHP83/84/85 epoll/poll, DEBUG, GCC, Valgrind and short FPM evidence. Native
checkpoint `94614a6`; [exact scope and results](MYSQL_MTLS.md). Stock3.3.17
rejects unsupported TLS explicitly. No DNS/SNI, WAN, rotation, broader version,
prepared-cache or server cancellation acknowledgement claim is added.
Other priority gaps remain open; release stays EXPERIMENTAL.

## PostgreSQL verified client TLS continuation (2026-11-11)

The explicit sslcert/sslkey/sslpassword API is IMPLEMENTED_AND_TESTED against
PostgreSQL16.15/libpq16.15, PHP83/84/85 and both backends. Real lock-held timer
progress, prepared queries, cancellation, encrypted-key rejection, FPM lifecycle
and cleanup pass. [Exact evidence](POSTGRESQL_MTLS.md) separates ordinary unload
FAIL109bytes from diagnostic retention PASS and a no-PHP reproduction. No
universal memory-clean, remote cancellation acknowledgement or WAN/rotation
certification is claimed. Other priority gaps remain open; EXPERIMENTAL stays.

## Hyper bounded gzip/deflate continuation (2026-11-10)

Native checkpoint `23e652f` implements buffered gzip and RFC1950 deflate through
zlib with Asyncara-tracked allocations and independent16MiB encoded/decoded
limits. HTTP/1.1 and verified HTTPS, cancellation, malformed-input rejection,
streaming preservation and two ticks before peer release pass the PHP83/84/85,
DEBUG and GCC epoll/poll matrix. The full HTTP gate passes84 commands; Gate A
passes183.667s and both full PHP85 suites pass. Short compressed-response FPM
checks pass on both backends. [Exact scope](HYPER_COMPRESSION.md) records memory,
negative controls and packaging separately. This does not certify broad proxies,
HTTP/2/3, Brotli/zstd or historical zero-tick causes. EXPERIMENTAL is unchanged.


## Hyper bounded streaming continuation (2026-11-10)

IMPLEMENTED_AND_TESTED for HTTP/1.1 response streams, fixed/chunked iterable
uploads, worker-backed file upload/download, verified HTTPS, backpressure and
owner cleanup. Native checkpoint `a0e3098`; no fallback to curl. The same public
HttpClient, client permits and C buffer limits are retained. See
[HTTP_STREAMING.md](HTTP_STREAMING.md#hyper-client-streaming-continuation-2026-11-10).
The35-command PHP83/84/85/DEBUG/GCC epoll/poll matrix and10 additional aggregate
memory-bound checks pass. Gate A203.435s and the76-command HTTP gate pass;
complete PHP85 suites and short four-worker FPM controls pass on both backends.
Scoped DEBUG Valgrind ownership controls report zero errors/loss,5306bytes
reachable. These results do not close broad protocol/proxy certification,
historical memory findings, client mTLS, decompression, redirects or HTTP/2/3.
Release remains EXPERIMENTAL; older rows retain their original source scope.


## PostgreSQL prepared-cache continuation (2026-11-10)

IMPLEMENTED_AND_TESTED: opt-in bounded per-connection server preparation and LRU
reuse through PQsendPrepare/PQsendQueryPrepared. PHP 8.3/8.4/8.5, DEBUG and GCC
epoll/poll functional matrix, Gate A, full PHP 8.5, database bounds and short
four-worker FPM checks pass. Original query defaults and shared-waiter semantics
are preserved. Fatal-bailout Valgrind is still FAIL; independent PHP-only and
matched previous-source controls characterize it without suppression. Ordinary
prepared-query and pending-exit memory checks pass. See
[exact scope](POSTGRESQL_PREPARED.md). PostgreSQL remains partial for broader
remote compatibility and acknowledged server cancellation. Historical rows below
retain their original checkpoint scope. Release remains EXPERIMENTAL.

## Hyper buffered request continuation (2026-11-10)

Bounded methods, ordinary headers, JSON/form/binary bodies and post-response
upload ownership are implemented. The final matrix passes95 commands across
PHP83/84/85, DEBUG and GCC ASan/UBSan on epoll/poll. Affected integration and
memory checks now pass in their stated scope: IMPLEMENTED_AND_TESTED for these
bounded requests. Independent packaging is separate. [HYPER_REQUESTS.md](HYPER_REQUESTS.md)
records failing-before controls, corrections and retained failures. Hyper remains
partial for decompression, proxies/redirects, streaming and HTTP/2/3. Existing
curl and all historical checkpoint labels keep their original scope.

## Previous Hyper HTTPS and bounded TLS input checkpoint (2026-11-10)

The current optional Hyper client adds verified TLS1.2/1.3 buffered GET, DNS/IP
certificate checks, bounded trust snapshots, pool trust isolation and handshake
deadlines. DNS and CA-file reads use existing workers; HTTP/TLS uses the native
readiness driver. Default curl remains the comprehensive HTTP client.
[Exact scope and evidence](HYPER_TLS.md). Hyper remains PARTIALLY_IMPLEMENTED for
general methods/headers, decompression, redirects/proxies, streaming and HTTP/2/3.

Shared server/client TLS reads coalesce already-ready records without waiting
for later data. The unchanged slow-input test now passes the 85-command PHP
83/84/85/DEBUG/GCC epoll/poll matrix; the previous measured deadline failure is
preserved. These local functional results do not resolve historical FPM zero-tick
failures or certify broader performance/endurance. Release remains EXPERIMENTAL.


## Current completion work (2026-11-10)

HTTPS/mTLS and bounded response streaming are implemented and tested on the
existing server branch, including scoped memory checks with the AWS-LC provider.
[Current inventory and validation](COMPLETION_STATUS.md) separates active gaps
from historical PARTIALLY_IMPLEMENTED records. The preceding server-r1 archive
is unchanged; its results do not certify new code. Release remains EXPERIMENTAL.

The dated checkpoints below retain their original source scope.

Request-body streaming is implemented and tested in the opt-in handler-owned,
bounded half-duplex scope; see [SERVER.md](SERVER.md). Normal/DEBUG matrix,
full suites, four-worker controls and scoped Valgrind pass. One GCC/poll/TLS
slow-input reset remains unresolved despite four passing repeats; the original
failure is preserved in [validation evidence](phase7/server-request-stream.json).

## Historical server-r1 checkpoint (2026-11-10)

The isolated `phase7-native-server-20261109T231953Z` branch adds
`Asyncara\App` with Hyper HTTP/1 transport, bounded bodies, routed Fiber handlers,
groups, middleware and optional prefork workers. Native checkpoint `195f088`
passes the focused PHP83/84/85, DEBUG and scoped GCC sanitizer matrix on epoll
and poll, plus Gate A. See [SERVER.md](SERVER.md) for the API, exact namespace
compatibility decision, build commands, ownership boundaries and evidence.
This is **PARTIALLY_IMPLEMENTED / EXPERIMENTAL**, not a production server or a
claim of outperforming Go. At that checkpoint TLS, HTTP/2/3 and server streaming were unavailable.
Historical HTTP-client and Phase4 failures remain separate and preserved.
Final scoped validation and full PHP85 suites pass. The matched server
[benchmark](LATEST_SERVER_BENCHMARK.md) does not meet the all-cases Go target;
large-body median wins do not establish universal superiority.

Server source archive: `build/asyncara-0.1.0-dev-server-r1.tar.gz`,557 files,
packaged commit `b1dc5b21bd5b175ddb33077a267b73f2684ad553`, native `195f088`.
SHA-256: `6839e7cac6ef6e6526389fca00db7640e17c816335850d1826bea25c8cec7dbb`.
Byte-reproducible and Git-tree matched; a fresh extracted Rust/C PHP85 build passes
server protocol, body lifetime and API tests on epoll/poll.
[Independent build attestation](phase7/server-r1-package.json). Not published;
performance acceptance remains NOT_MET and release remains EXPERIMENTAL.

## Previous HTTP-client checkpoint

Current runtime: `922a02459774df98191937cbc1f3ffacc80c975d`; test follow-up
`b1b14e9`, branch `phase6-http-transfer-20261109T211228Z`. Hyper now supports
worker-resolved ASCII DNS origins, preserved Host/origin identity, bounded
address fallback and owned cancellation/drain through the existing HttpClient.
[DNS and dependency continuation](HYPER_DNS.md) records the executable evidence.
Scoped normal/debug/sanitized DNS tests pass; final broad acceptance is INCOMPLETE (unchanged curl TLS-proxy progress assertion failed).
HTTPS/decompression/streaming remain unavailable in Hyper. Default curl remains
unchanged. Its host-library trailer memory FAIL is preserved; a separately built
upstream correction passes matched controls. Release EXPERIMENTAL; speed targets
remain NOT_MET. No prior package contains these DNS changes.

## Previous safety checkpoint (historical)

Previous isolated native source: `dd63396`, branch
`phase6-http-transfer-20261109T211228Z`. Hyper FFI fatal containment and repeated
Content-Encoding rejection have failing-before/passing-after regressions.
PHP83/84/85 ordinary HTTP gates pass on epoll/poll. Memory acceptance is not
universal: system libcurl8.5.0 leaks repeated chunked-trailer allocations in an
independent no-Asyncara control. The original failures remain retained.
See [Hyper safety review](HYPER_SAFETY_REVIEW.md) and
[dependency findings](DEPENDENCY_FINDINGS.md). Hyper remains PARTIALLY_IMPLEMENTED:
numeric plaintext buffered GET, no DNS/TLS/decompression. Receive-geometry speed
experiment remains unaccepted;13k/4x/3x targets NOT_MET. Release EXPERIMENTAL.
New local candidate package: **hyper-safety-r1**,521files, source3317084,
runtime dd63396. SHA256
`a29eab103da8f5fb4df78314a50afe670a483c7ace46ab743c91c9bd144db78b`.
Byte-reproducible, Git-tree matched, independently built; six focused Hyper
controls pass. [Attestation](phase6/hyper-safety-package.json). Not published;
known dependency-memory and timing failures prevent general acceptance.
The historical http-r5 package below predates these changes.

Previous local source archive: **http-r5-capacity**,506 files, packaged f12146a,
native b1cfd18. Independent PHP83/84/85 HTTP builds/tests, optional Hyper85 and
short feature FPM pass. [Attestation](phase6/http-r5-capacity-package.json).
Performance remains NOT_MET; prior progress/B2 failures remain unresolved.
Release EXPERIMENTAL; not published. That package remains historical evidence.

Test-only follow-up `f26ad6b` adds safe peer EOF teardown and a regression; native
source remains b1cfd18. All12 capacity controls pass again. The smaller-initial-
buffer experiment9eea780 is retained but not merged. See
[capacity follow-up](HTTP_BUFFER_CAPACITY.md#later-test-fixture-cleanup-and-rejected-c-experiment).

## HTTP capacity continuation (2026-11-09)

Native `b1cfd18`, tests `9a59bef`: buffer growth now reserves the terminator
without doubling exact power-of-two payload capacity. Four512KiB public responses
complete under3MiB where the old implementation fails; two ticks during pending
I/O, binary integrity, cancellation, bounds and zero-after-collection are tested.
PHP83/84/85, DEBUG/GCC HTTP matrices, full85 suites, Gate A and scoped memory/
short FPM tests pass. [HTTP_BUFFER_CAPACITY.md](HTTP_BUFFER_CAPACITY.md) records
all exact identities and retained failures. The initial PHP83/poll progress
failure remains unexplained. A separate early-release race in the test peer is
fixed with a failing-before/passing-after regression; it is not a runtime change.

Current public Hyper medians: 9301.92/s (1KiB),
4970.34/s (64KiB). Requested performance:
**NOT_MET**. Historical B2 remains FAIL; release EXPERIMENTAL.
No Phase4 files or installed campaign module were changed.

## Previous recorded checkpoint
## Latest HTTP continuation (2026-11-09)

`84e49f1` corrects HEAD upload framing by rejecting unsupported content before
submission while preserving ordinary bodyless HEAD. This boundary is
IMPLEMENTED_AND_TESTED in PHP8.3/8.4/8.5, DEBUG/GCC HTTP matrices, Gate A and
scoped native/DEBUG Valgrind. The original negative regression fails before the
fix. Later HEAD-specific feature FPM validation passes60 main requests, including
producer rejection and bodyless framing. These short passes are separate from
the still-failed B2 campaign. See [HTTP_HEAD_SAFETY.md](HTTP_HEAD_SAFETY.md).

`09b4767` adds bounded request-local epoll reuse with descriptor revalidation,
unchanged deadlines and exact teardown. Gate A and the PHP83/84/85 targeted
checks pass, alongside full85 suites, scoped ASan/UBSan, native TSan/Valgrind and
DEBUG Valgrind. B1 passes; B2 FAILS after5,236 recorded main responses with
one tick instead of the required two. Separate diagnosis is ongoing; no
acceptance assertion was changed. The public performance
targets remain **NOT_MET**. See [HTTP_EPOLL_REUSE.md](HTTP_EPOLL_REUSE.md).

The entries below preserve earlier checkpoints and their own test scope.

Native checkpoint `d57d088` includes `5ffa61e` dispatch improvements and `c79e907`
optional Hyper integration. These supersede the historical HTTP table below.
Default curl feature coverage is preserved. Hyper is **PARTIALLY_IMPLEMENTED**,
limited to numeric plaintext buffered GET. Exact PHP patch SDK matching is now
enforced at module initialization. Matching 8.5.12 Gate A and B2 pass; B2 has
8,092 responses over 600.095 seconds with independently recomputed resource and
progress assertions. New final matrix/package validation remains in progress.
The 13,000/s, 4x curl_multi and 3x Swoole requirements remain **NOT_MET**.
No native-Future or timer-adapter experiment was adopted. Older comparator PHP
extensions require matching-SDK replacement before certified relative comparisons.
See [HTTP_PERFORMANCE_WORK.md](HTTP_PERFORMANCE_WORK.md).

## Initial HTTP native API continuation (historical, 2026-11-09)

Implementation checkpoint6b9da6b (native92d0ba0, ownership45a4a20) on isolated `phase6-http`, based on79d142f.
[HTTP_ENGINEERING.md](HTTP_ENGINEERING.md) records the completed PHP83/84/85 epoll/poll tests,
scoped instrumentation and Gates A/B (9236 FPM responses/600.253s). Historical entries below retain their original scope.

| Item | State | Evidence / boundary |
|---|---|---|
| Multiple-file MIME | IMPLEMENTED_AND_TESTED | 1..16 sequential worker sources, shared64 KiB native slot; empty/duplicate/missing files, cancellation, TLS and FPM |
| Atomic streamed download | IMPLEMENTED_AND_TESTED | Pinned directory staging, optional file+directory fsync, prepublication failure preserves destination; committed-after-rename errors explicit |
| Curl lifecycle and configurable admission | IMPLEMENTED_AND_TESTED | Native completion avoids second body copy; bounded easy-handle cache; CURLM failure collection;8/16/32 limits; buffered wait correction |
| Reproducible HTTP source distribution | IMPLEMENTED_AND_TESTED | f818d29 /463 files; clean PHP83/84/85 builds and six backend combinations; phase6/http-package.json |
| Performance target4x curl_multi/3x Swoole | NOT_IMPLEMENTED | Matched repeated benchmarks are slower than both; no global ranking claim |
| Rust Hyper backend | NOT_IMPLEMENTED | Standalone experiment built/measured only; no PHP/FFI integration or production Rust dependency |
| Broad HTTP protocol/proxy certification | PARTIALLY_IMPLEMENTED | HTTP/1.1 streaming and local HTTP/HTTPS CONNECT tested; HTTP/2/3, streaming redirect replay, resumable/nested MIME and broad proxy-auth remain unverified/unavailable |

Release remains **EXPERIMENTAL**. No historical zero-tick attribution or
distribution-PHP memory discrepancy is declared resolved by this work.

## Resource ownership and provenance continuation (2026-11-07)

Runtime checkpoint `b7ea081`; diagnostic-output regression checkpoint `ce9b977`.
[RESOURCE_PROVENANCE_PROGRAM.md](RESOURCE_PROVENANCE_PROGRAM.md) is the current
engineering report; the older tables below retain historical source scope.
Release remains **EXPERIMENTAL**.

| Item | State | Evidence / boundary |
|---|---|---|
| Canonical owner copy policies | IMPLEMENTED_AND_TESTED | Owner/alias/value policies, PHP83/84/85 epoll/poll; duplicate TcpStream ownership rejected; live local database controls |
| Bounded opt-in numeric trace | IMPLEMENTED_AND_TESTED | Ring, overflow, producer contention, deferred output, controlled delay and diagnostic FPM; limited pre-RSHUTDOWN export |
| Large watcher index / native count validation | IMPLEMENTED_AND_TESTED | Matched real readiness benchmark, bounds negative control, allocation failure/recovery, sanitizer checks |
| Final source Gates A/B/C | IMPLEMENTED_AND_TESTED | A 161.403s; B1 350,004 waits/210,000 jobs; B2 9,376 responses/600.013s; C 27,636/1800.071s; independent review PASS, unchanged limits |
| Historical zero-tick attribution | BLOCKED | Original missing provenance cannot be recreated by new tracing |
| Distribution-PHP memory discrepancy | PARTIALLY_IMPLEMENTED | Matched PHP-only shutdown-cycle effect reproduced; richer workload retention differences remain unresolved, original losses retained |

## Native reliability continuation (2026-11-07)

Current native checkpoint: `9bee5c3eeb1ba89124bb35251d679bbda0b8b7f8`.
The [program report](NATIVE_RELIABILITY_PROGRAM.md) and
[ownership audit](NATIVE_OWNERSHIP_AUDIT.md) supersede the older engineering
status tables below for this isolated branch. Historical results retain their
original identities. Release remains **EXPERIMENTAL**.

| Item | Status | Executed evidence / limits |
|---|---|---|
| Native wait budget through setup | IMPLEMENTED_AND_TESTED | `ext/backend.c`; original extra 5ms reproduced, corrected absolute deadline covers preparation, rounding and failure cleanup; epoll/poll |
| SQLite result allocation failures | IMPLEMENTED_AND_TESTED | `ext/workers.c`; two original crashes and one incorrect success reproduced. Corrected TEXT/BLOB conversion retains UTF-8 and binary semantics; five native controls, UTF-16 regression |
| Deterministic production-path framework / Gate A | IMPLEMENTED_AND_TESTED | Final source PASS 200.729s; three seeds, both backends; real kernel waits separate from virtual PHP scheduler time |
| Gate B1 native volume | IMPLEMENTED_AND_TESTED | 350,004 real kernel wait calls plus 210,000 worker jobs; injected syscall failures excluded from wait count |
| Gate B2 four-worker FPM | IMPLEMENTED_AND_TESTED | 8,240 main responses over 600.019s; 6,180 I/O responses, minimum eight ticks; independent resource/lifecycle review PASS. This is the redesigned short gate, not completion of the old 200,000-response gate |
| Gate C extended candidate observation | IMPLEMENTED_AND_TESTED | PASS 2136.317s total, 1800.190s mixed FPM / 24,740 main responses; independent review PASS, original limits; not six-/24-hour endurance |
| Distribution-PHP shutdown allocation discrepancy | IMPLEMENTED_AND_TESTED | Independent PHP-only cyclic-object reproducer and PHP source explain DEBUG/non-DEBUG shutdown differences. Original raw Valgrind runs remain FAIL; separate explicit-GC controls pass. No runtime workaround or suppression |
| Focused ownership audit | PARTIALLY_IMPLEMENTED | Scoped native/PHP ownership matrix completed; remaining copying-policy, hard-shutdown and external allocator limits documented. Not universal certification |
| Historical zero-tick cause | BLOCKED | Original request lacks per-wait/dispatch provenance needed for attribution. Controlled interpreter-delay reproduces zero ticks; does not establish the original cause. Historical six-hour FAIL remains |

## Historical fast-r1 track (2026-11-07)

Native API First remains unchanged. [Fast reliability](FAST_RELIABILITY.md)
supersedes repeated multi-hour campaigns as the developer feedback strategy.
Original hour PASS and six-hour FAIL are preserved; historical zero-tick cause
remains unresolved. No 24-hour FPM result exists for that campaign.

| Item | Status | Evidence / limits |
|---|---|---|
| Accepted close cleanup (`6ee73d8`) | IMPLEMENTED_AND_TESTED | Native file/SQLite controls fail on `d74f3b3`, pass on candidate; ordinary cancellation retained. This supersedes the historical unbuilt entry below for this isolated branch only |
| Interrupted worker completion notification | IMPLEMENTED_AND_TESTED | Minimal C EINTR retry; 10,000 injected completion transitions/backend pass. Both prior sources fail. Not attribution of historical FPM zero ticks |
| Deterministic kernel/virtual-time framework | IMPLEMENTED_AND_TESTED | PHP 8.3/8.4/8.5 × epoll/poll × three source variants, 10,000 iterations; separate real kernel and virtual scheduler scope |
| Gate A expanded seed/resource suite | IMPLEMENTED_AND_TESTED | 190.920 seconds, 10,000 iterations/path, three seeds, epoll/poll; 140,000 total worker jobs and zero tracked owners at checkpoints |
| Gate B 200,000 original FPM main responses | PARTIALLY_IMPLEMENTED | Count runner and independent review executed: 7,980 responses in 600.027s, original assertions pass; 200,000 target NOT met (INCOMPLETE) |
| Gate C / extended observation | BLOCKED | Not run because B volume gate is incomplete; no automatic six-hour/24-hour run |
| Original zero-tick attribution / distribution-PHP Valgrind discrepancy | BLOCKED | Unresolved evidence; no claim that new notification fault injection explains either |

## Isolated native track (2026-11-07)

This branch starts from frozen `d74f3b3`; the Phase 4 tables below are inherited
checkpoint records, not a live report of the separate running campaign.

| Item | Status | Evidence / limits |
|---|---|---|
| Accepted file/SQLite close ownership under cancellation | IMPLEMENTED_BUT_NOT_FULLY_TESTED | `ext/workers.c` dispatch correction and deterministic native regression written; NOT_YET_BUILT / NOT_YET_TESTED while Phase 4 FPM is active. Static source analysis only; [engineering report](NATIVE_CORE_ENGINEERING.md) |
| Native close build, PHP 8.3/8.4/8.5 regressions and sanitizers | BLOCKED | Intentionally deferred by campaign resource/isolation policy, not an environment/compiler failure |

No Phase 4 result certifies this new native patch. No new hooks or backend are added.

Updated 2026-11-07 (Europe/Berlin). Status describes implemented behavior and executed evidence, not interface declarations. Release assessment after the independent audit: **EXPERIMENTAL**. The original archive is preserved with known defects; the working tree repairs seven high-severity issues. See [INDEPENDENT_AUDIT.md](INDEPENDENT_AUDIT.md) and its durable evidence. Broad production readiness is not complete.

## Native API lifecycle continuation

The official direction is [Native Async API First — Optional Transparent
Compatibility](NATIVE_API_FIRST.md). No new hooks, driver replacements or backend
claims are part of this continuation. [IO_LIFECYCLE_CONTRACT.md](IO_LIFECYCLE_CONTRACT.md)
documents eager submission, shared waiting versus owned cancellation, draining,
partial results, backpressure and request shutdown against existing source.

| Item | Status | Evidence / remaining work |
|---|---|---|
| Buffered HTTP cancellation ownership | IMPLEMENTED_AND_TESTED | `tests/io_lifecycle.php`: public Task and client permit now remain owned until native cancellation collection; prior implementation failed the completion-listener regression |
| SQLite connection copy protection | IMPLEMENTED_AND_TESTED | Same regression rejects cloning/serialization/unserialization of request-local native identity; original implementation allowed copies |
| Shared-waiter/file/SQLite lifecycle regressions | IMPLEMENTED_AND_TESTED | Six cases in `tests/io_lifecycle.php`, included in `scripts/test.sh`; epoll/poll PHP 8.5.12 and GCC ASan/UBSan pass |
| Local uninstrumented FPM cause and endurance | PARTIALLY_IMPLEMENTED | Original zero-tick failures remain unresolved. Controlled worker stops reproduce fewer-than-two ticks, not attribution of original failures. Original acceptance workload and thresholds remain unchanged |
| Comprehensive lifecycle certification | PARTIALLY_IMPLEMENTED | Existing functional suites pass; shared operation-wide cancellation authority, arbitrary shutdown deadlines, wider database/platform coverage and long local FPM acceptance remain limited |

Current executions and frozen-source acceptance state are recorded in
[PHASE4_DELIVERY.md](PHASE4_DELIVERY.md). Historical sections below retain the
source identities and scope of their original measurements.

| Area | Status | Evidence / remaining work |
|---|---|---|
| Environment, PHP internals and upstream research | IMPLEMENTED_AND_TESTED | RESEARCH.md, inspected source, RFC status checked |
| Native extension, direct build, phpize/configure | IMPLEMENTED_AND_TESTED | Loaded binaries; warning-free builds on PHP 8.3/8.4/8.5 NTS |
| Fiber scheduler / Tasks / Futures | IMPLEMENTED_AND_TESTED | FIFO batches, lifecycle/errors, 10,000-task workload |
| Timers, cancellation, deadlines | IMPLEMENTED_AND_TESTED | monotonic heap, races, cleanup, repeat/callLater APIs |
| Groups / Lock / Semaphore / Event / Queue | IMPLEMENTED_AND_TESTED | fairness, contention, deadlocks, cancellation handoffs |
| Explicit context / async producer streams | IMPLEMENTED_AND_TESTED | inheritance/isolation, backpressure, failures, early close |
| epoll / poll | IMPLEMENTED_AND_TESTED | complete core suite with each Linux backend |
| TCP / UDP / DNS / explicit TLS | PARTIALLY_IMPLEMENTED | tested local IPv4/IPv6 TCP/UDP, dual-address DNS, TLS1.2/1.3 SNI/ALPN, partial I/O and cancellation; WAN/TLS state edge cases remain |
| HTTP client | PARTIALLY_IMPLEMENTED | concurrent curl multi, pooling, HTTP(S), JSON/form, chunking/compression tested; bounded HTTP/1.1 response/file/iterable streaming and one-file curl MIME tested; broader protocol/upload matrix incomplete |
| Native filesystem | PARTIALLY_IMPLEMENTED | basic operations, stable handles, seek/sync, chunk backpressure, typed errno and atomic replacement tested; crash durability / broader filesystem certification incomplete |
| SQLite | IMPLEMENTED_AND_TESTED | worker C API, binds, ordering, transactions, cancellation and cleanup; buffered scope documented |
| PostgreSQL | PARTIALLY_IMPLEMENTED | native nonblocking libpq, binds, transactions, notifications tested locally; private-CA SCRAM/verify-full tested; server-side prepare cache and cancellation acknowledgment incomplete |
| MySQL / MariaDB | PARTIALLY_IMPLEMENTED | Connector/C tested separately on MariaDB 11.8.9 and Oracle MySQL 8.4.13; strict IP SAN TLS/auth; broader variants unverified |
| Redis | PARTIALLY_IMPLEMENTED | RESP2/RESP3 common types, pipeline, transactions, Pub/Sub tested; attributes/streaming aggregates/cluster support absent |
| Transparent hooks | PARTIALLY_IMPLEMENTED | sleep/usleep opt-in tested; signal semantics partial; all other groups explicitly unsupported |
| ProcessPool | IMPLEMENTED_AND_TESTED | registry, bounded framing/queue, distinct overlapping CPU processes, timeout/crash/replacement and close |
| Userland ThreadPool | BLOCKED | NTS and single-interpreter ZTS CLI tested; no safe interpreter-thread executor |
| io_uring filesystem | NOT_IMPLEMENTED | separate kernel NOP probe succeeded; no Asyncara operations integrated |
| kqueue / IOCP / non-Linux ports | NOT_IMPLEMENTED | fallback architecture exists; no platform certification |
| WebSocket | PARTIALLY_IMPLEMENTED | ws/wss client, raw TCP server upgrade, bounded framing/messages, controls, cancellation and independent Python17.4 interop tested; extensions/server-WSS/browser/long-run certification incomplete |
| Composer / PSR-18 and PSR-17 adapter | IMPLEMENTED_AND_TESTED | Composer validation; Nyholm PSR-7 integration case |
| Laravel / Symfony / WordPress / PSR-3 / alternate loops | NOT_IMPLEMENTED | integration guidance only, no framework lifecycle certification |
| Diagnostics | PARTIALLY_IMPLEMENTED | measured max task step/timer lag, live counts/memory; native aggregate allocation accounting; full traces and third-party memory accounting incomplete |
| Memory hardening | PARTIALLY_IMPLEMENTED | independent native and fully instrumented PHP ASan/UBSan, native TSan, debug PHP, allocation injection and fatal file/SQL cleanup; Valgrind clean in tested paths; bounded fuzzing and ZTS CLI tested; foreign allocator coverage incomplete; 109-byte upstream libpq TLS unload leak documented |
| Stress | PARTIALLY_IMPLEMENTED | 300 independent mixed cycles; FPM sequential, within-request and four-worker concurrency; 1,000/10,000 tasks and latency samples; extended soak incomplete |
| Benchmarks | PARTIALLY_IMPLEMENTED | raw three-sample HTTP/FPM/Swoole/native, AMP timers, file, SQLite, queue, CPU results; remaining workload matrix incomplete |
| Developer/API/installation/security documentation | IMPLEMENTED_AND_TESTED | executable examples and documented commands; docs distinguish unsupported features |

## Minimum meaningful release evidence

A compiled native extension; B Fiber scheduler; C TCP; D concurrent HTTP; E worker filesystem; F worker SQLite; G Tasks/Futures/cancellation/deadlines; H synchronization; I structured groups; J process CPU parallelism; K verified limited timer hooks; M automated integration tests; N reproducible local/phpize builds; O accurate documentation are present and tested. L is bounded evidence: no known critical invalid-memory defect in the tested paths, with clean instrumentation runs described in SECURITY.md; this is not a universal memory-safety guarantee.

## Phase 3 hardening checkpoints

| Work | Status | Evidence / boundary |
|---|---|---|
| P0 preserved baseline and seven audit negative controls | IMPLEMENTED_AND_TESTED | audit1 source match; original archive reproduces all seven failures; baseline commands pass; checkpoint b0dfa41 |
| CI regression and database lifecycle workflows | IMPLEMENTED_AND_TESTED | all four hosted jobs passed at final native source e5d94fd: PHP 8.3/8.4/8.5 regression and database lifecycle; [run 41549782614](https://github.com/asyncara/asyncara/actions/runs/41549782614); scheduled endurance is separate |
| P1 admission and buffer limits | IMPLEMENTED_AND_TESTED | resource_bounds.php, database/resource_bounds.php; 8.3/8.4/8.5 and instrumented core; notification overflow/fatal cleanup Valgrind; see RESOURCE_LIMITS.md |
| P2 protocol/allocation fuzzing | IMPLEMENTED_AND_TESTED | committed seeds, 100 RESP cases, real IPC failures, 326,134 native ASan/UBSan fuzz inputs and 12,737 instrumented PHP mutations; bounded coverage in FUZZING.md |
| P3 frozen mixed soak harness | PARTIALLY_IMPLEMENTED | earlier baseline 20,855 cycles and HTTP-streaming checkpoint 19,761 cycles each ran one hour; final e5d94fd passed 300.073s / 1,331 cycles, RSS endpoint +200KiB, flat fds/threads; Phase 4 1h attempt failed at an unattributed timeout; 6h/24h not started; SOAK_TESTING.md |
| P4 expanded FPM lifecycle | PARTIALLY_IMPLEMENTED | sequential/within-request/multi-worker/streaming concurrency, recycling/reload/kill/timeout/fatal/pending isolation; historical RSS failures repaired with dedicated mappings, three unchanged five-minute runs passed; final e5d94fd: 3,756 requests / 300.106s, maximum 2,888KiB beyond warmup; endurance incomplete; FPM_LIFECYCLE.md |
| P5 database TLS/auth and protocol repairs | PARTIALLY_IMPLEMENTED | Private-CA matrix, Oracle 8.4.13, certificate identity fix, nonblocking transaction control and buffered TLS wakeups; WAN/mTLS/cancel acknowledgement remain unverified |
| P6 ZTS investigation | PARTIALLY_IMPLEMENTED | PHP 8.5.11 ZTS DEBUG CLI full tests and database suite pass; concurrent interpreter threads unverified, ThreadPool BLOCKED |
| Third-party aggregate memory accounting | PARTIALLY_IMPLEMENTED | SQLite value limits; other foreign allocators remain outside native ledger |

## Phase 4 verified endurance checkpoint

| Work | Status | Executed scope / remaining work |
|---|---|---|
| UDP endpoint ownership, descriptor exhaustion and destination validation repairs | IMPLEMENTED_AND_TESTED | f2abb55; 8.3/8.4/8.5, epoll/poll, DEBUG, GCC ASan/UBSan and Valgrind regressions |
| Reproducible r4 distribution | IMPLEMENTED_AND_TESTED | 355 Git-matching source files; isolated three-version installations/tests; package-r4.json |
| Frozen 1h / 6h / 24h mixed endurance | IMPLEMENTED_AND_TESTED | same f2abb55 source/binary, Linux PHP8.5.12 NTS epoll; 8,488 / 69,836 / 294,899 cycles; explicit local database/network scope in endurance-r4.json |
| Frozen hosted 1h mixed and FPM | IMPLEMENTED_AND_TESTED | r4 archive byte identity and official artifact digests; FPM 67,824 requests plus lifecycle/fault cases; hosted-r4.json |
| Local FPM and 6h / 24h FPM endurance | PARTIALLY_IMPLEMENTED | local attempts failed responsiveness at364.506s and998.680s; six-/24-hour stages unstarted; all original assertions retained |
| Scheduling observer and independent wait controls | IMPLEMENTED_AND_TESTED | reproduced zombie-exit ownership error repaired; live identity/cgroup ancestry tests; epoll/poll/nanosleep controls and opt-in combined native-call/syscall diagnostics; these do not certify FPM endurance |
| Direct libc wait probe and bounded interval analysis | IMPLEMENTED_AND_TESTED | 180s/900s FPM diagnostics and 60s full lifecycle smoke; native forwarding/thread/errno/fd controls, scoped ASan/UBSan/TSan/static analysis; instrumentation only, not a production hook |
| Diagnostic logging failure propagation | IMPLEMENTED_AND_TESTED | negative controls reproduce ignored PHP write failures and accepted instrumentation errors; PHP8.3/8.4/8.5 and seven analyzer tests pass; historical logs rechecked; changed profiler not yet run in FPM |

Mixed 24-hour steady RSS endpoint change is +260KiB, with zero final scheduled
work. Recorded maximum timer lag exceeds one second; this is no latency SLA.
The independent review recalculates trends from raw samples and preserves all
historical failures. The native/public source and r4 archive remain unchanged.
No new protocol, database TLS, hook, platform or framework capability follows
from these scoped endurance passes. Overall release remains **EXPERIMENTAL**.

## Next engineering work, in order

Diagnose the failed FPM-only campaign at
`/tmp/asyncara-phase4-nrtjh3t1/campaign-f2abb55-fpm-solo-r1/state.json` using the
preserved failure and bounded diagnostics. Native/public runtime source is
unchanged; a host wait-control delay alone does not establish a runtime defect
or exonerate the failed request. The observer repair and combined tracing do not
weaken any workload assertion. Actual source identities, measurements, dependency
findings and prior attempts are in [PHASE4_DELIVERY.md](PHASE4_DELIVERY.md) and
WORKLOG.md. The latest67e4530 hosted mixed/FPM hour and all four CI jobs also pass;
native/public runtime is unchanged from r4. No uninstrumented local retry is
currently running. Use the verified direct probe for the next full lifecycle
diagnostic; keep its instrumentation scope separate from endurance certification.
That one-hour diagnostic started November6 at17:49:09Z on frozen f2abb55 with
tools7e104c0. Observe `fpm-full-wait-probe-r2/state.json` under the existing evidence
root; its launch attestation is in phase4/fpm-wait-hour-launch.json. No completed
hour or automatic six-/24-hour advancement is claimed.
The later profiler-write repair does not modify that campaign's private tools;
check its completed logs with the hardened analyzer before accepting measurement
evidence. See phase4/profile-log-errors.json for the reproduced tool failures.
The detached `fpm-full-wait-probe-r2-review/state.json` checker waits for that
terminal result, verifies the old warning paths, and queues one60s full lifecycle
smoke of the repaired profiler only after an instrumented-hour PASS. Its exact
code is retained in phase4/profile-log-review-launch.json. No longer stage starts.

1. Complete the frozen FPM-only one-/six-/24-hour chain without weakening checks; hosted one-hour success and mixed 24-hour success remain separate evidence. The four hosted regression/database jobs are passing; scheduled endurance is separate. Repeated libpq unloads accumulate allocations; resident connection controls do not. Follow up the unresolved upstream lifetime issue. Expand remote SQL auth/TLS and acknowledged cancellation tests; keep native and fully instrumented PHP regressions. Review remaining third-party memory/process quotas.
2. Broaden atomic-file ACL/mount/race and crash-durability testing. io_uring file operations remain optional and require complete completion/cancellation/fallback tests; preserve the worker fallback.
3. Extend HTTP streaming/multipart certification and multiple-file uploads, WebSocket endurance/interoperability coverage, IPv6/TLS edge cases, and database remote auth/TLS/cancellation matrices.
4. Extend individual transparent hooks with exact parameter/warning/resource/return/signal behavior tests; keep unsupported groups explicit.
5. Add common DB manual transaction APIs where missing, server-side prepared caches, richer Redis types and safe notification/pipeline policies.
6. Add framework/PSR-3/loop adapters and production telemetry, then certify supported OS/PHP/SAPI combinations. Only after a stable boundary consider ZTS interpreter-thread execution.

Continuation commands: `make audit`, `make audit-fpm`, `./scripts/audit-allocations.sh`, `make verify`, `ASYNCARA_BACKEND=poll ./scripts/test.sh`, `./scripts/phpize-local.sh`, `./benchmarks/run.py`. Consult WORKLOG.md and build logs before changing a status. All source and scripts are in the repository; external dependencies/build products are isolated and ignored.

### HTTP public-path performance continuation (2026-11-09)

PARTIALLY_IMPLEMENTED: buffered completion ownership and native conversion/wait
optimizations pass the current HTTP gate and Gate A. Final matrix, memory and FPM
validation remain pending. The public 13,000 requests/s and relative 4x/3x targets
are NOT_MET; see [HTTP_PERFORMANCE_WORK.md](HTTP_PERFORMANCE_WORK.md). Hyper remains
an evaluation until a real PHP integration demonstrates an advantage.


### HTTP performance continuation — 2026-11-09

- Buffered owner/wait observer/metadata/dispatch improvements: IMPLEMENTED_AND_TESTED
  in scoped HTTP suites, PHP 8.3/8.4/8.5 epoll/poll, full8.5 and Gate A.
- Optional Hyper backend: PARTIALLY_IMPLEMENTED. Numeric HTTP/1.1 buffered GET
  passes targeted lifecycle/cancellation/bounds/FPM tests. It is not default and
  does not implement TLS, DNS, proxies, general methods or streaming. Curl retains
  these existing capabilities.
- Performance acceptance: NOT_MET. One scoped median exceeds13000/s; later
  repetitions are slower. 4x curl_multi /3x Swoole is not demonstrated.
- Additional HTTP FPM harness barrier corrected with negative control; the retained
  pre-correction assertion failure and historical Phase4 zero-tick attribution
  remain unresolved. See HTTP_PERFORMANCE_WORK.md and HYPER_EXPERIMENT.md.

### HTTP wait-cost checkpoint c4aea94 (2026-11-09)

C curl interest preparation now scans socket/job sets linearly when no stream is
paused. Native negative/positive pause controls and real1024-operation wait-cost
measurements retain cancellation and backpressure. This does not establish the
public HTTP speed target; the matching reference comparisons remain NOT_MET.
Latest-source matrix/memory/FPM results are recorded in HTTP_PERFORMANCE_WORK.md
and phase6/http-current-evidence.json. Older checkpoint results remain historical.

At c4aea94, the wait-interest optimization is IMPLEMENTED_AND_TESTED in the
scoped native/HTTP PHP83/84/85 matrix, full85 suites, memory checks, Gate A and
short FPM fixtures. This status applies to that change, not universal HTTP
certification. Hyper remains PARTIALLY_IMPLEMENTED and performance NOT_MET.
