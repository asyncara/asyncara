<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Asyncara\{Async,Runtime};
use Asyncara\IO\{File,Files};
use Asyncara\Concurrency\Event;
use Asyncara\Exception\{CancelledException,OperationException};

function verify(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
function fails(string $class, callable $body): void {
    try { $body(); } catch (Throwable $e) { if ($e instanceof $class) return; throw $e; }
    throw new RuntimeException('Expected '.$class);
}
$path = tempnam(sys_get_temp_dir(), 'asyncara-handle-');
$moved = $path.'.moved';
try {
    Async::run(function () use ($path, $moved): void {
        $file = Files::open($path, 'w+b')->await();
        try {
            $writes = [];
            for ($i = 0; $i < 100; $i++) $writes[] = $file->write(sprintf('%03d', $i));
            verify(array_sum(Async::gather($writes)) === 300, 'Ordered writes');
            $file->sync()->await();
            verify($file->stat()->await()['size'] === 300, 'Metadata');
            verify($file->seek(-3, SEEK_END)->await() === 297, 'Seek');
            verify($file->read(3)->await() === '099', 'Last ordered record');
            verify($file->read()->await() === '', 'EOF');
            Files::rename($path, $moved)->await();
            Files::write($path, 'replacement')->await();
            Files::delete($moved)->await();
            $file->seek(0)->await();
            verify($file->read(6)->await() === '000001', 'Handle changed when original path was replaced');
            fails(LogicException::class, fn() => serialize($file));
            fails(Error::class, fn() => clone $file);
        } finally { Async::shield(fn() => $file->close()->await()); }
        $file->close()->await();
        fails(LogicException::class, fn() => $file->read()->await());
        fails(ValueError::class, fn() => Files::open($path, 'bad')->await());
        fails(OperationException::class, fn() => Files::open('/dev/zero')->await());
        fails(OperationException::class, fn() => Files::open($path, 'x')->await());

        $append = Files::open($path, 'a')->await();
        $append->seek(0)->await();
        $append->write('!')->await();
        $append->close()->await();
        verify(Files::read($path)->await() === 'replacement!', 'Append mode');

        $handles = [];
        try {
            for ($i = 0; $i < 128; $i++) $handles[] = Files::open($path)->await();
            fails(ValueError::class, fn() => Files::open($path)->await());
            verify(Runtime::information()->openFileHandles === 128, 'Handle cap reporting');
        } finally { foreach ($handles as $handle) $handle->close()->await(); }

        $open = Files::open($path);
        Async::sleep(0);
        $open->cancel();
        fails(CancelledException::class, $open->await(...));
        verify(Runtime::information()->openFileHandles === 0, 'Cancelled acquisition retained handle');

        Files::write($path, str_repeat('a', 200000))->await();
        $stream = Files::readStream($path, 1024);
        $received = '';
        foreach ($stream as $chunk) {
            if ($received === '') {
                Files::rename($path, $moved)->await();
                Files::write($path, 'new')->await();
            }
            $received .= $chunk;
        }
        verify($received === str_repeat('a', 200000), 'Stream did not retain inode');
        $stream = Files::readStream($moved, 1024);
        foreach ($stream as $chunk) break;
        $stream->close();
        verify(Runtime::information()->openFileHandles === 0, 'Early stream exit leaked handle');

        $released = false;
        $entered = false;
        $acquiring = new Event();
        $finish = new Event();
        $owner = Async::spawn(function () use (&$released, &$entered, $acquiring, $finish): void {
                Async::using(
                    static fn() => Async::spawn(static function () use ($acquiring, $finish): int {
                        $acquiring->set(); $finish->wait(); return 42;
                    }),
                    function () use (&$entered): void { $entered = true; },
                    function (int $value) use (&$released): void { $released = $value === 42; },
                );
        });
        $acquiring->wait();
        $owner->cancel();
        $finish->set();
        fails(CancelledException::class, $owner->await(...));
        verify($released && !$entered, 'Cancellation during acquisition skipped release');
    });
    gc_collect_cycles();
    verify(Runtime::information()->openFileHandles === 0, 'Open handles retained');
    verify(Runtime::information()->nativeMemoryBytes === 0, 'Native allocations retained');
    echo "Stable file handles, ordering, seek/sync, limits, cancellation and streams: PASS\n";
} finally { @unlink($path); @unlink($moved); }
