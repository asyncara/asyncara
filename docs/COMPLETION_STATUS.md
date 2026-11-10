# Current partial-work inventory and completion record

Baseline: `08f2258b7638d0b7911b45a1eccc072b821c2efc`, branch
`phase7-native-server-20261109T231953Z`. Release remains EXPERIMENTAL.

The request to complete PARTIALLY_IMPLEMENTED was checked against source, not a
text replacement. ROADMAP contains chronological checkpoint tables: old DNS,
MIME, ownership and diagnostics entries must not be relabeled as if their old
archives contained later work. The current source already implements DNS for
Hyper, multiple-file curl MIME, atomic downloads, copy protections and bounded
tracing. Their historical labels are preserved.

## Current engineering slice

| Capability | State | Evidence / boundary |
|---|---|---|
| HTTP server HTTPS TLS1.2/1.3, optional mandatory client certificates | IMPLEMENTED_AND_TESTED (functional scope) | Private-CA trust/hostname/mTLS controls, idle handshake progress, bulk I/O, four workers; AWS-LC provider passes scoped DEBUG Valgrind; historical ring finding retained separately |
| HTTP server response streaming | IMPLEMENTED_AND_TESTED | Demand-driven 1..64KiB chunks; 16MiB transfer through 256KiB payload budget; TLS/plain, early delivery, two ticks before peer release, slow consumer and cancellation controls |
| Correct abort versus timeout counters | IMPLEMENTED_AND_TESTED | Regression initially counted 5 aborts as deadlines in 1.308s; separate Peer abort state passes unchanged 3s deadline controls |
| HEAD/header-rejection producer ownership | IMPLEMENTED_AND_TESTED | Eager ReadableStream now cancelled and joined even when its body is never iterated; failing-before case preserved |
| TLS private-key exception arguments | IMPLEMENTED_AND_TESTED | SensitiveParameter on native key argument; negative control exposes synthetic key before fix; PHP83/84/85/DEBUG/GCC controls redact it after fix |
| Optional phpize Hyper build | IMPLEMENTED_AND_TESTED | Isolated phpize/configure/make build with --with-asyncara-hyper, ordinary protocol/TLS/stream tests on both backends |

## Validation record

Raw output root: `/home/coder/Asyncara-completion-20261110T013405Z`.
`matrix-r4/report.json` passes 55 build/test commands: PHP 8.3.36, 8.4.27, 8.5.12,
8.5.12 DEBUG and 8.5.12 GCC ASan/UBSan, each epoll and poll. This is local Linux
HTTP/1 coverage, not internet/proxy or HTTP/2/3 certification. Rust/AWS-LC release
code is not compiler-instrumented by the C/PHP GCC sanitizer build.
`regressions-r3/report.json` passes 15 commands including Gate A 277.158s,
full PHP85 epoll/poll suites, four-worker plain/TLS normal and forced-worker-exit
controls, body retention, allocation audit and seven Rust units.
`http-r4/report.json` passes 74 commands in 120.022s,
including the existing curl and Hyper client regressions on both backends.
`phpize-r3/report.json` passes 10 isolated standard build/protocol/TLS/stream checks.
All these final results include the AWS-LC provider, native key redaction,
abort-counter correction and eager-stream cleanup. Earlier r1/r2/r3 matrix and
provider results retain their exact historical scope.
The earlier phpize-r1 environment selected an extracted static SQLite library
without its math dependencies; subsequent isolated builds use private symlinks
to host shared libraries without changing project checks or global configuration.
Commands, source/module fingerprints and preserved failure hashes are in
[durable evidence](phase7/server-completion.json).

Memory validation distinguishes providers. Initial rustls0.23.46/ring0.17.14
AES-GCM runs remain **FAIL / attribution unresolved**, independently reproduced
without PHP, Asyncara, Hyper or a TLS handshake on Valgrind3.22 and3.28.
The new server uses rustls with AWS-LC after matched independent AES128/AES256/
ChaCha controls passed. `memory-aws-r1/report.json` passes the actual DEBUG PHP
TLS echo/stream/close workload on epoll/poll under unsuppressed Valgrind 3.22:
zero errors and zero definitely/indirectly/possibly lost bytes; 5,306 bytes remain
reachable. This is scoped validation, not a universal memory-safety guarantee.
See [dependency findings](DEPENDENCY_FINDINGS.md) for preserved failures and the
provider decision. Historical PHP/libpq/libcurl findings remain separate.

## Still open; not silently promoted

| Area | Actual remaining scope |
|---|---|
| Server | Streaming request bodies; HTTP/2/3; WebSocket upgrade integration; deployment/endurance certification; all-cases Go performance target |
| Hyper client | HTTPS, decompression, general request methods/headers, redirects, proxies and streaming; default curl remains the comprehensive client |
| WebSocket | Server-WSS, browser/Autobahn conformance and extensions are absent or unverified; existing independent Python client/server tests retained |
| PostgreSQL | Prepared-statement caching, remote cancellation acknowledgement and broader remote compatibility |
| MySQL/MariaDB, Redis | Broader independent server/auth matrices; RESP attributes/streaming aggregates/Cluster/Sentinel remain limited or absent |
| Filesystem/network certification | Wider filesystem crash-durability, WAN/TLS deployment cases and platform certification need executable evidence |
| Memory/resource certification | Foreign allocations are outside the native ledger; historical distribution-PHP/Valgrind and libcurl findings retain their scope |
| FPM/history/performance | Original six-hour zero-tick FAIL remains; new short tests do not establish its exact cause or universal throughput |
| Hooks/ZTS/platforms | Existing optional timer hooks retained. Universal hooks, userland ThreadPool and untested platforms are not made complete by changing labels |

No claim that every PARTIALLY_IMPLEMENTED item is complete. The next source
priority is incoming body streaming with the same bounded ownership rules, before
adding multiplexed protocols. Wider database and certification gaps remain above.

## Verified source distribution

Implemented/tested/packaged source: `3991adc2b02b46f1525495cefde9c743d9c4e7a3`.
Archive: `build/asyncara-0.1.0-dev-server-r2.tar.gz`, 575 files.
SHA256: `d06985c353f62a767d33e094baa57508a9b214c692836b23b59cdf93d7be76bd`.
A second archive is byte-identical; every entry matches the Git tree. Clean
extraction, a fresh Cargo target and native build pass 13 commands: PHP85
protocol, API, body lifetime, TLS and plain/TLS streaming on epoll/poll.
[Independent attestation](phase7/server-r2-package.json). The follow-up evidence
commit changes documentation only; it is not a different native runtime.
No GitHub release, performance acceptance or broad production certification.
