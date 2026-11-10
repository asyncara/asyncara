<?php
declare(strict_types=1);
namespace Asyncara\Server;

/** One response owns one producer; assignment shares that owner, cloning does not. */
final class StreamResponse
{
    use \Asyncara\Internal\NonCopyable;
    private bool $used = false;
    public function __construct(private iterable $chunks, public readonly int $status = 200, public readonly array $headers = [])
    {
        if ($status < 200 || $status > 599 || in_array($status, [204, 205, 304], true)) throw new \ValueError('Streaming requires a response status permitting a body');
    }
    /** @internal Demand arrives from Hyper through App, never through busy polling. */
    public function send(int $id, Inbox $inbox): void
    {
        if ($this->used) throw new \LogicException('A streaming response can only be consumed once');
        $this->used = true;
        $iterator = (function (): \Generator { yield from $this->chunks; })();
        $started = false;
        try {
            while (true) {
                $event = $inbox->take();
                if ($event['kind'] !== 5 || $event['id'] !== $id) throw new \LogicException('Unexpected stream demand');
                if (!$started) { $iterator->rewind(); $started = true; } else $iterator->next();
                $empty = 0;
                while ($iterator->valid() && $iterator->current() === '') {
                    if (++$empty % 256 === 0) \Asyncara\Async::sleep(0);
                    $iterator->next();
                }
                if (!$iterator->valid()) { \asyncara_server_chunk($id, '', 1); return; }
                $chunk = $iterator->current();
                if (!is_string($chunk)) throw new \TypeError('Response stream chunks must be strings');
                if (!\asyncara_server_chunk($id, $chunk, 0)) throw new \Asyncara\Exception\CancelledException('Response connection closed');
            }
        } finally {
            // Release a suspended generator (and its finally cleanup) even if
            // the application retains the response object after disconnect.
            unset($iterator); $this->close();
        }
    }
    /** Release/join an eager Asyncara ReadableStream even if never iterated. */
    public function close(): void
    {
        $this->used = true;
        try {
            if ($this->chunks instanceof \Asyncara\Stream\ReadableStream) $this->chunks->close();
        } finally { $this->chunks = []; }
    }
}
