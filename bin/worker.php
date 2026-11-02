<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Asyncara\Internal\JsonData;
// Private inherited pipes only. Bootstrap is trusted local application code.
const MAX_FRAME = 16777216;
function readExact(int $bytes): ?string {
    $data = '';
    while (strlen($data) < $bytes) { $part = fread(STDIN, $bytes - strlen($data)); if ($part === false || $part === '') return null; $data .= $part; }
    return $data;
}
function sendFrame(array $data): void {
    JsonData::validate($data);
    $json = json_encode($data, JSON_THROW_ON_ERROR);
    if (strlen($json) > MAX_FRAME) $json = json_encode(['id' => $data['id'], 'error' => 'Worker result exceeds frame limit'], JSON_THROW_ON_ERROR);
    $frame = pack('N', strlen($json)) . $json; $offset = 0;
    while ($offset < strlen($frame)) { $n = fwrite(STDOUT, substr($frame, $offset)); if ($n === false || $n === 0) exit(74); $offset += $n; }
    fflush(STDOUT);
}
try {
    ob_start(); $registry = require $argv[1]; ob_end_clean();
    if (!is_array($registry)) throw new RuntimeException('Bootstrap must return a job registry');
    sendFrame(['ready' => true, 'pid' => getmypid()]);
    while (($header = readExact(4)) !== null) {
        $length = unpack('N', $header)[1]; if ($length < 1 || $length > MAX_FRAME) exit(65);
        $json = readExact($length); if ($json === null) exit(65);
        $job = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        try {
            if (!isset($registry[$job['job']]) || !is_callable($registry[$job['job']])) throw new RuntimeException('Job is not registered');
            ob_start();
            try { $result = ($registry[$job['job']])(...$job['arguments']); }
            finally { ob_end_clean(); }
            sendFrame(['id' => $job['id'], 'value' => $result, 'pid' => getmypid()]);
        } catch (Throwable $e) { sendFrame(['id' => $job['id'], 'error' => get_class($e) . ': ' . $e->getMessage(), 'pid' => getmypid()]); }
    }
} catch (Throwable $e) { fwrite(STDERR, 'Asyncara worker: ' . $e->getMessage() . "\n"); exit(70); }
