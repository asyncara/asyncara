<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/autoload.php';
use Asyncara\{Async, Runtime, Scheduler};
use Asyncara\HTTP\HttpClient;
function check(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
$count = 150;
Runtime::configure(['httpMaxConnections' => $count, 'httpMaxHostConnections' => $count]);
$server = proc_open(['python3', __DIR__.'/transfer_geometry_server.py', (string)$count],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', dirname(__DIR__, 2).'/build/hyper-wakeups-server.log', 'a']], $pipes);
try {
    $port = json_decode(fgets($pipes[1]), true, 512, JSON_THROW_ON_ERROR)['port'];
    stream_set_blocking($pipes[1], false);
    Async::run(function () use ($port, $count, $pipes): void {
        Async::timeout(10, function () use ($port, $count, $pipes): void {
            $client = new HttpClient(concurrency: $count, timeout: 8);
            $pending = [];
            for ($i = 0; $i < $count; $i++) $pending[] = $client->get("http://127.0.0.1:$port/gated/1024");
            $line = '';
            while (!str_ends_with($line, "\n")) {
                Scheduler::current()->readable($pipes[1]);
                $part = fgets($pipes[1]);
                if ($part !== false) $line .= $part;
                check(!feof($pipes[1]), 'Peer exited before all response prefixes');
            }
            check(json_decode($line, true, 512, JSON_THROW_ON_ERROR)['prefixes'] === $count, 'Missing active peer');
            for ($tick = 0; $tick < 2; $tick++) {
                Async::sleep(.002);
                foreach ($pending as $future) check(!$future->isCompleted(), 'I/O completed before timer progress');
            }
            check(fwrite($pipes[0], "release\n") === 8, 'Release failed');
            $expected = substr(str_repeat(implode('', array_map(chr(...), range(0, 250))), 5), 0, 1024);
            foreach ($pending as $future) {
                $response = $future->await();
                check($response->status === 200 && $response->body === $expected, 'Missing or corrupt response');
            }
        });
    });
    $native = asyncara_info(); $scheduler = Runtime::diagnostics();
    check(!$native['pendingOperations'] && !$native['nativeMemoryBytes'], 'Native ownership after collection');
    check(!$scheduler['activeTasks'] && !$scheduler['pendingFutures'], 'PHP ownership after collection');
    echo json_encode(['status' => 'PASS', 'responses' => $count, 'ticksWhileAllPending' => 2,
        'native' => $native, 'scheduler' => $scheduler], JSON_THROW_ON_ERROR), "\n";
} finally {
    fclose($pipes[0]); stream_set_blocking($pipes[1], true); stream_get_contents($pipes[1]); fclose($pipes[1]);
    check(proc_close($server) === 0, 'Fixture shutdown');
}
