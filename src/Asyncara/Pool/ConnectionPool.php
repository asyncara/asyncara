<?php
declare(strict_types=1);
namespace Asyncara\Pool;
use Asyncara\{Async,Awaitable,Future};
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
        $connection = $this->idle->get(); $this->leased++;
        try {
            if ($connection === null) { $connection = ($this->factory)(); if ($connection instanceof Awaitable) $connection = $connection->await(); }
            return $body($connection);
        } catch (\Throwable $e) {
            if ($connection !== null && !$connection instanceof Awaitable) Async::shield(function () use ($connection): void { $result = ($this->closer)($connection); if ($result instanceof Awaitable) $result->await(); });
            $connection = null; throw $e;
        } finally { $this->leased--; $this->idle->taskDone(); $this->idle->tryPut($connection); }
    }
    public function close(): void
    {
        if ($this->closed) return;
        if ($this->leased) throw new \LogicException('Join borrowers before closing the pool');
        $this->closed = true;
        Async::shield(function (): void { while ($this->idle->count()) { $connection = $this->idle->get(); $this->idle->taskDone(); if ($connection !== null) { $result = ($this->closer)($connection); if ($result instanceof Awaitable) $result->await(); } } });
        $this->idle->close();
    }
}
