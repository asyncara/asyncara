<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/autoload.php';
use Asyncara\{Async,Runtime};
use Asyncara\HTTP\HttpClient;
use Asyncara\IO\{File,Files};

$before = asyncara_info();
foreach (['pendingOperations','nativeMemoryBytes','openFileHandles','httpCachedHandles','httpHandlesCreated','httpHandlesReused'] as $key)
    if ($before[$key] !== 0) throw new RuntimeException('Cross-request HTTP owner: '.$key);
if (Runtime::timeHooksEnabled()) throw new RuntimeException('Hooks leaked');
$dir = dirname($_SERVER['AUDIT_LARGE_FILE']);
$url = trim(file_get_contents($dir.'/http-origin.txt'));
$id = $_SERVER['AUDIT_ID'];
$path = $dir.'/download-'.getmypid().'-'.$id;
$start = microtime(true);
if (($_GET['mode'] ?? '') === 'fatal') {
    Async::run(static fn() => File::stage($path)->await()); // abandoned native owner
    ini_set('memory_limit', '8M');
    str_repeat('x', 16 * 1024 * 1024); // intentional unrecoverable PHP request failure
    exit;
}
$result = Async::run(function () use ($url, $dir, $path): array {
    $client = new HttpClient(concurrency: 8);
    $jobs = [];
    for ($n = 0; $n < 4; $n++) $jobs[] = $client->get($_SERVER['AUDIT_URL']);
    $ticks = 0;
    $ticker = Async::spawn(function () use ($jobs, &$ticks): void {
        while (array_filter($jobs, static fn($j) => !$j->isCompleted())) {
            Async::sleep(.005);
            if (array_filter($jobs, static fn($j) => !$j->isCompleted())) $ticks++;
        }
    });
    $produced = false;
    $body = (static function () use (&$produced): Generator { $produced = true; yield 'x'; })();
    try {
        $client->requestStream('HEAD', $url.'/small', $body, 1)->await();
        throw new RuntimeException('FPM accepted unsupported HEAD upload');
    } catch (ValueError $error) {
        if (!str_contains($error->getMessage(), 'HEAD') || $produced)
            throw new RuntimeException('FPM HEAD rejection did not precede producer', 0, $error);
    }
    $head = $client->request('HEAD', $url.'/small')->await();
    if ($head->status !== 200 || $head->body !== '' || $head->headers['content-length'] !== ['65536'] ||
        $head->headers['x-method'] !== ['HEAD']) throw new RuntimeException('FPM HEAD framing');
    $mime = $client->multipartFiles($url.'/multipart', ['request'=>'isolated'], [
        ['field'=>'files[]','path'=>$dir.'/http-input.bin'],
        ['field'=>'files[]','path'=>$dir.'/http-input.bin'],
    ]);
    $download = $client->download($url.'/small', $path, atomic: true, durable: true, failOnHttpError: true);
    $intervals = array_map(static fn($r) => $r->json(), Async::gather($jobs));
    $ticker->await();
    if ($ticks < 2 || max(array_column($intervals,'start')) >= min(array_column($intervals,'end')))
        throw new RuntimeException('Original overlap/two-tick condition failed: '.json_encode(['ticks'=>$ticks,'intervals'=>$intervals],JSON_THROW_ON_ERROR));
    $parts = $mime->await()->json()['parts'];
    if (count($parts) !== 3 || $parts[1]['sha256'] !== hash('sha256', str_repeat('m',65536)) || $parts[2]['sha256'] !== $parts[1]['sha256'])
        throw new RuntimeException('Multipart mismatch');
    if ($download->await() !== 65536 || Files::stat($path)->await()['size'] !== 65536)
        throw new RuntimeException('Atomic download mismatch');
    Files::delete($path)->await();
    return ['ticks'=>$ticks,'http'=>$intervals,'headStatus'=>$head->status,'headBodyBytes'=>strlen($head->body),'headProducerStarted'=>$produced];
});
$after = asyncara_info();
foreach (['pendingOperations','nativeMemoryBytes','openFileHandles','httpUploadBufferedBytes','httpStreamingBufferedBytes'] as $key)
    if ($after[$key] !== 0) throw new RuntimeException('HTTP cleanup: '.$key);
header('Content-Type: application/json');
echo json_encode(['pid'=>getmypid(),'id'=>$id,'start'=>$start,'end'=>microtime(true),
    'before'=>$before,'after'=>$after,'result'=>$result], JSON_THROW_ON_ERROR);
