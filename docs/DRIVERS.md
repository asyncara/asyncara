# Driver behavior

## HTTP

`HTTP\HttpClient(concurrency:32, timeout:30, proxy:null, caFile:null)` returns Tasks from get, post and request. post accepts exactly one of json, form or body. Headers are lists of `Name: value` strings; CR/LF and NUL injection is rejected natively. Only HTTP and HTTPS are allowed, including redirect protocols. Redirect following is opt-in, capped at five hops. libcurl handles DNS, verified TLS, keep-alive, pooling, HTTP framing, chunked transfer and supported decompression. Per-runtime native limits are 64 connections total, eight per host, 16 MiB body and 64 KiB headers per response. The client semaphore adds an application admission limit.

Responses expose status, body, lowercased multi-value headers and json(). HTTP errors such as 503 are successful transport responses. Connection/protocol/TLS failures throw. Request cancellation removes the curl handle; no process-wide curl hook is installed. Native curl must report asynchronous DNS support, or HTTP submission fails. HTTP/2 may be negotiated by the installed curl but is not separately certified; HTTP/3 is not advertised. Response streaming, multipart upload and per-request arbitrary curl options are not implemented.

`HTTP\Psr\Client` implements PSR-18 with injected PSR-17 response/stream factories. It works inside a current runtime or starts one around a standalone call. HTTP status codes are preserved. Invalid requests and transport failures implement PSR exception interfaces and retain getRequest(). Request-body materialization follows the supplied PSR-7 stream and can block if that external stream blocks; the adapter does not make arbitrary stream implementations asynchronous. Responses are buffered and decoded transfer metadata is normalized.

## TCP, UDP, DNS and TLS

`IO\TcpServer(numericAddress, port:0, backlog:128)` binds/listens, reports its actual address and asynchronously accepts TcpStream connections. `TcpStream::connect(host, port, timeout:30, tls:false, caFile:null)` resolves names through worker getaddrinfo, then attempts nonblocking connections. read, readExactly and write handle EOF/partial operations. Concurrent reads or writes on one stream are rejected; separate read/write directions can coexist. read/write have no implicit operation deadline: wrap them in Async::timeout. Close wakes registered waiters before releasing the resource.

Explicit TLS uses PHP/OpenSSL with peer and hostname verification, SNI and HTTP/1.1 ALPN. Local trusted and untrusted certificate scenarios are tested. TLS server support, renegotiation edge cases and graceful TLS close-notify behavior are not certified. HTTP uses curl's mature TLS implementation.

`UdpSocket` supports numeric IPv4/IPv6 bind and destination addresses, receive with peer identity, bounded datagrams, empty datagrams and close. Tests cover loopback IPv4; IPv6 needs a separate integration matrix. Multiple concurrent receives on one socket are not supported as a reliable dispatch pattern; give the socket one receiving task. `Dns::resolve` returns IPv4/IPv6 address strings. It is worker-backed; there is no TTL cache and libc lookup cannot be forcibly interrupted.

## Files

`IO\Files` implements read, write, append, exists, delete, rename, mkdir, rmdir, stat, entries and copy. Each submits real native work. Paths obey open_basedir checks at submission; this is not a race-proof filesystem sandbox. Regular-file read/write/copy reject special files. Input/result buffers are capped at 16 MiB; directory entries are capped at 100,000. write is not atomic replacement or fsync. Cancellation may leave a partial write or copy and cannot undo side effects. Copy detects identical source/destination inodes before truncation.

`Files::open(path, mode)` returns a Future of `IO\File`, a stable native descriptor with read, write, seek, stat, sync and close Futures. Modes r/w/a/x accept optional + and b. Operations serialize in submission order and retain the gate through cancellation completion. read returns up to the requested count, with an empty string at EOF; write loops until all bytes are written or an error/cancellation occurs. seek returns the new offset. sync executes fsync on a worker. A descriptor continues referring to the opened inode after path replacement/unlink. This does not isolate in-place writes/truncation by other processes. Close explicitly with Async::using/finally; repeated close is safe. Handles cannot be cloned or serialized. Request shutdown closes abandoned handles; they are not immediately closed by a PHP destructor.

