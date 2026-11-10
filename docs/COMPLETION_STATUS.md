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
| Server | Duplex request/response streaming; HTTP/2/3; WebSocket upgrade integration; deployment/endurance certification; all-cases Go performance target |
| Hyper client | Redirects and proxies; buffered gzip/deflate is implemented and tested (HYPER_COMPRESSION.md); Brotli/zstd and composite codings remain unavailable; bounded response/upload streaming is now implemented and tested (HTTP_STREAMING.md); bounded methods/headers/bodies are implemented and tested in the documented functional/lifecycle scope (HYPER_REQUESTS.md); default curl remains the comprehensive client |
| WebSocket | Server-WSS, browser/Autobahn conformance and extensions are absent or unverified; existing independent Python client/server tests retained |
| PostgreSQL | Opt-in bounded prepared-statement caching is implemented and tested (POSTGRESQL_PREPARED.md); remote cancellation acknowledgement and broader remote compatibility remain open; fatal-bailout memory finding is separately retained |
| MySQL/MariaDB, Redis | Broader independent server/auth matrices; RESP attributes/streaming aggregates/Cluster/Sentinel remain limited or absent |
| Filesystem/network certification | Wider filesystem crash-durability, WAN/TLS deployment cases and platform certification need executable evidence |
| Memory/resource certification | Foreign allocations are outside the native ledger; historical distribution-PHP/Valgrind and libcurl findings retain their scope |
| FPM/history/performance | Original six-hour zero-tick FAIL remains; new short tests do not establish its exact cause or universal throughput |
| Hooks/ZTS/platforms | Existing optional timer hooks retained. Universal hooks, userland ThreadPool and untested platforms are not made complete by changing labels |

No claim that every PARTIALLY_IMPLEMENTED item is complete. Incoming streaming
has the scoped implementation below; broader protocol, database and certification
gaps remain above. Historical labels are not retroactively rewritten.

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

## Incoming request streaming continuation

Base `2b04c7b`, new raw root `/home/coder/Asyncara-request-stream-20261110T030416Z`.
Opt-in request input is IMPLEMENTED_AND_TESTED in the documented half-duplex
scope. The native service owns Incoming, emits one <=64KiB demanded chunk, applies
bodyBytes even to chunked input, and closes unread/error framing on early reply.
Default buffered APIs are preserved. RequestBodyStream rejects copy/serialization
and foreign-Task consumption/close; close releases its Task and Inbox references.
The retained-Inbox negative control fails before that correction and passes after.

Validation is **qualified**, not a universal clean matrix: matrix-r1 completed
75 commands,74 PASS and1 FAIL. Normal PHP83/84/85, DEBUG and GCC epoll cases pass;
the last GCC/poll/TLS8MiB slow-consumer case reset the connection. No ASan/UBSan
error was recorded. Four repetitions with the original3s deadline pass, with
slow transfers2.437..2.873s. Deadline sensitivity is a hypothesis; the exact first
reset cause was not recorded, so the original failure remains unresolved.
No assertions, limits or timeouts were relaxed.

regressions-r1 passes15 commands: Gate A191.178s, complete PHP85 epoll/poll suites,
Rust7 units, allocations, body ownership and eight four-worker plain/TLS
normal/forced-exit cases, each with128 streamed uploads. memory-r1 passes actual
TLS streamed-input/response DEBUG Valgrind3.22 controls on both backends: zero
errors and zero definite/indirect/possible loss,5306bytes reachable, no suppression.
[Exact commands, fingerprints and failures](phase7/server-request-stream.json).
Neither these tests nor server-r2 certify broader deployment or old FPM failures.

## Verified request-stream source package

