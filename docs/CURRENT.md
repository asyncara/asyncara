# Current isolated HTTP development state

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

The isolated branch now implements bounded opt-in server preparation through
nonblocking libpq. Default behavior remains unchanged. The connection owns its
LRU and serializes eviction, preparation, draining and execution; waiter-only
cancellation remains distinct from operation cancellation. The PHP 8.3/8.4/8.5,
DEBUG and GCC epoll/poll matrix, Gate A, full PHP 8.5 suites, database regressions
and short four-worker FPM integration pass in their stated scope.

Fatal-bailout Valgrind remains FAIL. PHP-only controls reproduce loss, and matched
cache-enabled/disabled/previous-source controls report identical lost bytes.
Normal execution, pending exit and the scoped OOM cleanup checks pass. No
universal clean-memory or endurance result is claimed. See
[POSTGRESQL_PREPARED.md](POSTGRESQL_PREPARED.md) and its durable evidence. This
does not complete all project PARTIALLY_IMPLEMENTED entries or the performance
target; the release remains EXPERIMENTAL.

## Hyper buffered request continuation (2026-11-10)

The isolated server branch now implements bounded HTTP(S) request methods,
ordinary headers, JSON/form and binary bodies through the existing HttpClient
API. Closing responses dispose unfinished uploads; keep-alive completion retains
upload ownership until the local transport flush. Shared-waiter cancellation is
distinct from owner cancellation. [HYPER_REQUESTS.md](HYPER_REQUESTS.md) records
the reproduced defects, corrections, preserved failures and validation scope.
Final functional/lifecycle validation passes on both backends, including the
95-command compatibility matrix, HTTP74, short real FPM POST checks and scoped
DEBUG Valgrind. The compilation-watchdog failure remains recorded separately;
independent packaging and performance have separate identities.
This does not complete all project PARTIALLY_IMPLEMENTED entries.

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
Latest native checkpoint: `84e49f137396c58e0954f2ba90dc8dd61df2615e`, branch
`phase6-http`. HEAD uploads and nonempty HEAD content now fail before transport
or producer execution; null/empty HEAD remains bodyless and reusable. The
old binary fails the new regression. PHP8.3/8.4/8.5, DEBUG and GCC HTTP suites
pass on both backends, alongside full8.5 suites, Gate A and scoped Valgrind.
Short curl/Hyper FPM fixtures pass. A later HEAD-specific feature FPM repetition
also passes60 main requests with rejection, bodyless framing and request reset;
neither replaces B2. [HTTP_HEAD_SAFETY.md](HTTP_HEAD_SAFETY.md) records exact
identities, test evidence and retained harness failures. No speed or release
promotion follows from this framing correction.

Latest five-sample public Hyper medians are4997.13/s (1KiB) and3550.73/s (64KiB)
using poll/four nginx workers. Minimum rates and4x/3x comparisons all fail the
requested targets; [HTTP_PERFORMANCE_WORK.md](HTTP_PERFORMANCE_WORK.md) records
both client backends and matching reference builds. Historical numbers below
are not results of this newer configuration.

Archive `build/asyncara-0.1.0-dev-http-r4-head.tar.gz` packages source `b04aa98`
with unchanged native source84e49f1:495 files, SHA-256
`12bc9fffeb1803b9deab72ab669323e4ad521bb9c4590ff2d4cdb27d0dc52051`.
Fresh PHP83/84/85 builds/HTTP gates, optional Hyper85 and HEAD FPM pass.
[Attestation](phase6/http-r4-head-package.json) records exact module hashes.
The r3 attempt remains a FAIL because its FPM report required Git after passing
functional cases; r4 fixes only that reporting assumption and its work-log entry.
Neither archive is a published or accepted reliability/performance release.

Previous native checkpoint: `09b47672644daeae8157b90a47116c0b92776b57` on
`phase6-http`. Request-local epoll reuse validates every descriptor through
MOD and rebuilds on identity/vector changes. Exact fd cleanup, failure recovery,
Gate A, PHP83/84/85 HTTP checks, full85 epoll/poll suites, scoped GCC/DEBUG/native
memory checks and short curl/Hyper FPM tests pass. Gate B now FAILS: B1
passes, but B2 stops after 5,236 recorded responses with one ticker increment
instead of the required two. A 333ms Task step is under investigation. [HTTP_EPOLL_REUSE.md](HTTP_EPOLL_REUSE.md) preserves the initial
candidate fd-lifetime failure and the corrected result. Its measured wait-only
CPU reduction does not meet the public HTTP speed requirements: latest1KiB
median4714.42/s,64KiB3062.36/s; all requested performance gates remain NOT_MET.
The r2 archive below contains the earlier c4aea94 native source, not this change.

