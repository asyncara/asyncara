# WebSocket

**PARTIALLY_IMPLEMENTED** overall certification; the client, raw TCP server
upgrade, framing and connection lifecycle below are implemented and tested.
The protocol follows [RFC 6455](https://www.rfc-editor.org/rfc/rfc6455).
This is an explicit API over the existing nonblocking TcpStream, not a PHP hook
or a curl HTTP connection pool. No new native parser was introduced.

```php
use Asyncara\Async;
use Asyncara\WebSocket\Client;

Async::run(function (): void {
    $connection = Client::connect('wss://example.test/socket')->await();
    try {
        $connection->send('hello');
        $message = $connection->receive(); // Message|null; binary flag and data
        $connection->ping()->await();
    } finally {
        $connection->close(); // bounded close acknowledgement, then cleanup
    }
});
```

`Server::accept($tcpStream, origins: ['https://allowed.test'], protocols: [...],
authorize: $callback)` upgrades an accepted raw TCP stream. Origin-bearing
requests are denied unless explicitly allowed. Clients without Origin are
permitted; an Origin check is not authentication. The optional authorization
callback receives path and headers and must return true. Rejected handshakes
close the transport; an HTTP error response service is not provided. This API
is for a standalone listener, not PHP-FPM request upgrades.

## Implemented behavior

- HTTP/1.1 upgrade, random nonce, exact accept validation, subprotocol selection,
  bounded headers, duplicate-critical-header rejection, role-specific masking.
- Incremental minimal-length parsing, RSV/opcode validation, continuation and
  fragmentation state, text UTF-8 validation across fragments, binary messages.
- Ping/Pong interleaved between data fragments, close validation/acknowledgement,
  malformed/truncated/abnormal EOF propagation and read/write/idle deadlines.
- Client WSS with existing certificate and hostname verification. Test certificates
  use a private CA; wrong CA and identity are rejected.
- Receive cancellation leaves the connection open. Send failure/cancellation
  aborts the connection because a partial frame cannot safely be replayed.
  `abort()` closes immediately; `close()` always joins the reader during cleanup.
  Callers must close owned connections in finally; retaining an abandoned active
  connection keeps its reader alive until its deadline/runtime cancellation.

There is one active receiver. Concurrent sends are serialized per message;
separate frame locking lets control frames pass between 64 KiB data fragments.
`receive()` returns queued messages before reporting a stored failure, or null
after a clean close. `exception()` and `statistics()` expose stored errors and
queue/close/ping state. Ping Futures start immediately, have bounded deadlines,
and reject on closure. The idle timeout defaults to 30 seconds between reads,
not a background keepalive guarantee; applications may send explicit pings.

## Bounds and backpressure

Defaults: 1 MiB per message, 16 queued messages and 4 MiB queued payload bytes.
Queue overflow sends close 1008 and disconnects the slow consumer, preserving
already queued messages for delivery before the exception. There is no silent
message drop. Maximum configurable message is 16 MiB, queue count 1024 and queue
bytes 64 MiB. Pending ping count is 16. Parser pending bytes are bounded by the
frame limit plus 65,550 bytes; headers by 16 KiB/64 fields plus a 4 KiB read.
Temporary frame, mask, message and PHP string copies are additional to queued
payload totals. They are PHP-heap allocations, not native-ledger bytes.

Outbound writes wait for socket readiness. Send callers can retain their own
strings while waiting for the message lock; scheduler admission bounds tasks,
not total application memory. Configure PHP memory/process limits as well.

## Executed validation

`python3 scripts/install-test-websockets.py` installs a SHA-256 pinned BSD-licensed
Python websockets 17.4 peer under .local only. `./scripts/test-websocket.sh` runs
41 framing cases and bidirectional interoperability, WSS, malformed-handshake,
fragmentation/control, slow-consumer, cancellation and shutdown tests. Plain
Asyncara server interoperability includes independent-client compression offers
which are deliberately not negotiated. The independent raw peer requires Pong
before the end of an 8 MiB fragmented upload; a separate old-lock negative
control fails this assertion.

PHP 8.3.36/8.4.27/8.5.11 NTS, poll, fully ASan/UBSan-instrumented 8.5 DEBUG and
8.5 ZTS single-interpreter CLI pass. Valgrind: zero errors and zero definite,
indirect or possible losses; 77,515 bytes reachable. No suppression was added.
Deterministic instrumented framing/close mutation campaigns ran 24,933 and
47,262 inputs in 60 seconds each without a finding; this is not coverage-guided
network/state-machine fuzzing. See FUZZING.md and phase3/websocket.json.
A frozen300-second/16-connection soak passed24,686cycles with flat RSS/fd/thread
samples and zero final pending work. Trends and limits are in SOAK_TESTING.md.

## Unavailable or unverified

No permessage-deflate/extensions, HTTP/2 upgrade, proxy, redirects, automatic
reconnection/replay or transparent WebSocket hooks. No server-side WSS listener
certification, browser/framework certification or Autobahn conformance run.
Long-duration/high-count workloads and remote adversarial deployments need
broader validation. Application payloads are bounded whole messages, not a
streaming message API. The overall release remains EXPERIMENTAL.


Phase 4 extended mixed smoke retains one ws and one verified wss connection
alongside HTTP/files/SQL/process work. Echo, ping, periodic reconnect and cancelled
receive reuse passed in60-second epoll/poll runs using the independent Python peer.
This is smoke evidence, not browser/Autobahn/WSS-server or endurance certification.
See PHASE4_DELIVERY.md for campaign status.

The expanded ten-minute mixed diagnostic subsequently completed 2,179 cycles
with clean final scheduled work. The earlier requested one-hour mixed run failed
on a timeout whose waiting operation was not identified by the original trace.
Per-operation diagnostics now preserve that detail on a recurrence. Neither run
establishes one-hour WebSocket endurance or identifies a WebSocket defect.

The f2abb55 / phase4-r4 extracted source independently passes the existing 41
framing cases and bidirectional Python interoperability/network suite on PHP
8.3.36, 8.4.27 and 8.5.12 NTS. The UDP repairs add no WebSocket capability.
The completed mixed endurance results follow below; browser/Autobahn/server-WSS
certification remains unavailable.

Frozen r4/f2abb55 mixed1h/6h/24h passed with one ws and one verified wss client,
echo/ping, receive cancellation and periodic reconnect. The last24h sample
records2,948 reconnect rounds. These are repeated connections over a24h workload;
single uninterrupted24h connections, server-WSS, browser and Autobahn coverage
remain unverified. The source/protocol capability is unchanged; independent
review and raw hashes are in phase4/endurance-r4.json.
