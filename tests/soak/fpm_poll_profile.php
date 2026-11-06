<?php
declare(strict_types=1);

// Copied into a private evidence directory by fpm_trace.py. Never load this in
// an application: it adds measurement and synchronous diagnostic file writes.
namespace Asyncara {
    function asyncara_profile_record(string $name, array $record): void
    {
        $line = \json_encode($record, JSON_THROW_ON_ERROR) . "\n";
        if (\file_put_contents(__DIR__ . '/' . $name, $line, FILE_APPEND | LOCK_EX) !== \strlen($line)) {
            // Test instrumentation must not silently accept missing evidence.
            // Preserve PHP's warning and make this a failed diagnostic request.
            throw new \RuntimeException('Asyncara poll profile failed: cannot append ' . $name);
        }
    }

    function asyncara_poll(array $readers, array $writers, int $timeout): array
    {
        $cpuBefore = \getrusage();
        $before = \hrtime(true);
        try {
            return \asyncara_poll($readers, $writers, $timeout);
        } finally {
            $finished = \hrtime(true);
            $elapsed = $finished - $before;
            if ($elapsed > 50_000_000) {
                $after = \getrusage();
                $cpu = static fn(array $r): int =>
                    ($r['ru_utime.tv_sec'] + $r['ru_stime.tv_sec']) * 1_000_000
                    + $r['ru_utime.tv_usec'] + $r['ru_stime.tv_usec'];
                asyncara_profile_record('native-polls.jsonl', [
                    'pid' => \getmypid(), 'request' => $_SERVER['AUDIT_ID'] ?? null,
                    'unixSeconds' => \microtime(true), 'elapsedNs' => $elapsed,
                    'startedMonotonicNs' => $before, 'finishedMonotonicNs' => $finished,
                    'cpuUs' => $cpu($after) - $cpu($cpuBefore), 'timeoutMs' => $timeout,
                    'readers' => \count($readers), 'writers' => \count($writers),
                ]);
            }
        }
    }
}

namespace {
    register_shutdown_function(static function (): void {
        $error = error_get_last();
        if ($error !== null && $error['type'] === E_ERROR) {
            \Asyncara\asyncara_profile_record('fatal-requests.jsonl', [
                'pid' => getmypid(), 'request' => $_SERVER['AUDIT_ID'] ?? null,
                'unixSeconds' => microtime(true), 'monotonicNs' => hrtime(true),
                'timerSlackNs' => trim(file_get_contents('/proc/self/timerslack_ns')),
                'error' => $error,
            ]);
        }
    });
}
