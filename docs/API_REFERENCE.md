# Public API reference

Generated from source signatures by `scripts/api-reference.php`. Semantics and internal-boundary restrictions are in API.md. Inherited methods are documented under their declaring class. Low-level `asyncara_*` functions are internal transport APIs.

## `Asyncara\Async`

```php
static run(callable $body): mixed
static spawn(callable $body, bool $inheritContext = true): Asyncara\Task
static await(Asyncara\Future $future): mixed
static gather(array $tasks): array
static sleep(float $seconds): void
static cancel(Asyncara\Task|Asyncara\Future $task): void
static callLater(float $seconds, callable $body): Asyncara\Timer
static repeat(float $seconds, callable $body): Asyncara\Task
static shield(callable $body): mixed
static timeout(float $seconds, callable $body): mixed
static taskGroup(callable $body): mixed
static wait(array $tasks, ?float $timeout = NULL, bool $all = true): Asyncara\WaitResult
static asCompleted(array $tasks): Generator
static using(callable $acquire, callable $body, callable $release): mixed
```

## `Asyncara\Awaitable`

```php
await(?Asyncara\CancellationToken $cancellation = NULL): mixed
```

## `Asyncara\CancellationSource`

```php
__construct()
cancel(?Asyncara\Exception\CancelledException $reason = NULL): void
```

## `Asyncara\CancellationToken`

```php
isRequested(): bool
throwIfRequested(): void
```

## `Asyncara\Concurrency\Event`

```php
__construct(bool $set = false)
set(): void
reset(): void
isSet(): bool
wait(): void
```

## `Asyncara\Concurrency\Lock`

```php
__construct()
acquire(): void
release(): void
synchronized(callable $body): mixed
```

## `Asyncara\Concurrency\Queue`

```php
__construct(int $capacity = 100)
put(mixed $value): void
tryPut(mixed $value): bool
get(): mixed
taskDone(): void
join(): void
count(): int
statistics(): array
close(): void
```

## `Asyncara\Concurrency\Semaphore`

```php
__construct(int $permits)
acquire(): void
release(): void
withPermit(callable $body): mixed
```

## `Asyncara\Context`

```php
static set(string $key, mixed $value): void
static get(string $key, mixed $default = NULL): mixed
static all(): array
static isolated(callable $body, array $values = []): mixed
```

## `Asyncara\Database\Database`

```php
static connectAsync(array $config): Asyncara\Future
```

## `Asyncara\Exception\CancelledException`

Exception or interface type; inherited standard behavior.

## `Asyncara\Exception\CapacityException`

Exception or interface type; inherited standard behavior.

## `Asyncara\Exception\DeadlockException`

Exception or interface type; inherited standard behavior.

## `Asyncara\Exception\FilesystemException`

```php
__construct(string $message, int $errno, bool $committed = false)
isMissing(): bool
```

## `Asyncara\Exception\OperationException`

Exception or interface type; inherited standard behavior.

## `Asyncara\Exception\QueueClosedException`

Exception or interface type; inherited standard behavior.

## `Asyncara\Exception\TimeoutException`

Exception or interface type; inherited standard behavior.

## `Asyncara\Exception\WorkerException`

Exception or interface type; inherited standard behavior.

## `Asyncara\Future`

```php
__construct()
static pendingCount(): int
isCompleted(): bool
isCancelled(): bool
exception(): ?Throwable
result(): mixed
await(?Asyncara\CancellationToken $cancellation = NULL): mixed
cancel(): void
```

## `Asyncara\HTTP\HttpClient`

