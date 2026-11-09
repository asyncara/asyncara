<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/autoload.php';
use Asyncara\{Async, Runtime, Scheduler};
use Asyncara\HTTP\HttpClient;
use Asyncara\Exception\{OperationException, CancelledException};
function check(bool $v, string $why): void { if (!$v) throw new RuntimeException($why); }
Runtime::configure(['httpMaxConnections' => 4, 'httpMaxHostConnections' => 2]);
$peer = proc_open(['python3', __DIR__.'/hyper_dns_server.py'],
    [0=>['pipe','r'], 1=>['pipe','w'], 2=>['file',dirname(__DIR__,2).'/build/hyper-dns-peer.log','a']], $pipes);
try {
    $port = json_decode(fgets($pipes[1]), true, 512, JSON_THROW_ON_ERROR)['port'];
    stream_set_blocking($pipes[1], false);
    Async::run(function () use ($port, $pipes): void {
        Async::timeout(8, function () use ($port, $pipes): void {
            $client = new HttpClient(concurrency:4, timeout:4);
            $a = json_decode($client->get("http://localhost:$port/")->await()->body, true, 512, JSON_THROW_ON_ERROR);
            check($a['host'] === "localhost:$port", 'DNS changed Host authority');
            $b = json_decode($client->get("http://localhost:$port/")->await()->body, true, 512, JSON_THROW_ON_ERROR);
            check($a['connection'] === $b['connection'], 'Origin connection not reused');
            $numeric = json_decode($client->get("http://127.0.0.1:$port/")->await()->body, true, 512, JSON_THROW_ON_ERROR);
            check($numeric['connection'] !== $a['connection'], 'Different origins shared a connection');
            // Both real reads must remain pending until two timer ticks complete.
            $pending = [$client->get("http://localhost:$port/gated"), $client->get("http://localhost:$port/gated")];
            for ($i=0; $i<2; $i++) {
                $line=''; while (!str_ends_with($line,"\n")) {
                    Scheduler::current()->readable($pipes[1]); $part=fgets($pipes[1]);
                    if ($part!==false) $line.=$part;
                    check(!feof($pipes[1]), 'Peer stopped before pending responses');
                }
                check(json_decode($line,true,512,JSON_THROW_ON_ERROR)['pending']==="localhost:$port", 'Wrong pending origin');
            }
            for ($tick=0; $tick<2; $tick++) {
                Async::sleep(.002); foreach($pending as $future) check(!$future->isCompleted(), 'DNS/HTTP completed before release');
            }
            // A cancelled waiter must not cancel the shared request owner.
            $waiter=Async::spawn(fn()=>$pending[0]->await()); Async::sleep(0); $waiter->cancel();
            try {$waiter->await(); throw new RuntimeException('Waiter cancellation lost');} catch(CancelledException) {}
            check(!$pending[0]->isCompleted(), 'Waiter cancelled shared HTTP');
            fwrite($pipes[0],"release\n");
            foreach($pending as $future) check($future->await()->status===200, 'Pending body lost');
            // Native resolver result supplied at the C boundary: first address
            // refuses, second succeeds. Retry is allowed only before send.
            $result=Scheduler::current()->native('http',["http://fallback.invalid:$port/",null,
                ['method'=>'GET','headers'=>[],'resolvedAddresses'=>['::1','127.0.0.1']],2000])->await();
            check(json_decode($result['body'],true)['host']==="fallback.invalid:$port", 'Fallback lost authority');
            foreach ([[],['not-an-ip'],array_fill(0,17,'127.0.0.1')] as $addresses) {
                try {Scheduler::current()->native('http',["http://bad.invalid:$port/",null,['resolvedAddresses'=>$addresses],1000])->await();
                    throw new RuntimeException('Invalid address vector accepted');} catch(OperationException|ValueError) {}
            }
        });
    });
    $native=asyncara_info();$runtime=Runtime::diagnostics();
    check($native['hyperSubmitted']===$native['hyperCompleted'], 'Duplicate/missing completion');
    check(!$native['pendingOperations']&&!$native['nativeMemoryBytes'], 'Native ownership leaked');
    check(!$runtime['activeTasks']&&!$runtime['pendingFutures'], 'PHP ownership leaked');
    $report=['status'=>'PASS','ticksWhilePending'=>2,'native'=>$native,'scheduler'=>$runtime];
} finally {
    fclose($pipes[0]);stream_set_blocking($pipes[1],true);stream_get_contents($pipes[1]);fclose($pipes[1]);
    check(proc_close($peer)===0,'DNS fixture exit');
}
echo json_encode($report), "\n";