Previous native checkpoint: `c4aea948233d79aee887c9ceeea4b56da340cc57` on
`phase6-http`. Buffered HTTP admission/dispatch improvements and a bounded opt-in
Hyper prototype follow the initial checkpoint described below. The default curl
backend retains TLS/DNS/streaming/uploads; Hyper currently supports only numeric
plaintext buffered GET. The PHP patch SDK guard rejects incompatible module loads.
The matching 8.5.12 Gate A and 600-second four-worker FPM B2 pass, with independent
review. Performance acceptance remains **NOT_MET**. Rejected native-Future and
hook-cost experiments remain on separate branches, without changing this runtime.
Earlier benchmark reference extensions had foreign patch build identities;
matching reference builds and comparisons are complete and still fail the speed
targets. The latest C change removes repeated unpaused socket/job scanning; its
wait-cost improvement does not establish a public-client throughput gain.
The d57d088 FPM evidence remains tied to its original module; new-checkpoint
validation now passes PHP83/84/85 HTTP suites, full85 epoll/poll, scoped memory
checks, Gate A and separate short curl/Hyper FPM fixtures. It is recorded
separately from the historical B2. See
[HTTP_PERFORMANCE_WORK.md](HTTP_PERFORMANCE_WORK.md) and
[HYPER_EXPERIMENT.md](HYPER_EXPERIMENT.md) for exact evidence and limitations.

Current independently validated distribution: `asyncara-0.1.0-dev-http-r2.tar.gz`,
486 files, packaged source ba9200f, native source c4aea94. See the
[package attestation](phase6/http-r2-package.json). It remains a local artifact;
no release or performance promotion follows from a reproducible build.

## Initial HTTP checkpoint (historical)

The active HTTP worktree is `/home/coder/Asyncara-http-20261109T050419Z`, branch
`phase6-http`, based on79d142f. Native checkpoint92d0ba0 plus the45a4a20 acquisition repair (tests6b9da6b) add bounded multiple-file
MIME, atomic downloads, connect/connection controls, native buffer ownership and
curl lifecycle repairs. [HTTP_ENGINEERING.md](HTTP_ENGINEERING.md) records actual
coverage, performance shortfall and the separate Rust Hyper experiment. Final
HTTP/memory/PHP83-85 validation and Gates A/B pass on6b9da6b; B2 records9236
responses in600.253s. Packaging has a separate attestation. No production-ready
or fastest-PHP claim.

## Inherited native resource-provenance checkpoint

The remainder describes the preserved Phase5 baseline, not the HTTP checkpoint
or the preserved Phase4 checkout.
Its engineering report is
[RESOURCE_PROVENANCE_PROGRAM.md](RESOURCE_PROVENANCE_PROGRAM.md), with the
[ownership matrix](NATIVE_OWNERSHIP_AUDIT.md) and
[lifecycle contract](IO_LIFECYCLE_CONTRACT.md).

Architecture remains **Native Async API First — Optional Transparent
Compatibility**. Native clients own HTTP, SQL/Redis, filesystem and networking
APIs independently of PHP function hooks. HTTP uses curl multi; PostgreSQL uses
nonblocking libpq; MySQL/MariaDB use Connector/C asynchronous states; Redis and
TCP/UDP use readiness waits; filesystem/SQLite/DNS use native workers; ProcessPool
uses processes. Fibers, scheduling and the timer heap are PHP-owned, not a C timer
heap. No new hooks, backend or protocol was added by this continuation.

Native code now adds a request-local bounded numeric trace and large-set watcher
indexing, with early native count validation. PHP owners reject unsafe copying;
selected descriptors/adapters retain tested alias/value-copy semantics. Trace
capture is disabled by default. Explicit extraction may block after execution;
capture can lose events visibly and is not a post-crash recorder.

Current runtime is `b7ea081` on `phase5-fast-reliability`; `ce9b977` adds only
an output-failure test. The report records subsequent documentation/package
identities separately. Gates A/B1/B2/C passed on this runtime, followed by
independent resource/log review. Package validation has a separate attestation.
Historical hour PASS / six-hour FAIL /24-hour NOT_STARTED remain tied to their
original source. The zero-tick cause remains unresolved. Distribution PHP still
reports shutdown-cycle-related losses; clean scoped native/DEBUG runs do not
prove universal memory safety. Release: **EXPERIMENTAL**.

Package source `63a3a496b7892a7d0e699517a05fa394ae6ebbc9` contains435 tracked files; archive
`build/asyncara-0.1.0-dev-resource-provenance-r1.tar.gz`, SHA-256
`d3cf2f58cebfcef6e4a0148d0c2b7a6f9dc5d20a6d0819a44b197ff16ea2f176`. Independent PHP83/84/85 builds,
six-combination critical tests and full85 epoll/poll pass. See the
[package attestation](phase5/resource-provenance-package.json). Final Git
attestation commits are separate from this archive source; no release was published.

## HTTP package identity

Source `f818d2975ef80d8188f751d07eb4243e5395263c`,463 files,
`build/asyncara-0.1.0-dev-http-r1.tar.gz`, SHA-256
`d811baa43e71ca62df128c0626d9e115341b041191c64cd4011e088787cd20f0`.
Reproducible and independently built/tested on PHP83/84/85, epoll/poll.
[HTTP package attestation](phase6/http-package.json) distinguishes native source92d0ba0,
tested runtime6b9da6b, packaged sourcef818d29 and later documentation. No GitHub
release publication; measured performance target remains unmet.
