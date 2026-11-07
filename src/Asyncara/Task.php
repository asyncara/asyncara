<?php
declare(strict_types=1);
namespace Asyncara;
final class Task extends Future
{
    public readonly \Fiber $fiber;
    public readonly CancellationSource $cancellation;
    /** @internal */ public bool $queued = false;
    /** @internal */ public int $shield = 0;
    /** @internal */ public string $state = 'ready';
    /** @internal */ public array $context;
    /** @internal */
    public function __construct(public readonly int $id, private Scheduler $scheduler, callable $body, array $context)
    {
        parent::__construct();
        $this->context = $context;
        $this->cancellation = new CancellationSource();
        $this->fiber = new \Fiber(function () use ($body): void {
            try {
                $this->cancellation->token->throwIfRequested();
                $this->resolve($body());
            } catch (\Throwable $e) { $this->reject($e); }
            finally { $this->state = 'completed'; $this->scheduler->completed($this); }
        });
    }
    public function isRunning(): bool { return !$this->isCompleted(); }
    public function cancel(): void { if (!$this->isCompleted()) { if (Trace::$mask & Trace::SCHEDULER) \asyncara_trace_event(4,37,$this->id,0,0,0); $this->cancellation->cancel(); } }
    /** @internal */
    public function step(): void
    {
        $this->queued = false;
        if ($this->isCompleted()) return;
        $this->state = 'running';
        if (!$this->fiber->isStarted()) $this->fiber->start(); else $this->fiber->resume();
        if (!$this->isCompleted() && !$this->queued) $this->state = 'waiting';
    }
}
