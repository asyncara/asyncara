<?php
declare(strict_types=1);
return [
    'sum' => static fn(int $n): int => array_sum(range(1, $n)),
    'cpu' => static function (int $rounds): array { $start = hrtime(true); $v = 'seed'; for ($i = 0; $i < $rounds; $i++) $v = hash('sha256', $v, true); return ['pid' => getmypid(), 'hash' => bin2hex($v), 'start' => $start, 'end' => hrtime(true)]; },
    'crash' => static function (): never { exit(23); },
    'fail' => static fn() => throw new RuntimeException('job failed'),
    'delay' => static function (int $microseconds): int { usleep($microseconds); return getmypid(); },
    'echo' => static fn(mixed $value): mixed => $value,
];
