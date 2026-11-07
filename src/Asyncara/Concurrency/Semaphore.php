<?php
declare(strict_types=1);
namespace Asyncara\Concurrency;
use Asyncara\{Future,Scheduler};
class Semaphore
{
    use \Asyncara\Internal\NonCopyable;

    private int $available, $held = 0, $next = 0;
    private array $waiters = [];
    public function __construct(private int $permits)
    {
        if ($permits < 1) throw new \ValueError('Semaphore permits must be positive'); $this->available = $permits;
    }
    public function acquire(): void
    {
        Scheduler::current()->currentCancellation()?->throwIfRequested();
        if ($this->available > 0) { $this->available--; $this->held++; return; }
        $id = ++$this->next; $future = new Future(); $this->waiters[$id] = $future;
        $remove = Scheduler::current()->currentCancellation()?->subscribe(function () use ($id): void { unset($this->waiters[$id]); });
        try { $future->await(); } finally { unset($this->waiters[$id]); if ($remove) $remove(); }
    }
    public function release(): void
    {
        if ($this->held <= 0) throw new \LogicException('Semaphore over-release');
        foreach ($this->waiters as $id => $future) { unset($this->waiters[$id]); $future->resolve(); return; }
        $this->held--; $this->available++;
    }
    public function withPermit(callable $body): mixed { $this->acquire(); try { return $body(); } finally { $this->release(); } }
}
