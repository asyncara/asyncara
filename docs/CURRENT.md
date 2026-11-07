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
