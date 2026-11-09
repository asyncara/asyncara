<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/autoload.php';
use Asyncara\{Async,Runtime};
use Asyncara\HTTP\HttpClient;

function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function invalid(callable $body): void {
    try { $body(); } catch (ValueError $error) {
        check(str_contains($error->getMessage(), 'HEAD'), 'Wrong validation error'); return;
    }
    throw new RuntimeException('HEAD request content accepted');
}
$server = proc_open(['python3', __DIR__.'/method_server.py'],
    [0=>['pipe','r'], 1=>['pipe','w'], 2=>['file', dirname(__DIR__,2).'/build/http-method-server.log','a']], $pipes);
try {
    $url = 'http://127.0.0.1:'.trim(fgets($pipes[1]));
    Async::run(function () use ($url): void {
        $client = new HttpClient(concurrency: 2, timeout: 1);
        $generated = 0;
        foreach ([0, 5, null] as $size) {
            $producer = (static function () use (&$generated, $size): Generator { $generated++; if ($size !== 0) yield 'hello'; })();
            invalid(fn() => $client->requestStream('HEAD', $url.'/head', $producer, $size)->await());
        }
        check($generated === 0, 'Rejected HEAD started upload producer');
        invalid(fn() => $client->upload($url.'/head', '/nonexistent/asyncara-head-input', 'HEAD')->await());
        invalid(fn() => $client->request('HEAD', $url.'/head', 'hello')->await());
        foreach ([['uploadSize'=>0], ['uploadSize'=>-1], ['uploadSize'=>5]] as $options)
            invalid(fn() => asyncara_submit('http', [$url.'/head', null, ['method'=>'HEAD']+$options, 1000]));
        invalid(fn() => asyncara_submit('http', [$url.'/head', 'x', ['method'=>'HEAD'], 1000]));
        check($client->get($url.'/records')->await()->json() === [], 'Rejected HEAD reached network');
        foreach ([null, ''] as $body) {
            $head = $client->request('HEAD', $url.'/head', $body)->await();
            check($head->status === 200 && $head->body === '' && $head->headers['x-method'] === ['HEAD'] &&
                $head->headers['content-length'] === ['19'], 'HEAD metadata or method changed');
            check($client->get($url.'/get')->await()->json()['method'] === 'GET', 'Reused HEAD handle corrupted GET');
        }
        $head = $client->request('HEAD', $url.'/pending'); $ticks = 0;
        for ($i=0; $i<2; $i++) { Async::sleep(.002); check(!$head->isCompleted(), 'HEAD did not remain pending'); $ticks++; }
        $client->get($url.'/release')->await();
        check($head->await()->body === '' && $ticks === 2, 'HEAD timer progress');
        $records = $client->get($url.'/records')->await()->json();
        check(count($records) === 3, 'Unexpected wire requests');
        foreach ($records as $record) check($record === ['method'=>'HEAD','length'=>null,'transfer'=>null], 'HEAD uploaded content framing');
        $info = Runtime::information();
        check($info->pendingOperations === 0 && $info->nativeMemoryBytes === 0 && $info->httpReadinessSockets === 0,
            'HEAD rejection or completion leaked native owner');
    });
    echo "PASS HEAD validation before transport/producer, bodyless metadata, handle reuse, two ticks during pending I/O, cleanup\n";
} finally {
    foreach ($pipes as $pipe) fclose($pipe);
    check(proc_close($server) === 0, 'Method fixture failed');
}
