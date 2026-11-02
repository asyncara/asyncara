<?php
declare(strict_types=1);
namespace Asyncara;
final class HookReport
{
    public function __construct(private array $available, private array $missing) {}
    public function enabled(): array { return $this->available; }
    public function unsupported(): array { return array_keys($this->missing); }
    public function reasons(): array { return $this->missing; }
}
