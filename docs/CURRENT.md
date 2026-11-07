# Current isolated native development state

This file describes the Phase5 worktree, not the preserved Phase4 checkout.
The authoritative current report is
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
