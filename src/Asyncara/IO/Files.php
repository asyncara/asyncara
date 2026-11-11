<?php
declare(strict_types=1);
namespace Asyncara\IO;
use Asyncara\{Async,Future,Scheduler};
use Asyncara\Exception\OperationException;
use Asyncara\Exception\FilesystemException;
use Asyncara\Stream\AsyncStream;
use Asyncara\Stream\ReadableStream;
final class Files
{
    public static function open(string $path, string $mode = 'r'): Future { return File::open($path, $mode); }
    public static function read(string $path): Future { return Scheduler::current()->native('read', [$path]); }
    public static function write(string $path, string $data): Future { return Scheduler::current()->native('write', [$path, $data]); }
    /** Same-directory atomic visibility; durable additionally fsyncs file and directory. */
    public static function replaceAtomic(string $path, string $data, bool $durable = false, int $permissions = 0600): Future
    {
        return Scheduler::current()->native('atomic_write', [$path, $data, $durable, $permissions]);
    }
    public static function append(string $path, string $data): Future { return Scheduler::current()->native('append', [$path, $data]); }
    public static function delete(string $path): Future { return Scheduler::current()->native('delete', [$path]); }
    public static function rename(string $from, string $to): Future { return Scheduler::current()->native('rename', [$from, $to]); }
    public static function mkdir(string $path): Future { return Scheduler::current()->native('mkdir', [$path]); }
    public static function rmdir(string $path): Future { return Scheduler::current()->native('rmdir', [$path]); }
    public static function stat(string $path): Future { return Scheduler::current()->native('stat', [$path]); }
    public static function entries(string $path): Future { return Scheduler::current()->native('list', [$path]); }
    public static function copy(string $from, string $to): Future { return Scheduler::current()->native('copy', [$from, $to]); }
    public static function exists(string $path): Future
    {
        $path = \asyncara_file_path($path);
        return Async::spawn(static function () use ($path): bool {
            try { self::stat($path)->await(); return true; }
            catch (FilesystemException $e) { if ($e->isMissing()) return false; throw $e; }
        });
    }
    /** One stable descriptor, bounded producer backpressure and joined cleanup. */
    public static function readStream(string $path, int $chunkSize = 65536): AsyncStream
    {
        if ($chunkSize < 1 || $chunkSize > 16777216) throw new \ValueError('Invalid chunk size');
        $path = \asyncara_file_path($path);
        return AsyncStream::fromProducer(static function ($emitter) use ($path, $chunkSize): void {
            Async::using(
                static fn() => self::open($path),
                static function (File $file) use ($emitter, $chunkSize): void {
                    while (($data = Async::await($file->read($chunkSize))) !== '') $emitter->emit($data);
                },
                static fn(File $file) => $file->close(),
            );
        }, 2);
    }
    public static function writeStream(string $path, iterable $chunks): Future
    {
        try { $path = \asyncara_file_path($path); }
        catch (\Throwable $error) {
            // Preserve producer cleanup even when admission rejects the path.
            if ($chunks instanceof ReadableStream) $chunks->close();
            throw $error;
        }
        return Async::spawn(static function () use ($path, $chunks): int {
            try {
                return Async::using(
                    static fn() => self::open($path, 'w'),
                    static function (File $file) use ($chunks): int {
                        $bytes = 0;
                        foreach ($chunks as $chunk) {
                            if (!is_string($chunk)) throw new \TypeError('File chunks must be strings');
                            $bytes += Async::await($file->write($chunk));
                        }
                        return $bytes;
                    },
                    static fn(File $file) => $file->close(),
                );
            } finally { if ($chunks instanceof ReadableStream) $chunks->close(); }
        });
    }
}
