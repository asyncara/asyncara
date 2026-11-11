# Redis reply association and bounded notifications

`a4f6ff4` separates RESP3 pushes from ordinary command replies; `fdc3fc3`
validates dedicated Pub/Sub response shapes and adds FPM cleanup tests. This is
an Asyncara-owned PHP protocol API over existing native readiness-integrated TCP
and verified TLS streams. No blocking worker wrapper or ext-Redis hook is used.
The scoped final gates below pass. Release remains EXPERIMENTAL.

## Reproduced defect

With Redis7.0.15, enable `CLIENT TRACKING ON BCAST PREFIX asyncara:push: NOLOOP`,
then change a matching key using an independent connection. Before the fix,
`command(['PING'])->await()` returned `['invalidate', [...keys]]` instead of
`PONG`. Both epoll and poll reproduce this failure. The parser discarded the
`>` frame identity and `pipeline()` counted the push as an ordinary response.
The real response remained pending, shifting subsequent command associations.

The parser now distinguishes top-level pushes. The serialized command owner
retains them in a separate bounded queue and continues reading the expected
ordinary reply. Pushes nested inside an aggregate are rejected: the protocol
allows out-of-band frames between replies, not within them. No reply is silently
skipped, relabeled or delivered to an unrelated command. Transaction and server
error results retain their existing shapes.

