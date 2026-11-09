# Backends and limits

`Runtime::information()` obtains runtime facts from the extension. It reports eventLoopBackend, filesystemBackend, networkBackend, dnsBackend, sqliteVersion, curlVersion, nativeWorkers, pendingOperations, queue/buffer caps, nativeMemoryBytes/nativeMemoryPeak/nativeMemoryRejections/nativeMemoryLimit, openFileHandles and optional SQL compilation flags. Compile flags are not a claim that a remote deployment's authentication/TLS combinations were tested.

Linux epoll is the default. Set `ASYNCARA_BACKEND=poll` before the first capability call or operation to choose poll. Both wait on socket readiness and a nonblocking worker completion pipe; native curl socket interests and its next timeout join that wait. Interrupted waits return to scheduler processing. Rebuilding epoll registrations on each wait is deliberately simple but has overhead; persistent registration is future performance work. Stream descriptors in one wait are deduplicated so concurrent read/write interests can coexist.

The PHP scheduler processes at most 256 ready tasks per turn, then checks timers and readiness. Timers use hrtime's monotonic clock and a priority heap. Cancelled heap entries are pruned and periodically compacted. Deadlock detection recognizes suspended tasks with no ready work, timers, native work or registered I/O; it cannot prove all circular dependencies or detect a peer that never responds.

The native pool creates up to four pthreads lazily per PHP request. The bounded job queue is request-owned. Filesystem and SQLite operations use those threads; getaddrinfo is a worker fallback. Worker inputs/results contain malloc-owned data, not PHP pointers. The loop thread marshals results. Shutdown requests cancellation, joins workers, then frees jobs, database handles and buffers. libc DNS and blocked storage calls can delay that join; no unsafe thread termination is attempted.

Current limits:

| Resource | Limit |
|---|---|
| live Tasks | configurable, default 20,000; memory must be budgeted separately |
| active timers | 100,000 |
| native pending operations | 1,024 |
| retained Asyncara native allocations | default 256 MiB; configurable 1 MiB–1 GiB before submission |
| stable native file handles | 128 |
| native wait descriptors | 16,384 including curl/notification descriptors |
| file/HTTP/IPC payload | generally 16 MiB per buffered operation/frame |
| HTTP streaming response/upload buffers | 64 KiB each; HTTP/1.1 pause/resume, two PHP response chunks queued |
| HTTP response headers | 64 KiB |
| native HTTP connections | defaults64 total/eight per host; request-local configurable1..256, per-host at most total, before submission |
| HttpClient in-flight admission | configurable 1–256, default 32 |
| native SQLite connections | 128 active |
| DB buffered rows | 100,000 and driver-specific byte cap |
| process workers | 1–64 |
| submitted process jobs | configurable, default 256 |
| Redis pipeline | 10,000 commands / 16 MiB request |
| Redis parse | depth 32 / 100,000 elements / 16 MiB reply |

`Runtime::configure(['nativeMemoryLimit' => 67108864])` sets a 64 MiB aggregate budget before the first native operation. Atomic accounting covers retained Asyncara-owned jobs, input/result trees, file/HTTP buffers and native connection metadata, including allocator headers. Growth reservations fail before exceeding the budget; completion/error cleanup releases them. The limit becomes immutable on first submission. `nativeMemoryPeak` measures peak reserved bytes; `nativeMemoryRejections` counts budget rejections.

This is **not a whole-process memory limit**. Third-party curl/SQLite/OpenSSL/SQL-connector internals, thread stacks, the small engine bootstrap and transient bounded readiness buffers are excluded. Readiness control buffers are separately bounded to under 1 MiB at the descriptor cap, so budget exhaustion cannot prevent draining/cancellation. Zend allocations remain subject to PHP's memory_limit. Applications still need conservative concurrency, database/server limits and OS process/container quotas for hostile or dense multi-tenant workloads.

io_uring, kqueue and IOCP remain absent. The initialization host's liburing NOP test completed successfully; implementing and validating actual file operations, cancellation and kernel feature fallbacks is still required. No released PHP scheduler RFC is assumed. PHP threading capability remains false even on an untested ZTS build until an interpreter-thread executor is implemented and validated.

