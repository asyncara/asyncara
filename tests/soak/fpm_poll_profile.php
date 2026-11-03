<?php
declare(strict_types=1);

// Copied into a private evidence directory by fpm_trace.py. Never load this in
// an application: it adds measurement and synchronous diagnostic file writes.
namespace Asyncara {
    function asyncara_poll(array $readers, array $writers, int $timeout): array
    {
        $cpuBefore = \getrusage();
        $before = \hrtime(true);
        try {
            return \asyncara_poll($readers, $writers, $timeout);
        } finally {
            $elapsed = \hrtime(true) - $before;
            if ($elapsed > 50_000_000) {
                $after = \getrusage();
                $cpu = static fn(array $r): int =>
                    ($r['ru_utime.tv_sec'] + $r['ru_stime.tv_sec']) * 1_000_000
                    + $r['ru_utime.tv_usec'] + $r['ru_stime.tv_usec'];
                \file_put_contents(__DIR__ . '/native-polls.jsonl', \json_encode([
                    'pid' => \getmypid(), 'request' => $_SERVER['AUDIT_ID'] ?? null,
                    'unixSeconds' => \microtime(true), 'elapsedNs' => $elapsed,
                    'cpuUs' => $cpu($after) - $cpu($cpuBefore), 'timeoutMs' => $timeout,
                    'readers' => \count($readers), 'writers' => \count($writers),
                ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
            }
        }
    }
}

namespace {
    register_shutdown_function(static function (): void {
        $error = error_get_last();
        if ($error !== null && $error['type'] === E_ERROR) {
            file_put_contents(__DIR__ . '/fatal-requests.jsonl', json_encode([
                'pid' => getmypid(), 'request' => $_SERVER['AUDIT_ID'] ?? null,
                'unixSeconds' => microtime(true), 'monotonicNs' => hrtime(true),
                'timerSlackNs' => trim(file_get_contents('/proc/self/timerslack_ns')),
                'error' => $error,
            ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
        }
    });
}