Primary specification: [Redis RESP pushes](https://redis.io/docs/latest/develop/reference/protocol-spec/#pushes)
and [CLIENT TRACKING](https://redis.io/docs/latest/commands/client-tracking/).

## Public scope and ownership

```php
$redis = Asyncara\Redis\Connection::connect(
    resp3: true,
    notificationLimit: 128,
    notificationByteLimit: 262144,
)->await();
// Configure the desired supported Redis push mode explicitly.
$reply = $redis->command(['PING'])->await();
$notifications = $redis->drainNotifications();
$limits = $redis->statistics();
```

`drainNotifications()` does not block, read a socket, start a Task or wait for a
future push. It transfers the already-parsed queue to application ownership and
resets its count/byte accounting. Pushes still buffered in PHP/TLS/kernel buffers,
or arriving after the final ordinary reply, are processed with the next command
read. There is no background idle notification reader. This is not a complete
client-side cache or a promise of cache coherence. The application must implement
its invalidation/lifetime policy; do not infer fresh cache state from an empty
local queue. A dedicated Pub/Sub connection remains the supported waiting API.

Defaults/hard maxima are1024 queued pushes and1048576 encoded wire bytes. Both
options accept smaller positive limits. `statistics()` reports current count,
wire bytes, configured bounds and closed state. Limits are enforced before queue
admission. The existing16MiB/100000-element/depth32 parser budget covers the entire
pipeline, including intervening pushes, and is not reset for each push.
A single frame can be parsed before failing queue admission, so the parser limit
also bounds that transient input. Encoded bytes are not total PHP heap usage;
array/string overhead and library/kernel buffers are additional allocations.

Overflow raises `OperationException`, discards the connection and clears its
request-owned buffers/notification queue. Treat that explicit failure as loss of
the subscription and rebuild any dependent cache state. There is no silent
DROP_OLDEST policy. Explicit `close()` also clears retained data and is idempotent.
A result returned by drain is application-owned and remains under PHP's memory
limit. Reconnection is explicit; no automatic replay of potentially executed
commands occurs.

Cancelling a shared waiter does not cancel the owning operation. Cancelling the
command owner discards the connection, because the incomplete reply stream cannot
be safely reused. Gates serialize command reads. Calling drain from another Task
while a command waits is safe in the single-interpreter model: the method does
not suspend. No cross-request connection sharing is implemented.

`subscribe()` / `nextMessage()` keep dedicated RESP2/RESP3 Pub/Sub semantics,
without adding a background application queue. Acknowledgements must match the
requested channel; delivered messages must have the expected list shape. A new
malformed-map regression first reproduced an undefined-key warning in the initial
validation; list checks now precede indexing, producing a controlled protocol
error and close without warning. Remote Redis output buffers remain server-owned;
configure server-side Pub/Sub output limits separately.

## Evidence and limits

Raw root: `/home/coder/Asyncara-priority-20261110T205459Z`.
`redis-push-before-r1` and `redis-subscription-before-r1` preserve failures.
`tests/database/redis_push_protocol.php` exercises real TCP/readiness, partial
push delivery, exactly two ticks before peer release, concurrent drain, wire-bound
equality, nested frames, capacity/EOF cleanup and peer-observed socket closure.
The corpus adds nested-push failures and a valid push without rewriting the parser.

`tests/database/redis_push.py` starts only its own isolated Redis7.0.15 with ACL
and private-CA TLS. It verifies RESP2/3 pipelines/transactions, tracking pushes,
queue/drain/overflow, dedicated Pub/Sub, shared waiters, owner cancellation and
zero outstanding runtime owners. With `--fpm`, it retains20 sequential plus40
four-worker requests in each plain/TLS case, original two-tick/HTTP overlap checks,
CLIENT LIST cross-request ownership checks and fatal memory exhaustion while
Redis independently confirms a BLPOP is pending. The next request must recover
on the same worker without an earlier request's named connection.

```
php -n -d extension=/path/to/asyncara.so tests/database/redis_push_protocol.php
python3 tests/database/redis_push.py --php /path/to/php \
  --module /path/to/asyncara.so --backend epoll --out /new/redis-controls
python3 tests/database/redis_push.py --php /path/to/php \
  --module /path/to/asyncara.so --backend poll --fpm /path/to/php-fpm \
  --out /new/redis-fpm
```

General RESP3 attributes, streaming aggregates, Cluster/Sentinel, automatic
reconnect, idle push pumping and a complete cache invalidation protocol remain
unimplemented. Tests certify these explicit framing/ownership paths on local
Redis7.0.15, not every server version, push extension, WAN or long-lived deployment.
Historical PHP shutdown, libpq and FPM endurance findings remain separate.

## Completed verification

[Durable attestation](phase7/redis-notifications.json) retains commands, failures,
source/module fingerprints and report hashes. Native code remains `acda044`;
this repair changes the Redis PHP parser/owner, not the epoll/poll engine.

| Check | Actual result |
|---|---|
| Final five-build matrix, both backends | PASS30 commands18.216s; actual PHP8.3.36/8.4.27/8.5.12,DEBUG/GCC8.5.12 |
| DEBUG Valgrind, ordinary unload | PASS6 processes56.177s;0errors and0definite/indirect/possible loss;3888bytes reachable each |
| Gate A | PASS49 commands238.244s outer runner |
| Full PHP85 | PASS epoll43.219s, poll57.535s |
| Four plain/TLS FPM cases across both backends | PASS240 main responses,960HTTP operations,2160 counted main-request Redis commands,240 notifications |
| Seeded mutation smoke | PASS51182 inputs/15.009s; seeds17,271828,104729; no discovered crash;2MiB peak reported PHP allocation |
| Exact RESP corpus | PASS112 production-parser cases including partial inputs |
| Existing SQL/Redis integration, database bounds, allocation audit | PASS with new private fixtures |

Independent FPM recomputation verifies all main response IDs/counts, original
HTTP overlap/two-tick conditions, one retained notification per request,
native cleanup and idle6FD/1thread. Minimum ticks are5 across the completed cases.
Sequential RSS ranges: plain epoll18268..18592KiB, plain poll18316..18636,
TLS epoll22128..22444, TLS poll22016..22324. Four-worker samples range
18244..18452,16392..18372,20152..22040,21760..22120 respectively. These are short
samples, not a leak trend/endurance certification; original conditional RSS checks
are unchanged. Counts exclude extra fatal/recovery requests and handshake commands.
FPM log files preserve deliberate fatal-memory errors; these are expected fault
controls, not suppressed runtime failures. The subsequent same-worker request
checks Redis server connection names for earlier request owners.

The memory result is specific to the tested parser/connection paths. It does not
erase historical distribution-PHP cyclic shutdown or upstream libpq findings.
GCC instruments C/PHP; Rust release dependencies are not covered by that compiler.
The mutation smoke also exercises existing IPC/header code and is not exhaustive
RESP conformance or native-parser fuzz coverage. No performance superiority claim.
Independent package verification follows the documented source checkpoint.

## Independently verified source distribution

Packaged source `f9cd72d84b330b381d9d5c5b21cbe298565d1595`,676files.
Archive `build/asyncara-0.1.0-dev-redis-push-r1.tar.gz`; SHA256
`a65307e5a15cac3f294c2a3df26494e4a9880a7c0eb38e9a659326299477942a`.
Two archives are byte-identical; every file matches the committed source tree.
Fresh extraction/Cargo target/native build144.764s and8 protocol/real Redis/FPM/
corpus checks on epoll/poll all pass. [Independent attestation](phase7/redis-push-package.json).
Independent module SHA256 `d681f04ee7acdaa31cd0c487c4910ff43171f7aace238861432818a5fc1ab8e9`.
No GitHub release or broader certification follows from packaging.
