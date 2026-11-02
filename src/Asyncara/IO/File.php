<?php
declare(strict_types=1);
namespace Asyncara\IO;

use Asyncara\{Async,Future,Scheduler};
use Asyncara\Concurrency\Semaphore;

/** Request-owned regular-file descriptor. Operations run in native workers. */
final class File
{
    private Semaphore $gate;
    private bool $closed = false;

    private function __construct(private int $id) { $this->gate = new Semaphore(1); }
    private function __clone() {}
    public function __serialize(): array { throw new \LogicException('Native file handles cannot be serialized'); }
    public function __unserialize(array $data): void { throw new \LogicException('Native file handles cannot be unserialized'); }

    public static function open(string $path, string $mode = 'r'): Future
    {
        return Async::spawn(static function () use ($path, $mode): self {
            $id = Async::shield(static fn() => Scheduler::current()->native('file_open', [$path, $mode])->await());
            try {
                Scheduler::current()->currentCancellation()?->throwIfRequested();
                return new self($id);
            } catch (\Throwable $error) {
                Async::shield(static fn() => Scheduler::current()->native('file_close', [$id])->await());
                throw $error;
            }
        });
    }

    private function operation(string $operation, array $arguments = []): Future
    {
        return Async::spawn(fn() => $this->gate->withPermit(function () use ($operation, $arguments): mixed {
            if ($this->closed) throw new \LogicException('File handle is closed');
            $future = Scheduler::current()->native($operation, [$this->id, ...$arguments]);
            $unsubscribe = Scheduler::current()->currentCancellation()?->subscribe(static fn() => $future->cancel());
            try { $value = Async::shield($future->await(...)); }
            finally { if ($unsubscribe) $unsubscribe(); }
            Scheduler::current()->currentCancellation()?->throwIfRequested();
            return $value;
        }));
    }

    /** Returns up to length bytes; empty string indicates EOF. */
    public function read(int $length = 65536): Future { return $this->operation('file_read', [$length]); }
    /** Writes all supplied bytes, preserving per-handle submission order. */
    public function write(string $data): Future { return $this->operation('file_write', [$data]); }
    public function seek(int $offset, int $origin = SEEK_SET): Future { return $this->operation('file_seek', [$offset, $origin]); }
    public function stat(): Future { return $this->operation('file_stat'); }
    public function sync(): Future { return $this->operation('file_sync'); }
    public function isClosed(): bool { return $this->closed; }

    public function close(): Future
    {
        return Async::spawn(fn() => $this->gate->withPermit(function (): void {
            if ($this->closed) return;
            $future = Scheduler::current()->native('file_close', [$this->id]);
            try { Async::shield($future->await(...)); }
            finally { $this->closed = true; }
        }));
    }
}
