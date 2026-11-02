<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Asyncara\{Async,Runtime};
use Asyncara\WebSocket\Client;
$count=(int)$argv[1];$url=$argv[2];$rounds=50;$latencies=[];$begin=hrtime(true);
$time=Async::run(function()use($count,$url,$rounds,&$latencies):float{
    $clients=[];
    try{
        $opening=[];for($i=0;$i<$count;$i++)$opening[]=Client::connect($url);$clients=Async::gather($opening);
        $start=hrtime(true);$tasks=[];$payload=str_repeat('w',1024);
        foreach($clients as$client)$tasks[]=Async::spawn(function()use($client,$rounds,$payload,&$latencies):void{
            for($i=0;$i<$rounds;$i++){$t=hrtime(true);$client->send($payload);if($client->receive()?->data!==$payload)throw new RuntimeException('echo mismatch');$latencies[]=(hrtime(true)-$t)/1e6;}
        });
        Async::gather($tasks);return(hrtime(true)-$start)/1e9;
    }finally{Async::gather(array_map(fn($client)=>Async::spawn($client->close(...)),$clients));}
});
gc_collect_cycles();sort($latencies);$quantile=fn(float $q)=>$latencies[(int)ceil(count($latencies)*$q)-1];preg_match('/VmHWM:\s+(\d+)/',file_get_contents('/proc/self/status'),$rss);
echo json_encode(['implementation'=>'Asyncara','connections'=>$count,'messages'=>$count*$rounds,'payloadBytes'=>1024,'seconds'=>$time,'totalSeconds'=>(hrtime(true)-$begin)/1e9,'messagesPerSecond'=>$count*$rounds/$time,'medianMs'=>$quantile(.5),'p95Ms'=>$quantile(.95),'p99Ms'=>$quantile(.99),'allLatenciesMs'=>$latencies,'rssPeakKiB'=>(int)$rss[1],'phpPeakBytes'=>memory_get_peak_usage(true),'scheduler'=>Runtime::diagnostics()],JSON_THROW_ON_ERROR),"\n";
