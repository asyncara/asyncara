<?php
declare(strict_types=1);
namespace Asyncara;

/** Optional, request-local numeric provenance. Extraction is a deferred action. */
final class Trace
{
    public const WAIT = 1, JOB = 2, SCHEDULER = 4, TIMER = 8, IO = 16, LIFECYCLE = 32, MARK = 64, ALL = 127;
    /** @internal Hot-path guard; configure through enable/disable only. */
    public static int $mask = 0;
    private static int $capacity = 1024, $scope = 0;

    public static function enable(int $capacity = 1024, int $classes = self::ALL): void
    {
        if (Scheduler::tryCurrent()) throw new \LogicException('Configure diagnostics outside Async::run');
        \asyncara_trace_configure($capacity, $classes);
        self::$capacity = $capacity; self::$mask = $classes;
    }
    public static function disable(): void
    {
        \asyncara_trace_configure(self::$capacity, 0); self::$mask = 0;
    }
    /** Mark a numeric reason without data or I/O; optionally freeze the ring. */
    public static function mark(int $reason, bool $freeze = false): void
    {
        if (self::$mask & self::MARK) \asyncara_trace_event(self::MARK, 80, $reason, 0, 0, 0);
        if ($freeze) self::disable();
    }
    /** @internal Separate IDs of successive schedulers in one PHP request. */
    public static function begin(): void
    {
        \asyncara_trace_event(self::LIFECYCLE, 30, ++self::$scope, 0, 0, 0);
    }
    public static function snapshot(): array
    {
        if (Scheduler::tryCurrent()) throw new \LogicException('Extract diagnostics after Async::run finishes');
        return \asyncara_trace_snapshot() + ['columns' => ['sequence','monotonicNs','scope','category','event','id','a','b','c']];
    }
    /** Caller owns this stream. Writes may block: never called from dispatch. */
    public static function writeTo(mixed $stream): int
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') throw new \ValueError('Expected output stream');
        $data = json_encode(self::snapshot(), JSON_THROW_ON_ERROR)."\n";
        if (strlen($data) > 4194304) throw new \OverflowException('Diagnostic extraction exceeds bound');
        $offset = 0;
        while ($offset < strlen($data)) {
            $written = @fwrite($stream, substr($data, $offset, 65536));
            if ($written === false || $written === 0) throw new \RuntimeException('Incomplete diagnostic output');
            $offset += $written;
        }
        if (!@fflush($stream)) throw new \RuntimeException('Diagnostic output flush failed');
        if (self::$mask & self::LIFECYCLE) \asyncara_trace_event(self::LIFECYCLE, 4, 0, $offset, 0, 0);
        return $offset;
    }
    public static function dump(string $path): int
    {
        if (Scheduler::tryCurrent()) throw new \LogicException('Extract diagnostics after Async::run finishes');
        $output = @fopen($path, 'xb');
        if ($output === false) throw new \RuntimeException('Cannot create diagnostic output (existing files are preserved)');
        try { return self::writeTo($output); } finally { fclose($output); }
    }
}
