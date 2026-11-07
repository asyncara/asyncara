<?php
declare(strict_types=1);
namespace Asyncara\Concurrency;
use Asyncara\{Future,Scheduler};
use Asyncara\Exception\QueueClosedException;
final class Queue
{
    use \Asyncara\Internal\NonCopyable;

    private \SplQueue $buffer;
    private array $readers = [], $writers = [];
    private int $next = 0, $unfinished = 0;
    private bool $closed = false;
    private Event $joined;
    /** Capacity 0 explicitly requests unbounded buffering. */
    public function __construct(private int $capacity = 100)
    {
        if ($capacity < 0) throw new \ValueError('Queue capacity must be nonnegative');
        $this->buffer = new \SplQueue(); $this->joined = new Event(true);
    }
    public function put(mixed $value): void
    {
        Scheduler::current()->currentCancellation()?->throwIfRequested();
        if ($this->tryPut($value)) return;
        $id = ++$this->next; $future = new Future(); $this->writers[$id] = [$value, $future];
        $remove = Scheduler::current()->currentCancellation()?->subscribe(function () use ($id): void { unset($this->writers[$id]); });
        try { $future->await(); } finally { unset($this->writers[$id]); if ($remove) $remove(); }
    }
    /** Non-suspending offer; safe in completion callbacks. */
    public function tryPut(mixed $value): bool
    {
        if ($this->closed) throw new QueueClosedException('Queue is closed');
        foreach ($this->readers as $id => $reader) { unset($this->readers[$id]); $this->accepted(); $reader->resolve($value); return true; }
        if (!$this->capacity || $this->buffer->count() < $this->capacity) { $this->accepted(); $this->buffer->enqueue($value); return true; }
        return false;
    }
    private function accepted(): void { $this->unfinished++; $this->joined->reset(); }
    public function get(): mixed
    {
        Scheduler::current()->currentCancellation()?->throwIfRequested();
        if (!$this->buffer->isEmpty()) {
            $value = $this->buffer->dequeue();
            foreach ($this->writers as $id => [$item, $writer]) { unset($this->writers[$id]); $this->accepted(); $this->buffer->enqueue($item); $writer->resolve(); break; }
            return $value;
        }
        if ($this->closed) throw new QueueClosedException('Queue is closed and drained');
        $id = ++$this->next; $future = new Future(); $this->readers[$id] = $future;
        $remove = Scheduler::current()->currentCancellation()?->subscribe(function () use ($id): void { unset($this->readers[$id]); });
        try { return $future->await(); } finally { unset($this->readers[$id]); if ($remove) $remove(); }
    }
    public function taskDone(): void
    {
        if ($this->unfinished < 1) throw new \LogicException('taskDone called too many times');
        if (--$this->unfinished === 0) $this->joined->set();
    }
    public function join(): void { $this->joined->wait(); }
    public function count(): int { return $this->buffer->count(); }
    public function statistics(): array
    {
        return ['capacity'=>$this->capacity, 'buffered'=>$this->count(), 'waitingReaders'=>count($this->readers), 'waitingWriters'=>count($this->writers), 'unfinished'=>$this->unfinished, 'closed'=>$this->closed];
    }
    public function close(): void
    {
        if ($this->closed) return; $this->closed = true;
        foreach ($this->readers as $reader) $reader->reject(new QueueClosedException('Queue closed'));
        foreach ($this->writers as [, $writer]) $writer->reject(new QueueClosedException('Queue closed'));
        $this->readers = $this->writers = [];
    }
}