```php
__construct(int $concurrency = 32, float $timeout = 30.0, ?string $proxy = NULL, ?string $caFile = NULL)
get(string $url, array $headers = [], bool $followRedirects = false): Asyncara\Future
getStream(string $url, array $headers = [], bool $followRedirects = false): Asyncara\HTTP\ResponseStream
download(string $url, string $path, array $headers = [], bool $followRedirects = false): Asyncara\Future
upload(string $url, string $path, string $method = 'PUT', array $headers = []): Asyncara\Future
requestStream(string $method, string $url, iterable $body, ?int $contentLength = NULL, array $headers = []): Asyncara\Future
multipart(string $url, array $fields, string $fileField, string $path, ?string $filename = NULL, string $contentType = 'application/octet-stream', array $headers = []): Asyncara\Future
post(string $url, mixed $json = NULL, ?array $form = NULL, ?string $body = NULL, array $headers = []): Asyncara\Future
request(string $method, string $url, ?string $body = NULL, array $headers = [], bool $followRedirects = false): Asyncara\Future
```

## `Asyncara\HTTP\Psr\Client`

```php
__construct(Psr\Http\Message\ResponseFactoryInterface $responses, Psr\Http\Message\StreamFactoryInterface $streams, Asyncara\HTTP\HttpClient $http = new Asyncara\HTTP\HttpClient())
sendRequest(Psr\Http\Message\RequestInterface $request): Psr\Http\Message\ResponseInterface
```

## `Asyncara\HTTP\Psr\NetworkException`

```php
__construct(Psr\Http\Message\RequestInterface $request, Throwable $previous)
getRequest(): Psr\Http\Message\RequestInterface
```

## `Asyncara\HTTP\Psr\RequestException`

```php
__construct(Psr\Http\Message\RequestInterface $request, Throwable $previous)
getRequest(): Psr\Http\Message\RequestInterface
```

## `Asyncara\HTTP\Response`

```php
__construct(int $status, string $body, string $rawHeaders)
json(bool $associative = true): mixed
```

## `Asyncara\HTTP\ResponseHead`

```php
__construct(int $status, string $rawHeaders)
```

## `Asyncara\HTTP\ResponseStream`

```php
headers(): Asyncara\Future
getIterator(): Traversable
consume(callable $consumer): void
close(): void
```

## `Asyncara\Hook`

Exception or interface type; inherited standard behavior.

## `Asyncara\HookReport`

```php
__construct(array $available, array $missing)
enabled(): array
unsupported(): array
reasons(): array
```

## `Asyncara\IO\Dns`

```php
static resolve(string $host): Asyncara\Future
```

## `Asyncara\IO\File`

```php
__serialize(): array
__unserialize(array $data): void
static open(string $path, string $mode = 'r'): Asyncara\Future
read(int $length = 65536): Asyncara\Future
write(string $data): Asyncara\Future
seek(int $offset, int $origin = 0): Asyncara\Future
stat(): Asyncara\Future
sync(): Asyncara\Future
isClosed(): bool
close(): Asyncara\Future
```

## `Asyncara\IO\Files`

```php
static open(string $path, string $mode = 'r'): Asyncara\Future
static read(string $path): Asyncara\Future
static write(string $path, string $data): Asyncara\Future
static replaceAtomic(string $path, string $data, bool $durable = false, int $permissions = 384): Asyncara\Future
static append(string $path, string $data): Asyncara\Future
static delete(string $path): Asyncara\Future
static rename(string $from, string $to): Asyncara\Future
static mkdir(string $path): Asyncara\Future
static rmdir(string $path): Asyncara\Future
static stat(string $path): Asyncara\Future
static entries(string $path): Asyncara\Future
static copy(string $from, string $to): Asyncara\Future
static exists(string $path): Asyncara\Future
static readStream(string $path, int $chunkSize = 65536): Asyncara\Stream\AsyncStream
static writeStream(string $path, iterable $chunks): Asyncara\Future
```

## `Asyncara\IO\TcpServer`

```php
__construct(string $address = '127.0.0.1', int $port = 0, int $backlog = 128)
address(): string
accept(): Asyncara\IO\TcpStream
close(): void
__destruct()
```

## `Asyncara\IO\TcpStream`

