<?php
declare(strict_types=1);
namespace Asyncara\HTTP;
use Asyncara\Future;
use Asyncara\Stream\{AsyncStream,ReadableStream};
final class ResponseStream implements ReadableStream
{
    /** @internal */
    public function __construct(private AsyncStream $body, private Future $head)
    {
        $body->completion()->subscribe(static function (Future $done) use ($head): void {
            if ($head->isCompleted()) return;
            $observed = $done->observed; $error = $done->exception(); $done->observed = $observed;
            $head->reject($error ?? new \Asyncara\Exception\OperationException('HTTP stream ended without response headers'));
        });
    }
    /** Resolves to ResponseHead; await it before reading to inspect status/encoding. */
    public function headers(): Future { return $this->head; }
    public function getIterator(): \Traversable { return $this->body->getIterator(); }
    public function consume(callable $consumer): void { $this->body->consume($consumer); }
    public function close(): void { $this->body->close(); }
}
