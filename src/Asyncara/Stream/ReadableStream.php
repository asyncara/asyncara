<?php
declare(strict_types=1);
namespace Asyncara\Stream;
/** An owned, single-consumer iterable whose close joins producer cleanup. */
interface ReadableStream extends \IteratorAggregate
{
    public function consume(callable $consumer): void;
    public function close(): void;
}
