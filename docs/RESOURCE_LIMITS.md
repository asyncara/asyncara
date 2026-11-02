# Resource boundaries

Phase 3 retains the existing backends. Limits below are admission/conversion
budgets, not a promise that RSS equals the accounted byte count. Capacity failure
is an exception; applications should stop submitting work or reduce concurrency.

| Resource | Default bound | Policy |
|---|---|---|
| Tasks plus retained unobserved task failures | 20,000 per run (`taskLimit`) | REJECT_NEW_ITEM; observing failures releases admission capacity |
| Pending Futures, including Tasks | 40,000 per PHP request (`futureLimit`) | FAIL_WITH_CAPACITY_EXCEPTION before native submission; weak accounting does not keep abandoned Futures alive |
| Future completion listeners | 65,536 per Future | FAIL_WITH_CAPACITY_EXCEPTION |
| Timers | 100,000 live timers | REJECT_NEW_ITEM; cancelled heap entries are compacted |
| Native jobs / descriptor registrations | 1,024 / 16,384 | REJECT_NEW_ITEM |
| Asyncara native allocations | 256 MiB (`nativeMemoryLimit`) | Reject allocation; atomic ledger includes allocation headers |
| HTTP streaming native response / upload | 64 KiB each per transfer; two PHP response chunks queued | BLOCK_PRODUCER using curl pause/resume; other per-transfer chunk copies are documented in HTTP_STREAMING.md |
| HTTP multipart fields | 64 fields, 64 KiB each / 1 MiB total | Reject invalid input; one streamed file, no unbounded field copies |
| HTTP buffered body / headers | 16 MiB / 64 KiB per transfer | Fail transfer and release native buffers |
| SQLite values/rows and SQL text | 16 MiB; 10,000 bind variables | SQLite rejects construction before result conversion; Asyncara also limits copied results |
| PostgreSQL buffered query | 16 MiB conversion budget / 100,000 rows | Fail query and close connection; per-cell metadata charge reduces empty-cell amplification |
| PostgreSQL notifications | 1,024 messages / 1 MiB channel+payload bytes | DISCONNECT_SLOW_CONSUMER with explicit exception; no silent drop |
| Redis buffered reply or entire pipeline | 16 MiB wire bytes / 100,000 parser elements; depth 32 | Fail operation and close connection to avoid protocol desynchronization |
| Redis requests | 16 MiB framed bytes; 10,000 commands per pipeline | REJECT_NEW_ITEM before concatenating beyond bound |
| ConnectionPool | 8 slots (maximum 256), 1,024 pending borrowers | FAIL_WITH_CAPACITY_EXCEPTION; `pendingLimit: 0` disallows waiting borrowers |
| ProcessPool | 256 pending jobs / 64 MiB encoded pending requests / 16 MiB frame | FAIL_WITH_CAPACITY_EXCEPTION; reservations released on completion including cancellation before execution |
| ProcessPool data | 100,000 nodes, depth 32, 16 MiB string/structure charge | Reject before JSON serialization; objects/resources/nonfinite numbers forbidden |
| ProcessPool worker PHP memory | 256 MiB per worker | PHP fatal exit, then existing crash recovery; does not cap foreign native allocations |
| Queue | 100 items by default | BLOCK_PRODUCER; `capacity: 0` is an explicit unbounded opt-in |
| Producer streams / file streams | 16 / 2 queued items by default | BLOCK_PRODUCER; arbitrary item bytes remain the application's responsibility |

`Runtime::configure()` validates all options before applying them. It must run
outside an active scheduler. `CapacityException` extends `OverflowException`.
Completed Futures and application-held values remain subject to PHP's memory
limit; the pending-Future bound does not cap all retained application results.
The failure budget counts completed unobserved Tasks so a long-running scheduler
cannot retain unlimited orphan exceptions while recycling its active task slots.

`PostgreSQL\Connection::connect()` accepts `notificationLimit` (1..1024) and
`notificationByteLimit` (1..1048576). These lower the application queue bound;
native conversion independently caps each batch at 1024/1 MiB. Overflow closes
the connection and reports the loss of the subscription. Reconnect and recover
from durable application state; PostgreSQL NOTIFY is not a durable message bus.

Redis Pub/Sub has no background application message queue: `nextMessage()` reads
one bounded response. Slow consumers exert TCP backpressure. Configure Redis
server `client-output-buffer-limit pubsub` as an additional bound; Asyncara cannot
account for the remote server queue. Pipeline limits cover all replies together.

`ConnectionPool::statistics()`, `ProcessPool::statistics()`,
`Queue::statistics()`, PostgreSQL connection statistics and
`Runtime::diagnostics()` expose admission counts. Slot and byte counts describe
the named resource, not a complete heap profiler. No diagnostic history queue is
retained. ProcessPool `queueBytes` covers encoded request retention; active frame
copies, decoded results and JSON escaping require additional PHP memory. At most
one response is read per worker at a time, and its frame is bounded independently.

The native ledger excludes curl, OpenSSL, SQLite, libpq and Connector/C internal
allocators, PHP's heap, kernel socket buffers and worker processes. SQLite's
per-connection limits control some foreign allocations; PostgreSQL single-row
mode reduces buffering but cannot prevent libpq from receiving an oversized row
before Asyncara inspects it. MariaDB Connector/C can similarly allocate protocol
buffers before conversion. Server statement/value limits, PHP memory limits,
worker/connection counts and deployment cgroup/RLIMIT_AS limits are additional
layers, not substitutes for the ledger. Asyncara does not change host limits.

Evidence: `tests/resource_bounds.php`, `tests/database/resource_bounds.php`, the
existing native limits/memory tests and the independent audit regression suite.
The first Phase 3 SQLite test failed on the baseline because `length(zeroblob(20000000))`
was accepted; the connection limit now rejects it, and a subsequent query works.

WebSocket: default 1 MiB message / 16 queued messages / 4 MiB queued payload,
16 pending pings, bounded handshake and incremental parser. Overflow policy is
DISCONNECT_SLOW_CONSUMER with explicit error after already queued messages.
Frame/message/string temporaries and waiting send callers use PHP heap outside
the native ledger. Exact configurable bounds/ownership: WEBSOCKET.md.
