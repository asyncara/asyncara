<?php
declare(strict_types=1);
namespace Asyncara\HTTP;

use Asyncara\{Future,Scheduler};
use Asyncara\Concurrency\Semaphore;
use Asyncara\Exception\CancelledException;

/** @internal Native completion owns the permit; no per-request Fiber is needed. */
final class BufferedRequest extends Future
{
    private bool $cancelled = false;
    public function __construct(private ?Semaphore $slots, private ?Scheduler $owner) { parent::__construct(); $owner->reserveOperation(); }
    public function cancel(): void
    {
        if (!$this->isCompleted()) { $this->cancelled = true; parent::cancel(); }
    }
    public function resolve(mixed $value = null): void
    {
        if ($this->cancelled) { $this->reject(new CancelledException('Operation cancelled')); return; }
        try { $response = new Response($value['status'], $value['body'], $value['headers']); }
        catch (\Throwable $error) { $this->reject($error); return; }
        $this->release();
        parent::resolve($response);
    }
    public function reject(\Throwable $error): void
    {
        $owner = $this->owner;
        $this->release();
        parent::reject($this->cancelled ? new CancelledException('Operation cancelled') : $error);
        $owner?->retainFailure($this);
    }
    private function release(): void
    {
        $slots = $this->slots;
        $this->owner?->releaseOperation();
        $this->slots = null; $this->owner = null;
        $slots?->release();
    }
}
