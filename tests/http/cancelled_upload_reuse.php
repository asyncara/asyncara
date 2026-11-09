<?php
declare(strict_types=1);
require (getenv('ASYNCARA_SOURCE_ROOT') ?: dirname(__DIR__, 2)).'/autoload.php';
use Asyncara\{Async, Scheduler};
use Asyncara\HTTP\HttpClient;
use Asyncara\Exception\{CancelledException, OperationException};
function check(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
$root = dirname(__DIR__, 2);
$server = proc_open(['python3', dirname(__DIR__).'/fixtures/http_stream_server.py',
    "$root/build/test-certs/cert.pem", "$root/build/test-certs/key.pem"],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', "$root/build/cancelled-upload-server.log", 'a']], $pipes);
try {
    $port = json_decode(fgets($pipes[1]), true, 512, JSON_THROW_ON_ERROR)['http'];
    Async::run(function () use ($port): void {
        $client = new HttpClient(concurrency: 1, timeout: 5);
        for ($i = 0; $i < 10; $i++) {
            $producerExited = false;
            $source = (function () use (&$producerExited): Generator {
                try { yield str_repeat('x', 65536); Async::sleep(10); }
                finally { $producerExited = true; }
            })();
            $pending = $client->requestStream('POST', "http://127.0.0.1:$port/slow-upload", $source);
            $deadline = Scheduler::now() + 3;
            while (!asyncara_info()['httpUploadPaused']) {
                check(!$pending->isCompleted() && Scheduler::now() < $deadline, 'Upload did not reach paused state');
                Async::sleep(.001);
            }
            $pending->cancel();
            try { $pending->await(); throw new RuntimeException('Cancellation lost'); }
            catch (CancelledException) {}
            check($producerExited, 'Producer not joined');
            check(asyncara_info()['pendingOperations'] === 0, 'Cancelled native owner not collected');
            check($client->get("http://127.0.0.1:$port/empty")->await()->status === 204, 'Post-cancel request failed');
        }
        foreach (['redirect-loop', 'redirect-file'] as $failure) {
            try {
                $client->get("http://127.0.0.1:$port/$failure", followRedirects: true)->await();
                throw new RuntimeException('Invalid redirect succeeded');
            } catch (OperationException) {}
            check($client->get("http://127.0.0.1:$port/empty")->await()->status === 204, 'Post-redirect-failure request failed');
        }
    });
    check(asyncara_info()['nativeMemoryBytes'] === 0, 'Native buffer leaked');
    echo "PASS paused-upload cancellation, producer join, failed redirects, subsequent buffered request, 10 cycles\n";
} finally {
    foreach ($pipes as $pipe) fclose($pipe);
    check(proc_close($server) === 0, 'Fixture exit');
}
