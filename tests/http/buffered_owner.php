<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/autoload.php';
use Asyncara\{Async,CancellationSource,Future,Scheduler};
use Asyncara\HTTP\HttpClient;
use Asyncara\Exception\{CancelledException,OperationException};
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function cancelled(Future $future): void {
    try { $future->await(); throw new RuntimeException('Cancellation was not delivered'); }
    catch (CancelledException) {}
}
$root = dirname(__DIR__, 2);
$server = proc_open(['python3', dirname(__DIR__).'/fixtures/http_stream_server.py',
    "$root/build/test-certs/cert.pem", "$root/build/test-certs/key.pem"],
    [0=>['pipe','r'],1=>['pipe','w'],2=>['file', "$root/build/buffered-owner-server.log", 'a']], $pipes);
try {
    $ports = json_decode(fgets($pipes[1]), true, 512, JSON_THROW_ON_ERROR);
    $url = 'http://127.0.0.1:'.$ports['http'];
    Async::run(function () use ($url): void {
        $client = new HttpClient(concurrency: 1);
        for ($n=0; $n<20; $n++) {
            $active = $client->get($url.'/stall');
            $queued = $client->get($url.'/empty');
            $last = $client->get($url.'/empty');
            $queued->cancel(); $active->cancel();
            $nativeAtCompletion = null;
            $active->subscribe(static function () use (&$nativeAtCompletion): void {
                $nativeAtCompletion = asyncara_info()['pendingOperations'];
            });
            cancelled($active); cancelled($queued);
            check($nativeAtCompletion === 0, 'Permit/result released before native collection');
            check($last->await()->status === 204, 'Queued owner did not receive permit');
            check($last->await() === $last->await(), 'Repeated result identity changed');
        }
        $shared = $client->get($url.'/headers-first');
        $source = new CancellationSource();
        $waiter = Async::spawn(fn() => $shared->await($source->token));
        $survivor = Async::spawn($shared->await(...));
        Async::sleep(.01);
        check(!$shared->isCompleted(), 'Peer must retain body');
        $source->cancel(); cancelled($waiter);
        check(!$shared->isCompleted(), 'Cancelling waiter destroyed shared operation');
        (new HttpClient())->get($url.'/release-body')->await();
        check($survivor->await()->body === 'x', 'Shared completion lost');
        check($shared->await() === $survivor->await(), 'Shared result identity changed');
        try { $client->get($url.'/empty', ["bad\r\nheader"])->await(); throw new RuntimeException('Invalid header accepted'); }
        catch (ValueError) {}
        check($client->get($url.'/empty')->await()->status === 204, 'Failed submission leaked permit');
        // Abandoning the public reference keeps its native completion owned.
        $client->get($url.'/empty');
    });
    check(asyncara_info()['pendingOperations'] === 0 && asyncara_info()['nativeMemoryBytes'] === 0, 'Abandoned owner leaked');
    // Removing the per-request Task must not silently discard unobserved errors.
    try {
        Async::run(static fn() => (new HttpClient())->get('http://127.0.0.1:1/'));
        throw new RuntimeException('Unobserved HTTP failure was swallowed');
    } catch (OperationException) {}
    try {
        Async::run(function () use ($url): void {
            (new HttpClient())->get($url.'/stall');
            throw new DomainException('root failure');
        });
        throw new RuntimeException('Root failure swallowed');
    } catch (DomainException) {}
    check(asyncara_info()['pendingOperations'] === 0 && asyncara_info()['nativeMemoryBytes'] === 0, 'Root failure did not drain HTTP');
    Asyncara\Runtime::configure(['taskLimit'=>2]);
    Async::run(function () use ($url): void {
        $client = new HttpClient(); $first = $client->get($url.'/stall');
        try { $client->get($url.'/empty'); throw new RuntimeException('Owned HTTP bypassed task capacity'); }
        catch (Asyncara\Exception\CapacityException) {}
        $first->cancel(); cancelled($first);
        check($client->get($url.'/empty')->await()->status === 204, 'Released operation budget not reusable');
    });
    echo "PASS buffered HTTP owner: queued/active cancellation, shared waiter, collection, submission failure, abandoned result, run failure\n";
} finally {
    foreach ($pipes as $pipe) fclose($pipe);
    check(proc_close($server) === 0, 'Fixture failed');
}
