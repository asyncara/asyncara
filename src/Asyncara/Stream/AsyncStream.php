<?php
declare(strict_types=1);
namespace Asyncara\Stream;
use Asyncara\{Async,Task};
use Asyncara\Concurrency\Queue;
use Asyncara\Exception\QueueClosedException;
final class AsyncStream implements ReadableStream
{
    private Queue $queue;
    private Task $producer;
    private bool $iterated = false;
    private function __construct(callable $producer, int $capacity)
    {
        $this->queue = new Queue($capacity);
        $this->producer = Async::spawn(function () use ($producer): void { try { $producer(new Emitter($this->queue)); } finally { $this->queue->close(); } });
    }
    public static function fromProducer(callable $producer, int $capacity = 16): self { return new self($producer, $capacity); }
    /** @internal Producer completion for resource metadata and cleanup observers. */
    public function completion(): \Asyncara\Future { return $this->producer; }
    public function getIterator(): \Traversable
    {
        if ($this->iterated) throw new \LogicException('Stream can only be consumed once'); $this->iterated = true;
        try {
            while (true) {
                try { $value = $this->queue->get(); } catch (QueueClosedException) { break; }
                try { yield $value; } finally { $this->queue->taskDone(); }
            }
            $this->producer->await();
        } finally { $this->producer->cancel(); $this->queue->close(); }
    }
    public function consume(callable $consumer): void
    {
        try { foreach ($this as $value) $consumer($value); } finally { $this->close(); }
    }
    public function close(): void
    {
        $this->producer->cancel(); $this->queue->close();
        Async::shield(function (): void { try { $this->producer->await(); } catch (\Asyncara\Exception\CancelledException) {} });
    }
}
