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
| Asyncara native allocations | 256 MiB (`nativeMemoryLimit`) | Reject allocation; atomic ledger includes allocation headers and mapped page rounding |
| HTTP streaming native response / upload | 64 KiB each per transfer; two PHP response chunks queued | BLOCK_PRODUCER using curl pause/resume; other per-transfer chunk copies are documented in HTTP_STREAMING.md |
| HTTP multipart fields | 64 fields, 64 KiB each / 1 MiB total | Reject invalid input; 1..16 streamed files via multipartFiles, shared 64 KiB native upload slot |
| HTTP buffered body / headers | 16 MiB / 64 KiB per transfer | Fail transfer and release native buffers |
| SQLite values/rows and SQL text | 16 MiB; 10,000 bind variables | SQLite rejects construction before result conversion; Asyncara also limits copied results |
| SQLite open or opening connections | 128 per native request engine, fixed | REJECT_NEW_ITEM with ValueError; closing a connection releases admission |
| Native curl connections | 64 total / 8 per host by default; HttpClient separately admits 32 requests by default | Queue transfers in curl; Runtime httpMaxConnections/httpMaxHostConnections configure 1..256 before native submission, per-host <= total |
| PostgreSQL buffered query | 16 MiB conversion budget / 100,000 rows | Fail query and close connection; per-cell metadata charge reduces empty-cell amplification |
| PostgreSQL notifications | 1,024 messages / 1 MiB channel+payload bytes | DISCONNECT_SLOW_CONSUMER with explicit exception; no silent drop |
| Redis buffered reply or entire pipeline | 16 MiB wire bytes / 100,000 parser elements; depth 32 | Fail operation and close connection to avoid protocol desynchronization |
| Redis parsed RESP3 pushes between command replies | 1024 messages / 1MiB encoded wire bytes, configurable lower | Explicit exception and disconnect on overflow; separate drain, no idle background pump |
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
retained by these metric APIs. The separate opt-in Trace ring has the hard
bounds documented in DIAGNOSTICS.md. ProcessPool `queueBytes` covers encoded request retention; active frame
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

Phase 4 adds `tests/connection_limits.php`:129 simultaneous SQLite opens admit128
and reject one; closing/reopening recovers capacity. Independent HTTP peers see
exactly8 concurrent requests at one host and64 across nine endpoints; all88
requests complete. PHP8.3.36/8.4.27/8.5.12 pass epoll/poll, and fully instrumented
GCC ASan/UBSan8.5.12 passes. These measure active requests, not all cached library
allocations. That historical test used fixed defaults. The HTTP completion checkpoint adds configurable limits without changing those defaults.
Direct PostgreSQL/MySQL/Redis connections have no additional request-global
connection admission cap. Use bounded ConnectionPool instances and deployment
limits; creating unlimited independent pools is not covered by one pool's size.

WebSocket: default 1 MiB message / 16 queued messages / 4 MiB queued payload,
16 pending pings, bounded handshake and incremental parser. Overflow policy is
DISCONNECT_SLOW_CONSUMER with explicit error after already queued messages.
Frame/message/string temporaries and waiting send callers use PHP heap outside
the native ledger. Exact configurable bounds/ownership: WEBSOCKET.md.

Asyncara-owned allocations of at least1MiB use dedicated anonymous mappings on
the tested Linux platform. Freeing them unmaps their pages instead of leaving
large buffers in per-thread malloc arenas across FPM requests. Small allocations
and third-party library allocators keep their existing behavior. No global malloc
tuning is applied. Resizing across or within the mapped range temporarily counts
both allocations; insufficient aggregate capacity fails without losing the old
buffer. Mapping/page/allocator metadata outside the charged region still affects
RSS. ASan builds explicitly poison unused mapped tails; a deliberate overrun
negative control verifies detection. See tests/audit/mappings.c.


Phase 4 test-resource boundary: endurance observers now stream JSONL and use
bounded aggregates rather than retaining every FPM response. Raw disk usage grows
with duration and must be budgeted separately. The OS observer counts descendants
and validated private PostgreSQL daemons; inaccessible /proc metrics are explicit
nulls, not zero. It does not impose cgroup limits or change application defaults.
Repeated libpq unloads accumulate foreign BIO allocations outside the ledger;
resident connection churn has separate controls in DEPENDENCY_FINDINGS.md.