readStream returns an AsyncStream with two buffered chunks, one stable descriptor and producer-owned cleanup. A full iteration closes the file. For early termination, call close() or use consume() to join cleanup; generator destruction only requests cancellation, and the enclosing runtime joins it. writeStream holds one descriptor, consumes chunks with backpressure and closes both its output and an AsyncStream input on failure. Large-file copies use a fixed native buffer. exists distinguishes missing/not-a-directory errors from other errors, but its current errno-message classification is limited to the tested libc locale.

## SQLite

`SQLite\Connection::open(path)` executes sqlite3_open_v2 in workers, including `:memory:` databases. query/execute accept positional scalar/null parameters; prepare returns a lightweight Statement whose execution prepares and binds on the native queue. One statement per call; multiple SQL statements are rejected. Results are arrays of associative rows, preserving SQLite integers, doubles, nulls and binary strings. Limits are 100,000 rows / 16 MiB approximate result payload and structure accounting.

The connection's admission gate serializes operations and holds ownership until native completion. Native busy protection rejects overlapping low-level submissions. SQLite FULLMUTEX allows serial use by different worker threads; thread identity is not promised. Busy timeout is 1,000 ms. Progress-handler cancellation interrupts executing queries; a busy handler or committed write may finish before cancellation is observed. Close waits its turn. Failed opens and closed native connection metadata are reclaimed.

Use transaction(callable) for guaranteed rollback on body failure. beginTransaction/commit/rollback also exist; manual transactions belong to the calling task and require explicit finally cleanup. Do not spawn unrelated tasks that use a transaction-owned connection from inside the transaction: they wait for its owner and can deadlock. Use independent connections for concurrent work. Transactions reserve a connection; globals do not isolate them.

## PostgreSQL

The optional libpq implementation connects using PQconnectStartParams/PQconnectPoll, explicitly enables nonblocking mode, sends bound extended-protocol queries, flushes output, consumes input and checks busy state before collecting results. Single-row mode bounds each native extraction batch; the public result is still buffered with 100,000-row/16 MiB limits. Values are text strings or null; use PostgreSQL's bytea text encoding for binary values. prepare is a parameterized statement descriptor, not a server-side prepared-statement cache.

connect accepts host, hostaddr, port, dbname, user, password, sslmode, sslrootcert and application_name. DNS resolves in native workers before libpq connect. sslmode defaults to verify-full; test fixtures explicitly disable it for private Unix sockets. SQL TLS/authentication deployments beyond local trust auth are not integration certified. Query failure or cancellation closes the connection; server-side cancellation acknowledgment is not promised. transaction reserves the connection. LISTEN plus nextNotification supports asynchronous notifications on a dedicated connection.

## MySQL / MariaDB

The optional Connector/C implementation uses actual nonblocking start/cont APIs for connect, prepare, execute, row fetch and statement close. Parameters are bound through MYSQL_BIND, including binary strings; results are strings/null. Connection affinity and transaction scopes follow PostgreSQL's model. SQL errors and cancellation discard the connection and free interrupted statements. Connector global initialization and cleanup are paired.

connect accepts numeric host or Unix socket, port, user, password, database, tls and caFile. TLS verification/enforcement are enabled by default for TCP. Hostname-to-address substitution would change connector hostname verification; this implementation therefore requires a matching IP certificate SAN for TLS TCP. Named hosts are resolved only when tls is explicitly false. Tests use an isolated MariaDB 11.8.9 server over Unix sockets; MySQL-server variants, authentication plugins and TLS configurations remain unverified. No PDO/MySQLi compatibility claim is made.

## Redis

`Redis\Connection::connect` supports host/port, optional password/user, RESP3 negotiation, timeout and TLS options. command returns a decoded value and throws ServerException for a server error. pipeline sends one bounded batch and drains every reply, returning server errors as values. transaction wraps commands with MULTI/EXEC without interleaving other commands. Subscribe returns a dedicated Pub/Sub connection; nextMessage yields protocol arrays. Cancellation or malformed transport closes the socket to prevent response misassociation. Reconnect explicitly or use a ConnectionPool; commands are never replayed automatically.

RESP2 and common RESP3 scalars, blobs, arrays, maps, sets and pushes are parsed with depth, byte and element limits. RESP3 attributes, streaming aggregates, arbitrary map-key types and client-tracking push demultiplexing are not supported. Pub/Sub uses a dedicated connection. Server/client feature negotiation beyond HELLO is not automatic.