```php
__construct(mixed $stream)
static connect(string $host, int $port, float $timeout = 30.0, bool $tls = false, ?string $caFile = NULL): Asyncara\Future
read(int $maxBytes = 65536): string
readExactly(int $bytes): string
write(string $data): void
resource(): mixed
close(): void
__destruct()
```

## `Asyncara\IO\UdpSocket`

```php
__construct(string $address = '127.0.0.1', int $port = 0)
address(): string
send(string $data, string $address): int
receive(): array
close(): void
__destruct()
```

## `Asyncara\Internal\IpcFrame`

```php
static length(string $header): int
static encode(array $value): string
static decode(string $json): array
```

## `Asyncara\Internal\JsonData`

```php
static validate(mixed $value): void
```

## `Asyncara\MySQL\Connection`

```php
static connect(array $options, float $timeout = 30.0): Asyncara\Future
query(string $sql, array $parameters = []): Asyncara\Future
execute(string $sql, array $parameters = []): Asyncara\Future
prepare(string $sql): Asyncara\MySQL\Statement
transaction(callable $body): Asyncara\Future
close(): void
__destruct()
```

## `Asyncara\MySQL\Statement`

```php
__construct(Asyncara\MySQL\Connection $connection, string $sql)
execute(array $parameters = []): Asyncara\Future
```

## `Asyncara\Pool\ConnectionPool`

```php
__construct(Closure $factory, Closure $closer, int $size = 8, int $pendingLimit = 1024)
withConnection(callable $body): mixed
statistics(): array
close(): void
```

## `Asyncara\Pool\ProcessPool`

```php
__construct(int $workers, string $bootstrap, int $queueLimit = 256, int $queueBytes = 67108864, int $workerMemoryBytes = 268435456)
submit(array|string $job, array $arguments = [], float $timeout = 30.0): Asyncara\Future
statistics(): array
close(): void
__destruct()
```

## `Asyncara\PostgreSQL\Connection`

```php
static connect(array $options, float $timeout = 30.0, int $notificationLimit = 1024, int $notificationByteLimit = 1048576): Asyncara\Future
query(string $sql, array $parameters = []): Asyncara\Future
execute(string $sql, array $parameters = []): Asyncara\Future
prepare(string $sql): Asyncara\PostgreSQL\Statement
transaction(callable $body): Asyncara\Future
nextNotification(): array
statistics(): array
close(): void
__destruct()
```

## `Asyncara\PostgreSQL\Statement`

```php
__construct(Asyncara\PostgreSQL\Connection $connection, string $sql)
execute(array $parameters = []): Asyncara\Future
```

## `Asyncara\Redis\Connection`

```php
static connect(string $host = '127.0.0.1', int $port = 6379, ?string $password = NULL, ?string $user = NULL, bool $resp3 = false, float $timeout = 30.0, bool $tls = false, ?string $caFile = NULL): Asyncara\Future
command(array $command): Asyncara\Future
pipeline(array $commands): Asyncara\Future
transaction(array $commands): Asyncara\Future
subscribe(array $channels): Asyncara\Future
nextMessage(): array
close(): void
```

## `Asyncara\Redis\ServerException`

Exception or interface type; inherited standard behavior.

## `Asyncara\Runtime`

```php
static configure(array $options): void
static configuration(): array
static information(): object
static supportsUserlandThreads(): bool
static enableHooks(int $flags = 1, string $policy = 'allow'): Asyncara\HookReport
static timeHooksEnabled(): bool
static strictBlockingDetection(bool $enabled): void
static diagnostics(): array
```

## `Asyncara\SQLite\Connection`

```php
static open(string $path): Asyncara\Future
query(string $sql, array $parameters = []): Asyncara\Future
execute(string $sql, array $parameters = []): Asyncara\Future
prepare(string $sql): Asyncara\SQLite\Statement
beginTransaction(): Asyncara\Future
commit(): Asyncara\Future
rollback(): Asyncara\Future
transaction(callable $body): Asyncara\Future
close(): Asyncara\Future
```

