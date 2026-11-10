<?php
declare(strict_types=1);
namespace Asyncara;
final class Task extends Future
{
    private bool $waitSettled = false, $waitSuspended = false;
    private ?\Throwable $waitError = null;
    private ?\Closure $completionObserver = null, $cancelObserver = null;
    public readonly \Fiber $fiber;
    public readonly CancellationSource $cancellation;
    /** @internal */ public bool $queued = false;
    /** @internal */ public int $shield = 0;
    /** @internal */ public string $state = 'ready';
    /** @internal */ public array $context;
    /** @internal */
    public function __construct(public readonly int $id, private Scheduler $scheduler, #[\SensitiveParameter] callable $body, array $context)
    {
        parent::__construct();
        $this->context = $context;
        $this->cancellation = new CancellationSource();
        $this->fiber = new \Fiber(function () use ($body): void {
            try {
                $this->cancellation->token->throwIfRequested();
                $this->resolve($body());
            } catch (\Throwable $e) { $this->reject($e); }
            finally { $this->completionObserver = $this->cancelObserver = null; $this->state = 'completed'; $this->scheduler->completed($this); }
        });
    }
    /** @internal One suspended wait per Task, reusable observers never own an operation. */
    public function waitFuture(Future $future, ?CancellationToken $token): void
    {
        $token?->throwIfRequested();
        $this->waitSettled = $this->waitSuspended = false; $this->waitError = null;
        $this->completionObserver ??= fn() => $this->resumeFutureWait(null);
        $this->cancelObserver ??= fn(Exception\CancelledException $error) => $this->resumeFutureWait($error);
        $listener = $future->attach($this->completionObserver); $cancel = null;
        try {
            $cancel = $token?->attach($this->cancelObserver);
            if (!$this->waitSettled) {
                $this->waitSuspended = true;
                if (Trace::$mask & Trace::SCHEDULER) $this->scheduler->traceFutureWait($this, 34);
                \Fiber::suspend();
                if (Trace::$mask & Trace::SCHEDULER) $this->scheduler->traceFutureWait($this, 35);
            }
            if ($this->waitError) throw $this->waitError;
        } finally {
            $future->detach($listener);
            if ($cancel !== null) $token->detach($cancel);
            $this->waitSuspended = false; $this->waitError = null;
        }
    }
    private function resumeFutureWait(?\Throwable $error): void
    {
        if ($this->waitSettled) return;
        $this->waitSettled = true; $this->waitError = $error;
        if (Trace::$mask & Trace::SCHEDULER) \asyncara_trace_event(4,38,$this->id,(int)$this->waitSuspended,(int)($error !== null),0);
        if ($this->waitSuspended) $this->scheduler->enqueue($this);
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
