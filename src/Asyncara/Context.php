<?php
declare(strict_types=1);
namespace Asyncara;
final class Context
{
    private static function task(): Task { return Scheduler::current()->task ?? throw new \LogicException('Context requires a task'); }
    public static function set(string $key, mixed $value): void { self::task()->context[$key] = $value; }
    public static function get(string $key, mixed $default = null): mixed { return self::task()->context[$key] ?? $default; }
    public static function all(): array { return self::task()->context; }
    public static function isolated(callable $body, array $values = []): mixed
    {
        $task = self::task(); $old = $task->context; $task->context = $values;
        try { return $body(); } finally { $task->context = $old; }
    }
}