Phase 3 additions: readiness includes bytes already buffered by PHP streams
(including TLS casting); syscall errors preserve errno; replaceAtomic runs on
native workers with explicit visibility/durability semantics in FILESYSTEM.md.
No io_uring filesystem, kqueue or IOCP implementation was added. Database TLS
capabilities and synchronous trust-file/crypto setup boundaries are documented
in DATABASE_COMPATIBILITY.md. Single-interpreter ZTS CLI evidence does not enable
PHP userland threads. Native aggregate accounting still excludes external libraries.

WebSocket uses the existing nonblocking TCP/PHP OpenSSL stream readiness path
(epoll or poll), with bounded PHP framing and message queues. No native worker
or curl pool executes WebSocket framing. See WEBSOCKET.md for verified WSS
client and raw plain-server scope; server-side TLS is not certified.

## Expanded local networking matrix

UDP endpoint ownership uses an exclusive native bind, followed by the existing
PHP socket stream and epoll/poll readiness paths. PHP's ordinary stream-server
bind enables `SO_REUSEADDR`; inheriting it let two live Asyncara UDP endpoints
share an address/port and receive each other's datagrams. The corrected helper
allocates the PHP-owned stream before acquiring the descriptor, sets nonblocking
and close-on-exec flags, and closes the stream on every bind/setup error. An
occupied endpoint now raises `OperationException` retaining errno. Reuseport,
multicast sharing and transparent PHP UDP hooks are not added. `UdpSocket`'s
numeric-address API and send/receive cancellation remain unchanged. Regression:
`tests/udp_ownership.php`; package-specific results are in PHASE4_DELIVERY.md.

`./scripts/test-network-edges.sh` now exercises IPv4 and IPv6 TCP listeners/
connections, IPv6 UDP including empty datagrams, localhost dual-address DNS and
fallback after an IPv6 refusal, TLS1.2 on IPv4 and TLS1.3 on IPv6, SNI localhost,
ALPN http/1.1, verified CA/hostname rejection, deliberately slow partial uploads/
downloads, abrupt peer disconnect, refused connections and handshake timeout/
cancellation. An unrelated timer advances throughout slow transfers. Tests pass
on PHP8.3/8.4/8.5 NTS, epoll/poll and instrumented8.5; Valgrind reports zero errors
and zero definite/indirect/possible losses (77,515 reachable bytes).

DNS remains worker-getaddrinfo. A test-only linker wrapper delays one reserved
hostname200ms; deadlines/cancellation return to the Fiber and timers continue,
while runtime cleanup still waits for that native call to return. There is no
claim of interrupting libc or supplying DNS TTL/cache controls. The first
LD_PRELOAD fixture did not interpose on the8.3 module and failed; explicit linker
wrapping makes the injected path reproducible without changing production code.

Scope remains Linux loopback/OpenSSL3.0.13. No WAN, IPv6 scope-ID/link-local,
Happy Eyeballs, renegotiation, TLS key-update or WANT_WRITE saturation coverage.
TcpStream handshake currently awaits readability after incomplete crypto; broad
TLS transport-state certification remains incomplete. Trust-file/crypto setup
is synchronous library work. Successful local transfer tests do not establish
universal TLS compatibility or server-side TLS listener support.

UDP destinations now require a literal IPv4/IPv6 address and port 1..65535,
validated before PHP's sendto parser. This prevents implicit synchronous hostname
resolution for malformed numeric-looking input and port wrap. Constructor port
zero still requests an ephemeral local bind; destination port zero is rejected.
Invalid destinations raise ValueError without waiting for readiness or sending.
The existing 65,507-byte datagram bound and native epoll/poll readiness remain.
Tests: udp_destinations.php, including actual empty/maximum-size IPv4/IPv6 sends.

Frozen r4 Linux/PHP8.5.12 NTS epoll mixed1h/6h/24h now passes with existing IPv4/
IPv6 UDP, localhost DNS, verified TLS1.2/1.3 and HTTP/HTTPS/ws/wss peers. The last
24h sample records589,782 UDP datagrams and verified TLS connections each. These
are loopback workload observations. Poll retains three-version functional coverage;
no new long-duration poll, WAN, platform or io_uring certification is inferred.
