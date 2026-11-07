<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Asyncara\{Async,Future,Runtime,CancellationSource};
use Asyncara\IO\{TcpStream,Files};
use Asyncara\Concurrency\{Event,Queue};

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejects(string $type, callable $body): void {
    try { $body(); } catch (Throwable $e) { if ($e instanceof $type) return; throw $e; }
    throw new RuntimeException('Expected '.$type);
}
function owner(object $object): void {
    rejects(Error::class, static fn() => clone $object);
    rejects(LogicException::class, static fn() => serialize($object));
}
// Each owner has state whose identity must not be duplicated. Reflection only
// inspects policy; real owners below verify that rejection preserves resources.
$owners = ['Scheduler','Future','Task','Timer','TaskGroup','CancellationToken',
    'Concurrency\\Queue','Concurrency\\Event','Concurrency\\Lock','Concurrency\\Semaphore',
    'IO\\TcpStream','IO\\TcpServer','IO\\UdpSocket','IO\\File','Stream\\AsyncStream',
    'HTTP\\HttpClient','HTTP\\ResponseStream','SQLite\\Connection','PostgreSQL\\Connection',
    'MySQL\\Connection','Redis\\Connection','WebSocket\\Connection','Pool\\ConnectionPool','Pool\\ProcessPool'];
foreach ($owners as $name) {
    $class = 'Asyncara\\'.$name; $reflection = new ReflectionClass($class);
    check(!$reflection->isCloneable(), "$class permits duplicate owner identity");
    check($reflection->hasMethod('__serialize') && $reflection->hasMethod('__unserialize'), "$class lacks serialization policy");
    rejects(LogicException::class, static fn() => unserialize(sprintf('O:%d:"%s":0:{}', strlen($class), $class)));
}
Async::run(static function (): void {
    foreach ([new Asyncara\Scheduler(), new Future(), new Event(), new Queue(),
        new Asyncara\TaskGroup(), new Asyncara\CancellationToken(), new Asyncara\Concurrency\Lock(),
        new Asyncara\Concurrency\Semaphore(1), new Asyncara\Timer(static function (): void {}),
        new Asyncara\HTTP\HttpClient(), Async::spawn(static fn() => 1)] as $object) owner($object);
    $server = new Asyncara\IO\TcpServer(); owner($server); $server->close(); $server->close();
    $udp = new Asyncara\IO\UdpSocket(); owner($udp); $udp->close(); $udp->close();
    $pool = new Asyncara\Pool\ProcessPool(1, __DIR__.'/fixtures/jobs.php'); owner($pool); $pool->close(); $pool->close();
    $leases = new Asyncara\Pool\ConnectionPool(static fn() => new stdClass(), static function (): void {}, 1);
    owner($leases); $leases->withConnection(static fn($value) => check($value instanceof stdClass, 'Lease copy rejection')); $leases->close();
    $producer = Asyncara\Stream\AsyncStream::fromProducer(static function ($emit): void { (clone $emit)->emit('value'); });
    owner($producer); $head = new Future(); $head->resolve(new Asyncara\HTTP\ResponseHead(200,''));
    $response = new Asyncara\HTTP\ResponseStream($producer, $head); owner($response);
    check(iterator_to_array($response) === ['value'], 'Shared emitter canonical queue'); $response->close();
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $stream = new TcpStream($pair[0]); $alias = $stream;
    $websocket = new Asyncara\WebSocket\Connection($stream, true); owner($websocket);
    rejects(Error::class, static fn() => clone $stream);
    rejects(LogicException::class, static fn() => serialize($stream));
    fwrite($pair[1], 'live'); check($alias->readExactly(4) === 'live', 'Rejected copy closed canonical descriptor');
    $pending = Async::spawn(static fn() => $stream->read());
    Async::sleep(0); $stream->close(); $alias->close();
    rejects(Asyncara\Exception\OperationException::class, $pending->await(...));
    fclose($pair[1]);

    $event = new Event(); $alias = $event;
    rejects(Error::class, static fn() => clone $event);
    $event->set(); $alias->set(); $alias->wait();
    $queue = new Queue(2); $alias = $queue;
    rejects(Error::class, static fn() => clone $queue);
    $queue->put('one'); check($alias->get() === 'one', 'Queue reference sharing'); $alias->taskDone(); $queue->join();
    check($queue->statistics()['unfinished'] === 0, 'Queue counter ownership'); $queue->close();

    $db = Asyncara\SQLite\Connection::open(':memory:')->await();
    $statement = $db->prepare('SELECT 42 AS n'); $copy = clone $statement;
    rejects(LogicException::class, static fn() => serialize($copy));
    check($copy->execute()->await()[0]['n'] === 42 && $statement->execute()->await()[0]['n'] === 42, 'Statement clones must retain canonical connection');
    $db->close()->await(); $db->close()->await();
    rejects(LogicException::class, static fn() => $copy->execute()->await());

    $source = new CancellationSource(); $other = clone $source;
    rejects(LogicException::class, static fn() => serialize($source));
    check($source->token === $other->token, 'Cancellation source clone must share canonical token');
    $shared = new Future(); $cancelled = Async::spawn(static fn() => $shared->await($source->token));
    $survivor = Async::spawn($shared->await(...)); Async::sleep(0); $other->cancel();
    rejects(Asyncara\Exception\CancelledException::class, $cancelled->await(...));
    check(!$shared->isCompleted(), 'Cancelling waiter settled shared operation');
    $shared->resolve('owned'); check($survivor->await() === 'owned' && $shared->await() === 'owned', 'Survivor result ownership');
});
$path = tempnam(sys_get_temp_dir(), 'asyncara-copy-');
try {
    Async::run(static function () use ($path): void {
        $file = Files::open($path, 'w+')->await(); $alias = $file;
        rejects(Error::class, static fn() => clone $file); rejects(LogicException::class, static fn() => serialize($file));
        $write = $file->write(str_repeat('x', 65536)); unset($file);
        $close = $alias->close(); unset($alias);
        check($write->await() === 65536, 'Pending job lost its handle owner'); $close->await();
    });
    $info = Runtime::information();
    check($info->pendingOperations === 0 && $info->openFileHandles === 0 && $info->sqliteConnections === 0 && $info->nativeMemoryBytes === 0, 'Resource ownership did not drain');
} finally { unlink($path); }
foreach ([new Asyncara\HTTP\Response(200,'value',''),new Asyncara\HTTP\ResponseHead(200,''),new Asyncara\WebSocket\Message('value')] as $value) {
    check(clone $value == $value && unserialize(serialize($value)) == $value, 'Value-copy semantics changed');
}
echo "PASS resource owner copy policies, reference sharing, waiter isolation, pending close and native cleanup\n";
