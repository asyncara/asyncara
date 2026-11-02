<?php
declare(strict_types=1);
namespace Asyncara;
final class TaskResult
{
    public function __construct(public readonly mixed $value, public readonly ?\Throwable $error) {}
    public static function from(Future $future): self { try { return new self($future->await(), null); } catch (\Throwable $e) { return new self(null, $e); } }
}
