<?php
declare(strict_types=1);
// Diagnostic memory workload: ordinary client deadlines, no latency acceptance.
require dirname(__DIR__, 2).'/autoload.php';
use Asyncara\{Async, Runtime, Scheduler};
use Asyncara\HTTP\HttpClient;
use Asyncara\Exception\{CancelledException, OperationException};
function check(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
function event($pipe): array {
    $line = '';
    while (!str_ends_with($line, "\n")) {
        Scheduler::current()->readable($pipe);
        $part = fgets($pipe); if ($part !== false) $line .= $part;
        check(!feof($pipe), 'Peer ended');
    }
    return json_decode($line, true, 512, JSON_THROW_ON_ERROR);
}
[$script, $certs, $out] = $argv;
Runtime::configure(['httpMaxConnections'=>8, 'httpMaxHostConnections'=>4]);
check(asyncara_info()['httpBackend'] === 'hyper-prototype', 'Expected Hyper');
$peer = proc_open(['python3', __DIR__.'/hyper_tls_peer.py', $certs],
    [0=>['pipe','r'], 1=>['pipe','w'], 2=>['file',$out.'/peer.log','w']], $pipes);
try {
    $ports = json_decode(fgets($pipes[1]), true, 512, JSON_THROW_ON_ERROR);
    stream_set_blocking($pipes[1], false);
    Async::run(function () use ($certs, $pipes, $ports): void {
        $client = new HttpClient(concurrency:4, caFile:$certs.'/ca.pem');
        foreach (['tls12','tls13'] as $version) {
            $url = 'https://localhost:'.$ports[$version]; $start = Scheduler::now();
            $first = $client->get($url)->await()->json();
            echo json_encode(['step'=>$version.'-first', 'seconds'=>Scheduler::now()-$start]), "\n";
            for ($i=0; $i<4; $i++) {
                check($client->get($url)->await()->json()['connection'] === $first['connection'], 'Pool identity');
                check(hash('sha256', $client->get($url.'/large')->await()->body) ===
                    'fbbab289f7f94b25736c58be46a994c441fd02552cc6022352e3d86d2fab7c83', 'Body integrity');
            }
        }
        $url = 'https://127.0.0.1:'.$ports['tls13'];
        try { (new HttpClient(caFile:$certs.'/wrong.pem'))->get($url)->await(); throw new RuntimeException('Untrusted peer'); }
        catch (OperationException) {}
        $pending = [$client->get($url.'/hold'), $client->get($url.'/hold')];
        for ($i=0; $i<2; $i++) check(isset(event($pipes[1])['pending']), 'Body barrier');
        for ($i=0; $i<2; $i++) { Async::sleep(.002); foreach ($pending as $f) check(!$f->isCompleted(), 'Body released early'); }
        $waiter = Async::spawn(fn()=>$pending[0]->await()); Async::sleep(0); $waiter->cancel();
        try { $waiter->await(); throw new RuntimeException('Wait cancellation lost'); } catch (CancelledException) {}
        check(!$pending[0]->isCompleted(), 'Waiter cancelled owner');
        fwrite($pipes[0], "release\n"); foreach ($pending as $f) check($f->await()->status === 200, 'Body result');
        $handshake = $client->get('https://127.0.0.1:'.$ports['stall']);
        check(event($pipes[1]) === ['handshake'=>'pending'], 'Handshake barrier');
        for ($i=0; $i<2; $i++) { Async::sleep(.002); check(!$handshake->isCompleted(), 'Handshake did not stay pending'); }
        $handshake->cancel();
        try { $handshake->await(); throw new RuntimeException('Owner cancellation lost'); } catch (CancelledException) {}
        check($client->get($url)->await()->status === 200, 'Recovery');
    });
    $native = asyncara_info(); $runtime = Runtime::diagnostics();
    check($native['hyperSubmitted'] === $native['hyperCompleted'], 'Completion identity');
    check(!$native['pendingOperations'] && !$native['nativeMemoryBytes'] && !$native['openFileHandles'], 'Native ownership');
    foreach (['activeTasks','pendingFutures','ioWatchers','activeTimers'] as $key) check($runtime[$key] === 0, $key);
    echo json_encode(['status'=>'PASS', 'scope'=>'diagnostic memory, not latency acceptance', 'native'=>$native, 'runtime'=>$runtime]), "\n";
} finally {
    fclose($pipes[0]); stream_set_blocking($pipes[1], true); stream_get_contents($pipes[1]); fclose($pipes[1]);
    check(proc_close($peer) === 0, 'Peer shutdown');
}
