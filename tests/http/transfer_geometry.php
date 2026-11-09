<?php
declare(strict_types=1);
require (getenv('ASYNCARA_SOURCE_ROOT') ?: dirname(__DIR__, 2)).'/autoload.php';
use Asyncara\{Async, Runtime, Scheduler};
use Asyncara\HTTP\HttpClient;
use Asyncara\Exception\{OperationException, CancelledException};
function check(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
function payload(int $size): string {
    return substr(str_repeat(implode('', array_map(chr(...), range(0, 250))), intdiv($size, 251) + 1), 0, $size);
}
$server = proc_open(['python3', __DIR__.'/transfer_geometry_server.py'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', dirname(__DIR__, 2).'/build/transfer-geometry-server.log', 'a']], $pipes);
try {
    $port = json_decode(fgets($pipes[1]), true, 512, JSON_THROW_ON_ERROR)['port'];
    $responses = Async::run(function () use ($port): int {
        $url = 'http://127.0.0.1:'.$port;
        $client = new HttpClient(concurrency: 8, timeout: 5);
        $count = 0;
        foreach ([1, 1024, 16384, 65535, 65536, 65537, 131071, 131072, 131073, 262144] as $size) {
            foreach (['body', 'chunked'] as $kind) {
                $pending = [];
                for ($i = 0; $i < 8; $i++) $pending[] = $client->get("$url/$kind/$size");
                foreach ($pending as $future) {
                    $r = $future->await();
                    check($r->status === 200 && $r->body === payload($size), "Binary body mismatch $kind/$size");
                    if ($kind === 'chunked') check($r->headers['x-end'] === ['yes'], 'Lost trailer');
                    $count++;
                }
            }
        }
        check($client->get("$url/head/64000")->await()->headers['x-pad'] === [str_repeat('p', 64000)], 'Valid bounded head');
        foreach (['head/66000', 'head/131072', 'truncated/65536', 'truncated/131072'] as $path) {
            try { $client->get("$url/$path")->await(); throw new RuntimeException('Accepted invalid transfer '.$path); }
            catch (OperationException) {}
            check($client->get("$url/body/1024")->await()->body === payload(1024), 'Recovery after transfer rejection');
        }
        // The buffer must have received bytes before cancellation. Cancelling
        // before the first poll cannot reproduce curl's retained-buffer state.
        for ($cycle = 0; $cycle < 4; $cycle++) {
            $pending = $client->get("$url/held/65536"); $deadline = Scheduler::now() + 3;
            while (asyncara_info()['nativeMemoryBytes'] < 65536) {
                check(Scheduler::now() < $deadline && !$pending->isCompleted(), 'Held body admission');
                Async::sleep(.001);
            }
            for ($tick = 0; $tick < 2; $tick++) {
                Async::sleep(.002); check(!$pending->isCompleted(), 'No timer/I/O overlap');
            }
            $pending->cancel();
            try { $pending->await(); throw new RuntimeException('Cancellation lost'); }
            catch (CancelledException) {}
            check($client->get("$url/body/65536")->await()->body === payload(65536), 'Reuse after cancellation');
        }
        if (asyncara_info()['httpBackend'] === 'curl-multi') {
            $stream = $client->getStream("$url/body/262144");
            foreach ($stream as $chunk) { check($chunk !== '', 'Empty stream'); break; }
            $stream->close();
            check($client->get("$url/body/1024")->await()->body === payload(1024), 'Reuse after early stream close');
        }
        return $count;
    });
    $native = asyncara_info(); $scheduler = Runtime::diagnostics();
    check($native['pendingOperations'] === 0 && $native['nativeMemoryBytes'] === 0, 'Native cleanup');
    check($scheduler['activeTasks'] === 0 && $scheduler['pendingFutures'] === 0, 'PHP cleanup');
    echo json_encode(['status' => 'PASS', 'binaryResponses' => $responses, 'native' => $native, 'scheduler' => $scheduler], JSON_THROW_ON_ERROR), "\n";
} finally {
    fclose($pipes[0]); stream_get_contents($pipes[1]); fclose($pipes[1]);
    check(proc_close($server) === 0, 'Fixture cleanup');
}
