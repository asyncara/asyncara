<?php
declare(strict_types=1);
namespace Asyncara\Concurrency;
use Asyncara\{Future,Scheduler};
final class Event
{
    private Future $future;
    public function __construct(private bool $set = false) { $this->future = new Future(); if ($set) $this->future->resolve(); }
    public function set(): void { if (!$this->set) { $this->set = true; $this->future->resolve(); } }
    public function reset(): void { if ($this->set) { $this->set = false; $this->future = new Future(); } }
    public function isSet(): bool { return $this->set; }
    public function wait(): void { $this->future->await(); }
}