UDP descriptors are governed by the process's OS descriptor limit; there is no
new Asyncara-wide UDP connection cap. The exclusive bind path retains errno and
releases failed allocations. Error and cleanup classes load before acquisition
so descriptor exhaustion cannot prevent their first autoload. The regression
`python3 scripts/test-udp-limits.py` lowers `RLIMIT_NOFILE` to 128 in a child only,
exercises Linux `EMFILE`, releases a socket, reopens successfully and checks the
descriptor count. It does not modify the parent, host or service limits. Both
ordinary and GCC ASan/UBSan PHP pass; external libraries and kernel buffers remain
outside the native ledger.

UDP sends additionally reject payloads above 65,507 bytes, nonliteral IP targets
and destination ports outside 1..65535 before transport parsing. This avoids
implicit blocking resolver calls and integer narrowing in PHP's underlying
sendto parser. Invalid input raises ValueError without network submission; it is
an input bound, not a new global socket cap. Real maximum-size IPv4/IPv6 tests
pass in the r4 package on PHP 8.3/8.4/8.5 with both event-loop backends.

Completed frozen r4 mixed24h has zero sampled native bytes at cycle boundaries,
steady23–24fds and five threads, and zero final scheduled/native work. Full sampled
RSS41,212–47,908KiB includes warmup; steady47,524–47,784KiB gives+260KiB endpoint
median growth. The unchanged report excludes the first fifth of samples for
steady trends. Within-cycle peaks and third-party memory remain outside these
observations. Hosted r4 FPM1h passed its strict idle6fd/1thread and8MiB growth
thresholds; the local responsiveness failure remains a failure. Endurance evidence
does not turn the admission ledger into a complete process memory limit.

The scheduling observer now follows the owned process's cgroup v2 membership
through all mounted ancestors and records `cpu.max`, `cpu.stat`, CPU pressure
and weight. The historical observer inspected only the root: its `cpu.stat` was
readable, while `cpu.max` was absent. Neither a missing root limit nor zero
throttle counters proves absence of host scheduling delay. This is observation,
not resource enforcement; no cgroup/OS limit or application default changes.
Zombie-exit handling has independent real-process regression coverage.

The optional readiness-wait diagnostic keeps no log fd across calls or requests.
Its small stack record is written only after a measured wait exceeds50ms; logging
failure terminates that diagnostic process explicitly. Offline correlation uses
a temporary SQLite index with a2MiB page cache,256MiB/file input limit, one million
records/file,64KiB lines,4,096 native process IDs and fatal records, and1,024 fatal
call summaries. Limits reject oversized input rather than silently truncating it.
Only the20 largest matches are retained in the summary; original logs are kept.
These are test-tool boundaries, not new runtime quotas or complete SQLite/RSS
accounting. See FPM_LIFECYCLE.md.

The diagnostic analyzer also checks at most4,096 known FPM error logs, each
limited to256MiB, one million lines and64KiB per line. It rejects instrumentation
failure markers even when the workload deliberately expects a fatal PHP request.
PHP profiler writes now require the complete record length; write failure is a
failed diagnostic, not silently missing evidence. Old raw reports remain intact.

Native wait setup retains the small descriptor-set scan. At 32 or more input
watchers it adds a temporary, at-most 131,072-byte descriptor index to the
existing mapping allocation. It is freed on every return, including allocation
and wait errors. This control buffer, like the bounded readiness arrays, is
outside the payload memory ledger and bounded by AR_MAX_WATCHERS.

From `09b4767`, epoll also retains an engine-owned descriptor and bounded
registration/output arrays. Maximum requested retained cache storage is262184
bytes on the tested Linux x86-64 ABI (allocator/kernel overhead and fixed engine
fields excluded). `waitControlBytes` reports current retained bytes; `epollCreated`
and `epollReused` report instance creation and validated reuse. The cache is freed
before buffer growth and at engine shutdown. Initialization/allocation failure
rolls back ownership. Poll-only engines allocate no epoll cache. See
[HTTP_EPOLL_REUSE.md](HTTP_EPOLL_REUSE.md) and `tests/fast/epoll_cache.c`.


HTTP completion adds request-header limits: 256 entries / 64 KiB including CRLF;
invalid input fails before transfer. At most 64 reset easy handles are retained
per request. This is separate from the total connection limit and excludes
libcurl/OpenSSL allocation sizes from the ledger. Inspect httpCachedHandles,
httpHandlesCreated and httpHandlesReused; all handles are freed at RSHUTDOWN.
Completed native response buffers transfer ownership to the result without a
second full native copy; conversion to PHP still allocates a PHP string.
Staged downloads consume one file and one parent-directory descriptor until
publication/close. They use the existing 128-file owner limit and worker queue.


## Optional HTTP/1 server TLS and response streaming

