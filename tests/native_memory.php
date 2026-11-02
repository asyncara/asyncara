<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';

use Asyncara\{Async,Runtime};
use Asyncara\IO\Files;
use Asyncara\Exception\OperationException;

Runtime::configure(['nativeMemoryLimit' => 1048576]);
$path = tempnam(sys_get_temp_dir(), 'asyncara-memory-');
try {
    file_put_contents($path, str_repeat('x', 900000));
    Async::run(function () use ($path): void {
        $jobs = [];
        for ($i = 0; $i < 16; $i++) $jobs[] = Files::read($path);
        $failures = 0;
        foreach ($jobs as $job) {
            try { $job->await(); }
            catch (OperationException $e) {
                if (!str_contains($e->getMessage(), 'memory')) throw $e;
                $failures++;
            }
        }
        if (!$failures) throw new RuntimeException('Memory budget did not reject oversized concurrent buffers');
        if (Files::read(__FILE__)->await() === '') throw new RuntimeException('Runtime failed to recover');
    });
    $info = Runtime::information();
    if ($info->nativeMemoryBytes !== 0 || $info->pendingOperations !== 0 ||
        $info->nativeMemoryPeak > $info->nativeMemoryLimit || $info->nativeMemoryRejections < 1) {
        throw new RuntimeException('Native allocation accounting failed: '.json_encode($info));
    }
    try {
        Runtime::configure(['nativeMemoryLimit' => 2097152]);
        throw new RuntimeException('Changed memory budget after native work began');
    } catch (Exception $e) {
        if (!str_contains($e->getMessage(), 'before submitting')) throw $e;
    }
    echo "Native memory rejection, draining, recovery and accounting: PASS\n";
} finally { unlink($path); }
