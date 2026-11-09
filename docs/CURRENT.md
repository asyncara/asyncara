# Current isolated HTTP development state

Latest native checkpoint: `c4aea948233d79aee887c9ceeea4b56da340cc57` on
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
