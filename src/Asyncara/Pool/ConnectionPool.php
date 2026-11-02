<?php
declare(strict_types=1);
namespace Asyncara\Pool;
use Asyncara\{Async,Awaitable,Future,Scheduler};
use Asyncara\Concurrency\Queue;
/** Explicit scoped leases; factory and closer execute on the borrowing task. */
final class ConnectionPool
{
    private Queue $idle;
    private int $leased = 0;
    private bool $closed = false;
    public function __construct(private \Closure $factory, private \Closure $closer, int $size = 8)
    {
        if ($size < 1 || $size > 256) throw new \ValueError('Pool size must be 1..256');
        $this->idle = new Queue($size); for ($i = 0; $i < $size; $i++) $this->idle->put(null);
    }
    public function withConnection(callable $body): mixed
    {
        if ($this->closed) throw new \LogicException('Connection pool closed');
        // Count queued acquisitions too: a resolved Queue waiter can own a
        // connection before its Fiber resumes. close() must not overlook it.
        $this->leased++;
        try {
            $connection = $this->idle->get();
            try {
                if ($connection === null) $connection = Async::shield(function (): mixed {
                    $value = ($this->factory)(); return $value instanceof Awaitable ? $value->await() : $value;
                });
                Scheduler::current()->currentCancellation()?->throwIfRequested();
                return $body($connection);
            } catch (\Throwable $e) {
                $discard = $connection; $connection = null;
                if ($discard !== null) Async::shield(function () use ($discard): void { $result = ($this->closer)($discard); if ($result instanceof Awaitable) $result->await(); });
                throw $e;
            } finally { $this->idle->taskDone(); $this->idle->tryPut($connection); }
        } finally { $this->leased--; }
    }
    public function close(): void
    {
        if ($this->closed) return;
        if ($this->leased) throw new \LogicException('Join borrowers before closing the pool');
        $this->closed = true;
        $failure = null;
        try { Async::shield(function () use (&$failure): void {
            while ($this->idle->count()) {
                $connection = $this->idle->get(); $this->idle->taskDone();
                if ($connection === null) continue;
                try { $result = ($this->closer)($connection); if ($result instanceof Awaitable) $result->await(); }
                catch (\Throwable $error) { $failure ??= $error; }
            }
        }); } finally { $this->idle->close(); }
        if ($failure) throw $failure;
    }
}
