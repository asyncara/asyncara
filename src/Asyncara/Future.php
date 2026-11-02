<?php
declare(strict_types=1);
namespace Asyncara;
use Asyncara\Exception\CancelledException;
class Future implements Awaitable
{
    private bool $complete = false;
    private mixed $value = null;
    private ?\Throwable $error = null;
    private array $listeners = [];
    private int $next = 0;
    /** @internal */ public bool $observed = false;
    /** @internal */ public ?\Closure $canceller = null;
    public function isCompleted(): bool { return $this->complete; }
    public function isCancelled(): bool { return $this->error instanceof CancelledException; }
    public function exception(): ?\Throwable { $this->observed = true; return $this->error; }
    public function result(): mixed
    {
        $this->observed = true;
        if (!$this->complete) throw new \LogicException('Future has not completed');
        if ($this->error) throw $this->error;
        return $this->value;
    }
    public function await(?CancellationToken $cancellation = null): mixed
    {
        $this->observed = true;
        $cancellation ??= Scheduler::tryCurrent()?->currentCancellation();
        $cancellation?->throwIfRequested();
        if (!$this->complete) {
            Scheduler::current()->suspend(function (\Closure $resume): \Closure {
                return $this->subscribe(static fn() => $resume(null, null));
            }, $cancellation);
        }
        return $this->result();
    }
    public function cancel(): void { if (!$this->complete && $this->canceller) ($this->canceller)(); }
    /** @internal Subscribers must not throw or suspend. */
    public function subscribe(\Closure $listener): \Closure
    {
        if ($this->complete) { $listener($this); return static function (): void {}; }
        $id = ++$this->next; $this->listeners[$id] = $listener;
        return function () use ($id): void { unset($this->listeners[$id]); };
    }
    /** @internal */
    public function resolve(mixed $value = null): void { $this->settle($value, null); }
    /** @internal */
    public function reject(\Throwable $error): void { $this->settle(null, $error); }
    private function settle(mixed $value, ?\Throwable $error): void
    {
        if ($this->complete) throw new \LogicException('Future already completed');
        $this->complete = true; $this->value = $value; $this->error = $error; $this->canceller = null;
        $listeners = $this->listeners; $this->listeners = [];
        foreach ($listeners as $listener) $listener($this);
    }
}
