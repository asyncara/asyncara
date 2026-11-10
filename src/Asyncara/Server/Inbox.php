<?php
declare(strict_types=1);
namespace Asyncara\Server;
use Asyncara\Scheduler;
/** @internal One connection, one consumer, at most one queued HTTP/1 request. */
final class Inbox
{
    use \Asyncara\Internal\NonCopyable;
    private ?array $item = null;
    private ?\Closure $resume = null;
    public function put(array $event): void
    {
        if ($this->resume !== null) { $resume = $this->resume; $this->resume = null; $resume($event); return; }
        if ($this->item !== null) throw new \LogicException('HTTP/1 connection dispatched concurrent requests');
        $this->item = $event;
    }
    public function take(): array
    {
        if ($this->item !== null) { $value = $this->item; $this->item = null; return $value; }
        $scheduler = Scheduler::current();
        return $scheduler->suspend(function (\Closure $resume): \Closure {
            $this->resume = $resume;
            return function (): void { $this->resume = null; };
        }, $scheduler->currentCancellation());
    }
}
