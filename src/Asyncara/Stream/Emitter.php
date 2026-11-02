<?php
declare(strict_types=1);
namespace Asyncara\Stream;
use Asyncara\Concurrency\Queue;
final class Emitter
{
    public function __construct(private Queue $queue) {}
    public function emit(mixed $item): void { $this->queue->put($item); }
}
