<?php
declare(strict_types=1);
namespace Asyncara;
final class Timer
{
    private bool $active = true;
    public function __construct(private \Closure $remove) {}
    public function cancel(): void { if ($this->active) { $this->active = false; ($this->remove)(); } }
    public function isActive(): bool { return $this->active; }
}
