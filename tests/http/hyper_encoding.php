<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/autoload.php';
use Asyncara\Async;
use Asyncara\HTTP\HttpClient;
use Asyncara\Exception\OperationException;
function check(bool $value, string $why): void { if (!$value) throw new RuntimeException($why); }
check(asyncara_info()['httpBackend'] === 'hyper-prototype', 'Expected Hyper');
$server = proc_open(['python3', __DIR__.'/transfer_geometry_server.py'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', dirname(__DIR__, 2).'/build/hyper-encoding-server.log', 'a']], $pipes);
try {
    $port = json_decode(fgets($pipes[1]), true, 512, JSON_THROW_ON_ERROR)['port'];
    Async::run(function () use ($port): void {
        $client = new HttpClient(timeout: 2);
        $base = "http://127.0.0.1:$port";
        for ($cycle = 0; $cycle < 4; $cycle++) {
            check($client->get($base.'/encoding/0')->await()->body === 'x', 'Repeated identity rejected');
            foreach ([1, 2, 3, 4] as $mode) {
                try {
                    $client->get($base.'/encoding/'.$mode)->await();
                    throw new RuntimeException('Unsupported coding accepted: '.$mode);
                } catch (OperationException $error) {
                    check(str_contains($error->getMessage(), 'does not decode compressed responses'), 'Wrong rejection');
                }
                check($client->get($base.'/body/1')->await()->body === "\0", 'Recovery after rejected coding');
            }
        }
    });
    $native = asyncara_info();
    check($native['hyperSubmitted'] === $native['hyperCompleted'], 'Missing or duplicate completion');
    check(!$native['pendingOperations'] && !$native['nativeMemoryBytes'], 'Uncollected native resources');
    echo json_encode(['status' => 'PASS', 'unsupportedCodingRejections' => 16, 'native' => $native]), "\n";
} finally {
    fclose($pipes[0]); stream_get_contents($pipes[1]); fclose($pipes[1]);
    check(proc_close($server) === 0, 'Fixture shutdown');
}