Source: `f9c6f96b9f94a39cb4db834bd9be4640eb112fdc`. Archive: `build/asyncara-0.1.0-dev-server-r3.tar.gz`,
581files, SHA256 `7d4ac74d7ee36c8d39ac620312d0a3aaaa69f17b8a36789e163a71660146e17c`.
A second archive is byte-identical; all files match the source Git tree. A fresh
extraction, fresh Cargo target and native build pass17 independent checks,
including input/output streaming, TLS, protocol, API and body ownership on
both backends. [Attestation](phase7/server-r3-package.json).
Independent module SHA256: `2afda8b4316a4191ad8b9ec2d450986e854921ab2032e6d654afa4f146b2374a`.
The earlier ASan slow-input failure remains qualified above; packaging does not
erase it. No release publication or new performance acceptance.

## Hyper verified HTTPS continuation

Verified TLS1.2/1.3 GET, bounded worker-loaded trust snapshots, pool isolation and
connect deadlines are implemented and functionally tested on PHP83/84/85, DEBUG
and GCC ASan/UBSan with epoll/poll. Matrix-r2 passes85 commands. The shared TLS
read adapter now coalesces immediately available plaintext within existing bounds;
the measured prior3s slow-input deadline failure is retained. Gate A passes
193.188s. Broader regression, memory and independent-package checks are recorded
in [HYPER_TLS.md](HYPER_TLS.md); this does not complete all Hyper protocol modes.

## Independently verified source package

Source: `8e67aebb3aa6cc2e4b346cf8004cfd88475c540a`. Archive: `build/asyncara-0.1.0-dev-server-r4.tar.gz`,
590 files, SHA256 `5592b3e7b37cbcb0aae6373710489d0b743e91457eee324ecaa01f35c05594f5`.
Two builds of the archive are byte-identical; every entry matches the Git tree.
A fresh extraction, fresh Cargo target and native build pass21 checks covering
server protocol, TLS, input/output streaming, ownership, and client verified TLS
and lifetime controls on epoll/poll. Independent module SHA256:
`9e0621ad0d9f66cdfb2e2394f4187cbe9a63a600a61e4b402f895c277daa6362`.
[Independent attestation](phase7/server-r4-package.json). No GitHub release or
broad performance/endurance certification. Original server-r1/r2/r3 and Phase4
checkout/module/LICENSE remain unchanged.

## Independent buffered-request package

Source: `d0c270a538c6d638149e4188f079dc3c2ae575bd`. Archive: `build/asyncara-0.1.0-dev-server-r5.tar.gz`,
598 files, SHA256 `721fa8b8e6139d56ba79b8496dac69dba5102964c3f78f3886f719e48c3438b0`.
Two archives are byte-identical and every entry matches the source commit. Fresh
extraction, fresh Cargo target and native build pass23 commands: server protocol,
API, TLS, input/output streaming, ownership and client TLS/buffered requests on
epoll/poll. Independent module SHA256:
`e55adac75d0798a5408a080b926bffa22352876ea2860f858c136bd4574b5ca7`.
[Build attestation](phase7/server-r5-package.json). No release/push or universal
performance/endurance certification. Historical archives remain unchanged.

## Independently verified Hyper streaming package

Packaged source `260f67dcc950e70c3bba4dc4763130d607bf63e3`, native checkpoint
`a0e3098be1fe8743a6e6b12dd9a8de048b7de513`. Archive
`build/asyncara-0.1.0-dev-hyper-stream-r1.tar.gz` contains614 files; SHA256
`109e754410e3b85c89c48283507d7f73c001c9ecbb62926fae2f81baade9f692`.
A second archive is byte-identical and every file matches the packaged Git tree.
Fresh extraction, fresh Cargo target and native build pass15 commands: build
plus client streaming/bounds/TLS/requests, server protocol/TLS and mixed-stream
FPM on both backends. Independent module SHA256
`38f7bf28c5815381ac436e20599e7da80aaaa1dab5d367ccb55067d26cbee24f`.
[Attestation](phase7/hyper-stream-package.json). No release publication or broad
protocol/performance/endurance certification. Historical failures remain intact.
