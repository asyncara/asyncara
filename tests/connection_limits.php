<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';

use Asyncara\{Async,Future,Runtime};
use Asyncara\HTTP\HttpClient;
use Asyncara\SQLite\Connection;

function demand(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

Async::run(function (): void {
    $connections = [];
    try {
        $opens = [];
        for ($i = 0; $i < 129; $i++) $opens[] = Connection::open(':memory:');
        $rejected = 0;
        foreach ($opens as $open) {
            try { $connections[] = $open->await(); }
            catch (ValueError $error) {
                demand(str_contains($error->getMessage(), 'SQLite connection limit'), 'Unexpected admission failure');
                $rejected++;
            }
        }
        demand(count($connections) === 128 && $rejected === 1, 'SQLite simultaneous open admission');
        demand(Runtime::information()->sqliteConnections === 128, 'SQLite native connection count');
        array_pop($connections)->close()->await();
        $replacement = Connection::open(':memory:')->await();
        $connections[] = $replacement;
        demand($replacement->query('SELECT 42 AS value')->await()[0]['value'] === 42, 'SQLite recovery after rejection');
    } finally {
        foreach ($connections as $connection) $connection->close()->await();
    }
    demand(Runtime::information()->sqliteConnections === 0, 'SQLite connection cleanup');
});

$peer = proc_open(['python3', __DIR__.'/fixtures/connection_limits.py'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
if (!is_resource($peer)) throw new RuntimeException('Could not start HTTP limit peer');
try {
    stream_set_timeout($pipes[1], 15);
    $ports = json_decode(fgets($pipes[1]), true, 16, JSON_THROW_ON_ERROR);
    Async::run(function () use ($ports): void {
        $client = new HttpClient(concurrency: 128, timeout: 15);
        foreach (['host' => 16, 'global' => 72] as $mode => $count) {
            $requests = [];
            for ($i = 0; $i < $count; $i++) {
                $port = $ports[$mode === 'host' ? 0 : $i % 9];
                $requests[] = $client->get("http://127.0.0.1:$port/$mode");
            }
            foreach (Async::gather($requests) as $response) demand($response->status === 200 && $response->body === 'ok', 'HTTP admission progress');
        }
    });
    fwrite($pipes[0], "stats\n"); fflush($pipes[0]);
    $stats = json_decode(fgets($pipes[1]), true, 16, JSON_THROW_ON_ERROR);
    demand($stats['host']['peak'] === 8 && $stats['global']['peak'] === 64, 'Native curl connection bounds');
    demand($stats['host']['completed'] === 16 && $stats['global']['completed'] === 72, 'Queued HTTP work was dropped');
    demand(Future::pendingCount() === 0 && Runtime::information()->pendingOperations === 0, 'Pending work after limits test');
    echo 'SQLite128 admission/recovery and native HTTP host8/global64: PASS ', json_encode($stats, JSON_THROW_ON_ERROR), "\n";
} finally {
    fclose($pipes[0]); fclose($pipes[1]);
    demand(proc_close($peer) === 0, 'HTTP limit peer failed');
}
