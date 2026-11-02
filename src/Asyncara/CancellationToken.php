<?php
declare(strict_types=1);
namespace Asyncara;
use Asyncara\Exception\CancelledException;
final class CancellationToken
{
    private ?CancelledException $reason = null;
    private array $listeners = [];
    private int $next = 0;
    public function isRequested(): bool { return $this->reason !== null; }
    public function throwIfRequested(): void { if ($this->reason) throw $this->reason; }
    /** @internal */
    public function request(?CancelledException $reason = null): void
    {
        if ($this->reason) return;
        $this->reason = $reason ?? new CancelledException('Task cancelled');
        $listeners = $this->listeners; $this->listeners = [];
        foreach ($listeners as $listener) $listener($this->reason);
    }
    /** @internal Returns an unsubscribe callback. */
    public function subscribe(\Closure $listener): \Closure
    {
        if ($this->reason) { $listener($this->reason); return static function (): void {}; }
        $id = ++$this->next; $this->listeners[$id] = $listener;
        return function () use ($id): void { unset($this->listeners[$id]); };
    }
}
