<?php
declare(strict_types=1);
$port=(int)$argv[1];$samples=[];
for($sample=0;$sample<3;$sample++){
 $times=[];$start=hrtime(true);
 Swoole\Coroutine\run(function()use($port,&$times){$done=new Swoole\Coroutine\Channel(4);for($w=0;$w<4;$w++)go(function()use($port,&$times,$done){$client=new Swoole\Coroutine\Http\Client('127.0.0.1',$port);$client->set(['timeout'=>10,'keep_alive'=>false]);for($i=0;$i<6;$i++){$t=hrtime(true);$client->get('/slow?delay=.02');if($client->body!=='hello asyncara')throw new RuntimeException('Swoole response mismatch');$times[]=(hrtime(true)-$t)/1e6;}$client->close();$done->push(true);});for($i=0;$i<4;$i++)$done->pop();});
 sort($times);$elapsed=(hrtime(true)-$start)/1e9;$samples[]=['seconds'=>$elapsed,'operations'=>24,'operationsPerSecond'=>24/$elapsed,'medianMs'=>$times[11],'p95Ms'=>$times[21],'p99Ms'=>$times[22]];
}
echo json_encode(['mode'=>'swoole-http','version'=>SWOOLE_VERSION,'samples'=>$samples],JSON_PRETTY_PRINT),"\n";
