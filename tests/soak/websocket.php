<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Runtime,Scheduler,Future};
use Asyncara\WebSocket\Client;
use Asyncara\Exception\CancelledException;
function ensure(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function osSample():array{
    $status=file_get_contents('/proc/self/status');preg_match('/VmRSS:\s+(\d+)/',$status,$rss);preg_match('/Threads:\s+(\d+)/',$status,$threads);
    $fds=glob('/proc/self/fd/*');$sockets=0;foreach($fds as$fd)if(is_link($fd)&&str_starts_with(readlink($fd),'socket:'))$sockets++;
    return ['rssKiB'=>(int)$rss[1],'threads'=>(int)$threads[1],'fds'=>count($fds),'sockets'=>$sockets];
}
$root=dirname(__DIR__,2);$seconds=(float)(getenv('ASYNCARA_SOAK_SECONDS')?:60);$connections=(int)(getenv('ASYNCARA_WS_CONNECTIONS')?:16);
$peer=proc_open(['python3',$root.'/tests/fixtures/websocket_peer.py',"$root/build/test-certs/cert.pem","$root/build/test-certs/key.pem"],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',"$root/build/ws-soak-peer.log",'a']],$pipes,null,array_merge(getenv(),['PYTHONPATH'=>$root.'/.local/ws-peer-17.4']));
$ports=json_decode(fgets($pipes[1]),true,512,JSON_THROW_ON_ERROR);$url='ws://127.0.0.1:'.$ports['ws'];
$start=Scheduler::now();$cycles=0;
try{
Async::run(function()use($seconds,$connections,$url,$start,&$cycles):void{
    $clients=[];$restarts=0;$cancellations=0;$next=0;
    try{
        for($i=0;$i<$connections;$i++)$clients[]=Async::await(Client::connect($url));
        while(Scheduler::now()-$start<$seconds){
            $jobs=[];
            foreach($clients as$i=>$client)$jobs[]=Async::spawn(function()use($client,$i,$cycles):void{
                $payload=$i.':'.$cycles.':'.str_repeat('x',1024);$client->send($payload);ensure($client->receive()?->data===$payload,'WebSocket echo mismatch');
                if($cycles%10===0)Async::await($client->ping());
            });
            Async::gather($jobs);$cycles++;
            if($cycles%100===0){
                $index=$restarts%$connections;$clients[$index]->close();$clients[$index]=Async::await(Client::connect($url));$restarts++;
                $wait=Async::spawn($clients[$index]->receive(...));Async::sleep(0);$wait->cancel();
                try{$wait->await();throw new RuntimeException('Read cancellation ignored');}catch(CancelledException){$cancellations++;}
            }
            if($cycles%25===0)Async::sleep(.02);
            gc_collect_cycles();$elapsed=Scheduler::now()-$start;
            foreach($clients as$client)ensure(!$client->statistics()['closed']&&$client->statistics()['queuedBytes']===0,'Retained WebSocket queue/closed connection');
            if($elapsed>=$next){
                echo json_encode(['type'=>'sample','seconds'=>$elapsed,'cycles'=>$cycles,'tasksExecuted'=>$cycles*$connections,'phpBytes'=>memory_get_usage(),'phpAllocatedBytes'=>memory_get_usage(true),'os'=>osSample(),'native'=>Runtime::information(),'scheduler'=>Runtime::diagnostics(),'connections'=>count($clients),'reconnections'=>$restarts,'cancellations'=>$cancellations,'unexpectedErrors'=>0],JSON_THROW_ON_ERROR),"\n";$next=$elapsed+5;
            }
        }
    }finally{foreach($clients as$client)$client->close();}
});
gc_collect_cycles();ensure(Future::pendingCount()===0,'WebSocket pending Futures after shutdown');
echo json_encode(['type'=>'complete','seconds'=>Scheduler::now()-$start,'cycles'=>$cycles,'native'=>Runtime::information(),'scheduler'=>Runtime::diagnostics(),'os'=>osSample()],JSON_THROW_ON_ERROR),"\n";
}finally{fclose($pipes[0]);fclose($pipes[1]);proc_close($peer);}
