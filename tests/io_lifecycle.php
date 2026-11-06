<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';

use Asyncara\{Async,CancellationSource,Runtime};
use Asyncara\HTTP\HttpClient;
use Asyncara\IO\Files;
use Asyncara\SQLite\Connection;
use Asyncara\Exception\{CancelledException,TimeoutException};

function demand(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function raises(string $type, callable $body): void {
    try { $body(); } catch (Throwable $error) { if ($error instanceof $type) return; throw $error; }
    throw new RuntimeException('Expected '.$type);
}
$failures = 0;
function test(string $name, callable $body): void {
    global $failures;
    try { $body(); echo "PASS $name\n"; }
    catch (Throwable $error) { $failures++; fwrite(STDERR, "FAIL $name: $error\n"); }
}
$root = dirname(__DIR__);
$path = tempnam(sys_get_temp_dir(), 'asyncara-lifecycle-');
$server = proc_open(['python3', __DIR__.'/fixtures/http_stream_server.py',
    "$root/build/test-certs/cert.pem", "$root/build/test-certs/key.pem"],
    [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['file', "$root/build/http-stream-server.log", 'a']], $pipes);
$ports = json_decode(fgets($pipes[1]), true, 512, JSON_THROW_ON_ERROR);
$url = 'http://127.0.0.1:'.$ports['http'];
try {
    test('SQLite connection identity cannot be cloned or serialized', static function (): void {
        Async::run(static function (): void {
            $db = Connection::open(':memory:')->await();
            try {
                raises(Error::class, static fn() => clone $db);
                raises(LogicException::class, static fn() => serialize($db));
                $serialized = sprintf('O:%d:"%s":0:{}', strlen(Connection::class), Connection::class);
                raises(LogicException::class, static fn() => unserialize($serialized));
                demand($db->query('SELECT 42 AS n')->await()[0]['n'] === 42, 'Rejected copy damaged live owner');
            } finally { $db->close()->await(); }
        });
        demand(Runtime::information()->sqliteConnections === 0, 'SQLite owner leaked');
    });
    test('HTTP cancellation drains native ownership before Task completion', static function () use ($url): void {
        Async::run(static function () use ($url): void {
            $client = new HttpClient(concurrency: 1);
            $request = $client->get($url.'/stall');
            Async::sleep(0); // Give the owned request task its first submission turn.
            demand(Runtime::information()->pendingOperations === 1, 'Missing pending native HTTP operation');
            $pendingAtCompletion = null;
            $request->subscribe(static function () use (&$pendingAtCompletion): void {
                $pendingAtCompletion = Runtime::information()->pendingOperations;
            });
            $request->cancel();
            raises(CancelledException::class, $request->await(...));
            demand($pendingAtCompletion === 0, 'HTTP Task completed while native result ownership remained');
            demand($client->get($url.'/empty')->await()->status === 204, 'Admission permit was not released');
        });
    });
    test('Cancelling a shared HTTP waiter preserves its producer and other waiter', static function () use ($url): void {
        Async::run(static function () use ($url): void {
            $client = new HttpClient();
            $stream = $client->getStream($url.'/headers-first');
            demand($stream->headers()->await()->status === 200, 'Header gate was not reached');
            $shared = Async::spawn(static function () use ($stream): string {
                try { return implode('', iterator_to_array($stream)); }
                finally { $stream->close(); }
            });
            $source = new CancellationSource();
            $waiter = Async::spawn(static fn() => $shared->await($source->token));
            $survivor = Async::spawn($shared->await(...));
            Async::sleep(.005);
            demand(!$shared->isCompleted(), 'Server must retain the body until explicit release');
            $source->cancel();
            raises(CancelledException::class, $waiter->await(...));
            demand(!$shared->isCompleted(), 'Waiter cancellation destroyed shared work');
            $client->get($url.'/release-body')->await();
            demand($survivor->await() === 'x' && $shared->await() === 'x' && $shared->await() === 'x', 'Repeated/shared result mismatch');
        });
    });
    test('Eager abandoned file operations drain and native results support repeated awaits', static function () use ($path): void {
        Async::run(static function () use ($path): void { Files::write($path, 'owned-result'); });
        Async::run(static function () use ($path): void {
            $read = Files::read($path);
            demand($read->await() === 'owned-result' && $read->await() === 'owned-result', 'Result lifetime mismatch');
        });
        demand(Runtime::information()->pendingOperations === 0 && Runtime::information()->nativeMemoryBytes === 0, 'Abandoned operation not drained');
    });
    test('Owned timeout joins SQLite cancellation before connection reuse', static function (): void {
        Async::run(static function (): void {
            $db = Connection::open(':memory:')->await();
            try {
                raises(TimeoutException::class, static fn() => Async::timeout(.005, static fn() => Async::await(
                    $db->query('WITH RECURSIVE c(x) AS (VALUES(1) UNION ALL SELECT x+1 FROM c WHERE x<100000000) SELECT sum(x) FROM c'))));
                demand($db->query('SELECT 7 AS n')->await()[0]['n'] === 7, 'Cancelled query still owns connection');
            } finally { $db->close()->await(); }
        });
    });
    test('File producer close joins bounded-buffer ownership cleanup', static function () use ($path): void {
        Async::run(static function () use ($path): void {
            Files::write($path, str_repeat('x', 1048576))->await();
            $stream = Files::readStream($path);
            foreach ($stream as $chunk) { demand(strlen($chunk) === 65536, 'Default file chunk changed'); break; }
            $stream->close();
            demand(Runtime::information()->openFileHandles === 0 && Runtime::information()->pendingOperations === 0, 'File producer close did not join cleanup');
        });
    });
    demand(Runtime::information()->nativeMemoryBytes === 0, 'Lifecycle suite retained native payloads');
} finally {
    unlink($path);
    fclose($pipes[0]); fclose($pipes[1]); proc_close($server);
}
exit($failures ? 1 : 0);
