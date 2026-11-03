<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Asyncara\Internal\IpcFrame;
// Private inherited pipes only. Bootstrap is trusted local application code.
function readExact(int $bytes): ?string {
    $data = '';
    while (strlen($data) < $bytes) { $part = fread(STDIN, $bytes - strlen($data)); if ($part === false || $part === '') return null; $data .= $part; }
    return $data;
}
function sendFrame(array $data): void {
    $json = IpcFrame::encode($data);
    $frame = pack('N', strlen($json)) . $json; $offset = 0;
    while ($offset < strlen($frame)) { $n = fwrite(STDOUT, substr($frame, $offset)); if ($n === false || $n === 0) exit(74); $offset += $n; }
    fflush(STDOUT);
}
try {
    ob_start(); $registry = require $argv[1]; ob_end_clean();
    if (!is_array($registry)) throw new RuntimeException('Bootstrap must return a job registry');
    sendFrame(['ready' => true, 'pid' => getmypid()]);
    while (($header = readExact(4)) !== null) {
        $length = IpcFrame::length($header);
        $json = readExact($length); if ($json === null) exit(65);
        $job = IpcFrame::decode($json);
        if (!isset($job['id'],$job['job'],$job['arguments']) || !is_int($job['id']) || $job['id']<1 || !is_string($job['job']) || !is_array($job['arguments'])) throw new RuntimeException('Invalid job descriptor');
        try {
            if (!isset($registry[$job['job']]) || !is_callable($registry[$job['job']])) throw new RuntimeException('Job is not registered');
            ob_start();
            try { $result = ($registry[$job['job']])(...$job['arguments']); }
            finally { ob_end_clean(); }
            sendFrame(['id' => $job['id'], 'value' => $result, 'pid' => getmypid()]);
        } catch (Throwable $e) { sendFrame(['id' => $job['id'], 'error' => get_class($e) . ': ' . substr($e->getMessage(),0,4096), 'pid' => getmypid()]); }
    }
} catch (Throwable $e) { fwrite(STDERR, 'Asyncara worker: ' . substr($e->getMessage(),0,4096) . "\n"); exit(70); }
