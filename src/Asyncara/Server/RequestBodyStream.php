<?php
declare(strict_types=1);
namespace Asyncara\Server;

/** @internal A single consumer scoped to the current handler, before its reply. */
final class RequestBodyStream implements \Asyncara\Stream\ReadableStream
{
    use \Asyncara\Internal\NonCopyable;
    private bool $started = false, $closed = false;
    private ?\Asyncara\Task $owner;
    public function __construct(private readonly int $id, private ?Inbox $inbox)
    {
        $this->owner = \Asyncara\Scheduler::current()->task;
    }
    public function getIterator(): \Traversable
    {
        if ($this->started || $this->closed) throw new \LogicException('Request input is single-use and handler-scoped');
        if (\Asyncara\Scheduler::current()->task !== $this->owner) throw new \LogicException('Only the request handler may consume its input');
        $this->started = true;
        try {
            while (true) {
                if ($this->closed || \Asyncara\Scheduler::current()->task !== $this->owner) throw new \LogicException('Only the request handler may consume its input');
                $result = \asyncara_server_input($this->id);
                if ($result === 2) return;
                if ($result === 0) throw new \Asyncara\Exception\CancelledException('Request connection closed');
                $event = $this->inbox->take();
                if ($event['id'] !== $this->id) throw new \LogicException('Unexpected request input event');
                if ($event['kind'] === 7) return;
                if ($event['kind'] !== 6) throw new RequestBodyException(match ($event['kind']) { 8 => 400, 9 => 413, 10 => 503, default => 500 });
                yield $event['body'];
            }
        } finally { $this->close(); }
    }
    public function consume(callable $consumer): void
    {
        if ($this->closed || \Asyncara\Scheduler::current()->task !== $this->owner) throw new \LogicException('Only the request handler may consume its input');
        try { foreach ($this as $chunk) $consumer($chunk); }
        finally { $this->close(); }
    }
    public function close(): void
    {
        if ($this->closed) return;
        if (\Asyncara\Scheduler::tryCurrent()?->task !== $this->owner) throw new \LogicException('Only the request handler may close its input');
        // Pending owns Incoming until a response or connection teardown. An
        // early response will close, never silently reuse partially read input.
        $this->closed = true; $this->owner = null; $this->inbox = null;
    }
}