Server response streaming admits one demanded chunk of1..65,536 bytes, applies
responseBytes to the cumulative transfer (default8MiB, PHP bridge maximum16MiB),
and charges retained payload Bytes until their final Hyper owner drops them.
A full body is not assembled. bufferBytes covers request/response payload bytes;
PHP strings, Hyper metadata/read buffers, TLS state, allocator slack and kernel
buffers are outside this ledger. rustls has a64KiB output-buffer target per
connection; it is not a total TLS allocation cap. Connection admission and
finite request deadlines are additional protections. See SERVER.md for exact
options and the independent slow-reader/cancellation controls.

## Incoming server streaming

With streamRequests=true, bodyBytes remains the total input ceiling and each
native-to-PHP demand yields at most64KiB. No entire upload is collected unless
application code (including Request::json) asks to collect it. One InputState owns
Hyper Incoming plus a retained frame remainder; one event is admitted per demand.
Input errors/overflow reject the request rather than silently dropping data.
Unconsumed input forces connection closure on reply. The existing deadline bounds
slow readers/writers; this is not an unlimited idle timeout. PHP values, Hyper
frame storage, TLS and kernel buffers remain outside the payload ledger.

## Hyper client trust and TLS buffers

The optional Hyper client accepts at most 1 MiB of PEM trust input and 512
certificates per policy. Its native PHP-request-local cache admits at most 16
exact policies, then rejects new policies; existing policies remain usable.
Connections match the exact policy identity before reuse. The 16 MiB PEM ceiling
does not cover parsed roots, Rust allocations, TLS state, PHP values or kernel
socket buffers. Rustls uses a 64 KiB outgoing-buffer limit and the shared TLS
adapter uses a 16 KiB initialized scratch buffer with at most 16 read iterations
per call. Caller read capacity still limits returned plaintext. No full-memory
accounting claim follows from these bounds. See [Hyper TLS](HYPER_TLS.md) and
`tests/http/hyper_tls_bounds.php` for rejection/recovery controls.

## Hyper buffered request bounds

Request bodies are limited to16MiB, header lines to256 entries/64KiB combined,
and methods to1..32 uppercase ASCII letters. Invalid framing, unsupported
CONNECT/TRACE and nonempty HEAD bodies are rejected before connection admission.
There is an additional owned Rust body copy outside the existing C memory
ledger; this is not zero-copy or a complete process-memory ceiling. Connection
admission and per-operation bounds remain necessary. Early keep-alive responses
retain the C body's accounting until local upload flush or connection disposal;
timeouts/cancellation still terminate that ownership. See HYPER_REQUESTS.md.

## Optional PostgreSQL prepared cache

Connection::connect adds preparedCacheLimit (0..256, default0 disabled) and
preparedCacheByteLimit (1..1048576 retained SQL bytes, default262144). Cacheable
misses evict least-recently-used owned server statements under the connection's
operation gate. Oversize SQL uses the original unnamed path. No query result is
dropped. SQL byte accounting excludes PHP metadata, libpq and server plan memory;
a server-side plan can cost more than its SQL text. statistics exposes entries,
SQL bytes, limits and cumulative hits/misses/evictions. See
[implementation and evidence](POSTGRESQL_PREPARED.md).

## Hyper streaming slots

The client continuation uses the existing64KiB C response/upload slots and
BLOCK_PRODUCER pause/resume behavior. The public response stream queues at most
two PHP chunks. Rust retains the pending response frame while paused and an
owned upload Bytes copy while the transport uses it. Hyper's configured128KiB
read/write buffering threshold, a current frame and rustls's64KiB output bound
are separate layers, not a precise total-allocation ceiling. Native accounting
does not include all Rust, PHP, TLS, allocator or kernel memory. Operation and
connection limits remain required. No critical bytes are dropped on overflow;
consumer close/owner cancellation explicitly terminates the transfer.


## Bounded Hyper decoding (2026-11-10)

The optional Hyper buffered decoder caps encoded input and decoded output independently at16MiB. zlib state/internal allocations use ar_calloc/ar_free and the aggregate native limit;32KiB output scratch is stack memory. Failed allocation rejects the operation and collects the owning job. Response streams retain64KiB raw-byte backpressure. [Executed expansion/allocation/cancellation controls](HYPER_COMPRESSION.md) establish this scope without accounting for all foreign libraries.

Redis `notificationLimit` and `notificationByteLimit` now bound the parsed push
queue separately from pipeline results. Parser bytes/elements still cover all
intervening pushes. `drainNotifications()` transfers already-read messages to PHP
application ownership; overflow/close clears retained input and disconnects.
Wire bytes do not measure total PHP array overhead. [Exact contract and evidence](REDIS_NOTIFICATIONS.md).
