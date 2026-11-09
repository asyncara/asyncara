<?php
declare(strict_types=1);
namespace Asyncara;
final class Runtime
{
    private static array $config = ['taskLimit' => 20000, 'futureLimit' => 40000, 'slowTaskSeconds' => 0.0, 'nativeMemoryLimit' => 268435456, 'httpMaxConnections' => 64, 'httpMaxHostConnections' => 8];
    private static bool $timeHooks = false;
    private static array $lastMetrics = [];
    public static function configure(array $options): void
    {
        if (Scheduler::tryCurrent()) throw new \LogicException('Configure before starting the runtime');
        foreach ($options as $key => $value) {
            if ($key === 'nativeMemoryLimit') {
                if (!is_int($value) || $value < 1048576 || $value > 1073741824) throw new \ValueError('nativeMemoryLimit must be 1 MiB..1 GiB');
                continue;
            }
            if (!array_key_exists($key, self::$config)) throw new \ValueError("Unknown runtime option: $key");
            if (str_starts_with($key, 'httpMax') && (!is_int($value) || $value < 1 || $value > 256)) throw new \ValueError('HTTP connection limit must be 1..256');
            if ($key === 'taskLimit' && (!is_int($value) || $value < 1 || $value > 100000)) throw new \ValueError('taskLimit must be 1..100000');
            if ($key === 'futureLimit' && (!is_int($value) || $value < 1 || $value > 1000000)) throw new \ValueError('futureLimit must be 1..1000000');
            if ($key === 'slowTaskSeconds' && (!is_numeric($value) || !is_finite((float)$value) || $value < 0)) throw new \ValueError('Invalid slow task threshold');
        }
        $combined = array_replace(self::$config, $options);
        if ($combined['httpMaxHostConnections'] > $combined['httpMaxConnections']) throw new \ValueError('Per-host HTTP limit exceeds total');
        if (isset($options['httpMaxConnections']) || isset($options['httpMaxHostConnections']))
            asyncara_http_limits($combined['httpMaxConnections'], $combined['httpMaxHostConnections']);
        if (isset($options['nativeMemoryLimit'])) asyncara_memory_limit($options['nativeMemoryLimit']);
        self::$config = array_replace(self::$config, $options);
    }
    public static function configuration(): array { return self::$config; }
    public static function information(): object { return (object)asyncara_info(); }
    public static function supportsUserlandThreads(): bool { return false; }
    public static function enableHooks(int $flags = Hook::ALL, string $policy = Hook::ALLOW_BLOCKING): HookReport
    {
        if (!in_array($policy, [Hook::ALLOW_BLOCKING, Hook::WARN, Hook::STRICT], true)) throw new \ValueError('Unknown hook policy');
        if ($flags & ~31) throw new \ValueError('Unknown hook flags');
        $missing = [];
        foreach ([Hook::HTTP => 'HTTP', Hook::STREAM => 'STREAM', Hook::FILESYSTEM => 'FILESYSTEM', Hook::DATABASE => 'DATABASE'] as $flag => $name) if ($flags & $flag) $missing[$name] = 'Transparent interception not implemented; use the explicit asynchronous API';
        if ($missing && $policy === Hook::STRICT) throw new \LogicException(implode('; ', array_keys($missing)) . ' hooks unavailable; arbitrary blocking calls cannot be detected');
        if ($missing && $policy === Hook::WARN) trigger_error('Asyncara unsupported hook groups: ' . implode(', ', array_keys($missing)), E_USER_WARNING);
        self::$timeHooks = (bool)($flags & Hook::TIME);
        if (Scheduler::tryCurrent()) asyncara_hooks(self::$timeHooks);
        return new HookReport(self::$timeHooks ? ['sleep', 'usleep'] : [], $missing);
    }
    public static function timeHooksEnabled(): bool { return self::$timeHooks; }
    public static function strictBlockingDetection(bool $enabled): void { self::$config['slowTaskSeconds'] = $enabled ? 0.05 : 0.0; }
    public static function diagnostics(): array { return Scheduler::tryCurrent()?->statistics() ?? self::$lastMetrics; }
    /** @internal */ public static function recordMetrics(array $metrics): void { self::$lastMetrics = $metrics; }
}
