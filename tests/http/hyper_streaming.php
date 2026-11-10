<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/autoload.php';
use Asyncara\{Async, Runtime, Scheduler};
use Asyncara\HTTP\HttpClient;
use Asyncara\IO\Files;
use Asyncara\Exception\{CancelledException, OperationException, TimeoutException};
function check(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
function fails(string $type, callable $body): void {
    try { $body(); } catch (Throwable $error) { if ($error instanceof $type) return; throw $error; }
    throw new RuntimeException('Expected '.$type);
}
[$script, $certs, $out] = $argv;
check(asyncara_info()['httpBackend'] === 'hyper-prototype', 'Expected actual Hyper path');
$peer = proc_open(['python3', dirname(__DIR__).'/fixtures/http_stream_server.py', $certs.'/server.pem', $certs.'/server.key'],
    [0=>['pipe','r'], 1=>['pipe','w'], 2=>['file',$out.'/peer.log','x']], $pipes);
$file = $out.'/download.bin';
$gzipHash = trim(file_get_contents($out.'/expected-gzip.sha256'));
$block = str_repeat(implode('', array_map(chr(...), range(0, 255))), 256);
$hash = hash_init('sha256'); for ($i = 0; $i < 512; $i++) hash_update($hash, $block);
$expected = hash_final($hash); $metrics = [];
try {
    $ports = json_decode(fgets($pipes[1]), true, flags: JSON_THROW_ON_ERROR);
    Async::run(function () use ($ports, $certs, $out, $file, $block, $expected, $gzipHash, &$metrics): void {
        foreach (['http://127.0.0.1:'.$ports['http'], 'http://localhost:'.$ports['http'], 'https://localhost:'.$ports['https']] as $url) {
            echo json_encode(['stage'=>'response', 'url'=>$url]), "\n";
            $client = new HttpClient(concurrency:2, timeout:10, caFile:str_starts_with($url, 'https:') ? $certs.'/ca.pem' : null);
            $stream = $client->getStream($url.'/headers-first');
            check($stream->headers()->await()->status === 200, 'Headers must precede pending body');
            $body = Async::spawn(fn() => implode('', iterator_to_array($stream)));
            for ($i = 0; $i < 2; $i++) { Async::sleep(.002); check(!$body->isCompleted(), 'Body completed before independent peer release'); }
            $client->get($url.'/release-body')->await(); check($body->await() === 'x', 'Gated body'); $stream->close();
            $empty = $client->getStream($url.'/empty'); check($empty->headers()->await()->status === 204, 'Empty head');
            check(iterator_to_array($empty) === [], 'Empty body'); $empty->close();
            $gzip = $client->getStream($url.'/gzip');
            check($gzip->headers()->await()->headers['content-encoding'] === ['gzip'], 'Encoded streaming metadata');
            $encoded = ''; $gzip->consume(function ($chunk) use (&$encoded): void { $encoded .= $chunk; });
            check(hash('sha256', $encoded) === $gzipHash, 'Streaming must retain exact peer-encoded bytes');
            $stream = $client->getStream($url.'/large');
            Async::timeout(3, function (): void { while (!asyncara_info()['httpStreamingPaused']) Async::sleep(.001); });
            $turns = Scheduler::current()->statistics()['turns']; Async::sleep(.05);
            check(Scheduler::current()->statistics()['turns'] - $turns < 300, 'Paused response spins');
            $bytes = 0; $peak = 0; $buffered = 0; $ticks = 0; $stop = false; $hash = hash_init('sha256');
            $ticker = Async::spawn(function () use (&$ticks, &$stop): void { while (!$stop) { Async::sleep(.001); if (!$stop) $ticks++; } });
            try { foreach ($stream as $chunk) {
                check(strlen($chunk) <= 65536, 'Response chunk cap');
                $bytes += strlen($chunk); hash_update($hash, $chunk);
                $info = asyncara_info(); $peak = max($peak, $info['nativeMemoryBytes']); $buffered = max($buffered, $info['httpStreamingBufferedBytes']);
                Async::sleep(.0002);
            } } finally { $stream->close(); $stop = true; $ticker->await(); }
            check($bytes === 33554432 && hash_final($hash) === $expected, 'Large streamed identity');
            check($ticks >= 2 && $buffered <= 65536 && $peak < 1048576, 'Response progress or native bounds');
            $metrics[] = compact('url', 'bytes', 'ticks', 'peak', 'buffered');
            $chunks = ''; $client->getStream($url.'/chunks')->consume(function ($chunk) use (&$chunks): void { $chunks .= $chunk; });
            check($chunks === substr($block,0,1).substr($block,0,7).substr($block,0,61).substr($block,0,2048).$block, 'Chunked response/trailers');
            fails(OperationException::class, fn() => $client->getStream($url.'/broken')->consume(static fn($chunk) => null));
            for ($i = 0; $i < 5; $i++) {
                $early = $client->getStream($url.'/large');
                try { foreach ($early as $_) break; } finally { $early->close(); }
                check(asyncara_info()['pendingOperations'] === 0, 'Early-close transfer retained');
            }
            $one = new HttpClient(concurrency:1, caFile:str_starts_with($url, 'https:') ? $certs.'/ca.pem' : null);
            $held = $one->getStream($url.'/large');
            Async::timeout(3, function (): void { while (!asyncara_info()['httpStreamingPaused']) Async::sleep(.001); });
            $queued = $one->get($url.'/small'); Async::sleep(.005); check(!$queued->isCompleted(), 'Client admission bypassed');
            $held->close(); check($queued->await()->body === $block, 'Permit release');

            echo json_encode(['stage'=>'upload-download', 'url'=>$url]), "\n";
            check($client->download($url.'/large', $file, atomic:true)->await() === 33554432, 'Atomic download length');
            $hash = hash_init('sha256'); foreach (Files::readStream($file) as $chunk) hash_update($hash, $chunk);
            check(hash_final($hash) === $expected, 'Worker-backed download identity');
            echo "file-upload\n";
            $result = $client->upload($url.'/slow-upload', $file)->await()->json();
            check($result['bytes'] === 33554432 && $result['sha256'] === $expected && $result['method'] === 'PUT', 'Worker-backed streamed upload');
            echo "fixed-upload\n";
            $fixed = $client->requestStream('POST', $url.'/upload', [$block, $block], 131072)->await()->json();
            check($fixed['bytes'] === 131072, 'Fixed upload framing');
            echo "empty-upload\n";
            check($client->requestStream('PUT', $url.'/upload', [], 0)->await()->json()['bytes'] === 0, 'Empty upload');
            echo "invalid-upload\n";
            fails(ValueError::class, fn() => $client->requestStream('PUT', $url.'/upload', ['short'], 99)->await());
            fails(ValueError::class, fn() => $client->requestStream('PUT', $url.'/upload', [str_repeat('x',65537)])->await());
            echo "producer-exception\n";
            $broken = (static function () { yield 'x'; throw new DomainException('producer'); })();
            fails(DomainException::class, fn() => $client->requestStream('PUT', $url.'/upload', $broken)->await());
            echo "early-response\n";
            $slow = (static function () use ($block) { yield $block; Async::sleep(30); yield $block; })();
            $started = Scheduler::now();
            check($client->requestStream('PUT', $url.'/reject-held', $slow)->await()->status === 413, 'Early response');
            check(Scheduler::now() - $started < 2, 'Early response retained upload owner');
            $client->get($url.'/release-reject')->await();
            // An unread-body reset can discard a TLS response at the peer. Both
            // explicit transfer failure and a fully received 413 are valid;
            // neither may retain the sleeping producer until its deadline.
            $abrupt = (static function () use ($block) { yield $block; Async::sleep(30); yield $block; })();
            $started = Scheduler::now();
            try { check($client->requestStream('PUT', $url.'/reject', $abrupt)->await()->status === 413, 'Abrupt response'); }
            catch (OperationException $error) { check(!str_contains($error->getMessage(), 'timed out'), 'Lost abrupt completion'); }
            check(Scheduler::now() - $started < 2, 'Abrupt response retained producer');
            echo "cancel\n";
            $pending = $client->upload($url.'/slow-upload', $file); Async::sleep(.01); $pending->cancel();
            fails(CancelledException::class, $pending->await(...));
            echo "owned-timeout\n";
            fails(TimeoutException::class, fn() => Async::timeout(.01, fn() => Async::await($client->upload($url.'/slow-upload', $file))));
            echo "unstarted-download\n";
            $unstarted = $client->download($url.'/large', $file); $unstarted->cancel(); fails(CancelledException::class, $unstarted->await(...));
            echo "consumer-exception\n";
            fails(DomainException::class, fn() => $client->getStream($url.'/large')->consume(static fn($chunk) => throw new DomainException('consumer')));
            echo "unconsumed\n";
            $never = $client->getStream($url.'/large'); $never->close(); fails(CancelledException::class, $never->headers()->await(...));
            check(!asyncara_info()['pendingOperations'] && !asyncara_info()['openFileHandles'], 'Stream cleanup');
        }
            echo "final-controls\n";
        $plain = 'http://127.0.0.1:'.$ports['http'];
        $expired = (new HttpClient(timeout:.04))->getStream($plain.'/large'); Async::sleep(.08);
        fails(OperationException::class, fn() => $expired->consume(static fn($chunk) => null));
        $shared = (new HttpClient())->getStream($plain.'/stall');
        $survivor = Async::spawn(fn() => $shared->headers()->await());
        fails(TimeoutException::class, fn() => Async::timeout(.01, fn() => $shared->headers()->await()));
        check($survivor->await()->status === 200, 'Waiter cancelled shared headers'); $shared->close();
        fails(OperationException::class, fn() => (new HttpClient(caFile:$certs.'/wrong.pem'))->getStream('https://localhost:'.$ports['https'].'/small')->consume(static fn($chunk) => null));
    });
    gc_collect_cycles(); $native = asyncara_info(); $runtime = Runtime::diagnostics();
    check(!$native['pendingOperations'] && !$native['nativeMemoryBytes'] && !$native['openFileHandles'], 'Native resources retained');
    check($native['hyperSubmitted'] === $native['hyperCompleted'], 'Missing completion');
    // Runtime::diagnostics outside run() is the last pre-GC snapshot. Use
    // Future's live weak registry to verify post-collection ownership.
    $liveFutures = \Asyncara\Future::pendingCount(); check($liveFutures === 0, 'Live pending Futures after collection');
    foreach (['activeTasks','ioWatchers','activeTimers'] as $key) check($runtime[$key] === 0, $key);
    echo json_encode(['status'=>'PASS', 'metrics'=>$metrics, 'native'=>$native, 'runtime'=>$runtime, 'livePendingFutures'=>$liveFutures]), "\n";
} finally {
    fclose($pipes[0]); stream_get_contents($pipes[1]); fclose($pipes[1]);
    check(proc_close($peer) === 0, 'Peer shutdown');
    if (is_file($file)) unlink($file);
}
