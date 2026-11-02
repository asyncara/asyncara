<?php
declare(strict_types=1);
namespace Asyncara\Concurrency;
use Asyncara\Scheduler;
final class Lock
{
    private Semaphore $semaphore;
    private ?int $owner = null;
    public function __construct() { $this->semaphore = new Semaphore(1); }
    public function acquire(): void
    {
        $id = Scheduler::current()->task?->id ?? throw new \LogicException('Lock requires a task');
        if ($this->owner === $id) throw new \LogicException('Lock is not reentrant');
        $this->semaphore->acquire(); $this->owner = $id;
    }
    public function release(): void
    {
        if ($this->owner === null || $this->owner !== Scheduler::current()->task?->id) throw new \LogicException('Only the owning task may release a lock');
        $this->owner = null; $this->semaphore->release();
    }
    public function synchronized(callable $body): mixed { $this->acquire(); try { return $body(); } finally { $this->release(); } }
}