## `Asyncara\SQLite\Statement`

```php
__construct(Asyncara\SQLite\Connection $connection, string $sql)
execute(array $parameters = []): Asyncara\Future
```

## `Asyncara\Scheduler`

```php
__construct(int $taskLimit = 20000)
static tryCurrent(): ?Asyncara\Scheduler
static current(): Asyncara\Scheduler
currentCancellation(): ?Asyncara\CancellationToken
static now(): float
spawn(callable $body, bool $inherit = true): Asyncara\Task
suspend(Closure $register, ?Asyncara\CancellationToken $token = NULL): mixed
delay(float $seconds, Closure $callback, bool $waitTimer = false): Asyncara\Timer
readable(mixed $stream): void
writable(mixed $stream): void
waitDescriptor(int $fd, int $events, ?float $timeout = NULL): int
native(string $op, array $args): Asyncara\Future
run(callable $body): mixed
statistics(): array
```

## `Asyncara\Stream\AsyncStream`

```php
static fromProducer(callable $producer, int $capacity = 16): Asyncara\Stream\AsyncStream
getIterator(): Traversable
consume(callable $consumer): void
close(): void
```

## `Asyncara\Stream\Emitter`

```php
__construct(Asyncara\Concurrency\Queue $queue)
emit(mixed $item): void
```

## `Asyncara\Stream\ReadableStream`

```php
consume(callable $consumer): void
close(): void
```

## `Asyncara\Task`

```php
isRunning(): bool
cancel(): void
```

## `Asyncara\TaskGroup`

```php
spawn(callable $body): Asyncara\Task
cancel(): void
```

## `Asyncara\TaskResult`

```php
__construct(mixed $value, ?Throwable $error)
static from(Asyncara\Future $future): Asyncara\TaskResult
```

## `Asyncara\Timer`

```php
__construct(Closure $remove)
cancel(): void
isActive(): bool
```

## `Asyncara\WaitResult`

```php
__construct(array $completed, array $pending)
```

## `Asyncara\WebSocket\Client`

```php
static connect(string $url, array $protocols = [], array $headers = [], ?string $caFile = NULL, float $timeout = 10.0, int $messageLimit = 1048576, int $queueLimit = 16, int $queueBytes = 4194304, float $idleTimeout = 30.0): Asyncara\Future
```

## `Asyncara\WebSocket\Connection`

```php
receive(): ?Asyncara\WebSocket\Message
send(string $data, bool $binary = false): void
ping(?string $payload = NULL, float $timeout = 5.0): Asyncara\Future
close(int $code = 1000, string $reason = '', float $timeout = 5.0): void
abort(): void
exception(): ?Throwable
statistics(): array
```

## `Asyncara\WebSocket\FrameCodec`

```php
__construct(bool $expectMasked, int $limit = 1048576)
push(string $bytes): void
clear(): void
next(): ?array
static encode(int $opcode, string $data, bool $masked, bool $fin = true): string
static utf8(string $value): bool
static closePayload(string $data): array
```

## `Asyncara\WebSocket\Handshake`

```php
static read(Asyncara\IO\TcpStream $stream): array
static one(array $headers, string $name): string
static tokens(array $headers, string $name): array
static upgrade(array $headers): void
static accept(string $key): string
static protocols(array $protocols): void
```

## `Asyncara\WebSocket\Message`

```php
__construct(string $data, bool $binary = false)
```

## `Asyncara\WebSocket\ProtocolException`

```php
__construct(string $message, int $closeCode = 1002)
```

## `Asyncara\WebSocket\Server`

```php
static accept(Asyncara\IO\TcpStream $stream, array $origins = [], array $protocols = [], ?callable $authorize = NULL, float $timeout = 5.0, int $messageLimit = 1048576, int $queueLimit = 16, int $queueBytes = 4194304, float $idleTimeout = 30.0): Asyncara\Future
```
