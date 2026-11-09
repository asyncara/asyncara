<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Runtime};use Asyncara\HTTP\HttpClient;
$limit=(int)($argv[1]??8);
if($limit!==8)Runtime::configure(['httpMaxConnections'=>$limit,'httpMaxHostConnections'=>$limit]);
try{Runtime::configure(['httpMaxConnections'=>257]);throw new RuntimeException('Invalid limit accepted');}catch(ValueError){}
$p=proc_open(['python3',dirname(__DIR__,2).'/benchmarks/http/server.py'],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',dirname(__DIR__,2).'/build/connections-server.log','a']],$pipes);
try{
 $port=json_decode(fgets($pipes[1]),true,512,JSON_THROW_ON_ERROR)['port'];$ticks=0;
 Async::run(function()use($port,$limit,&$ticks){$client=new HttpClient(concurrency:32);$jobs=[];for($i=0;$i<32;$i++)$jobs[]=$client->get('http://127.0.0.1:'.$port.'/body?size=1024&delay=.05');$finished=false;$ticker=Async::spawn(function()use(&$finished,&$ticks){while(!$finished){Async::sleep(.001);if(!$finished)$ticks++;}});foreach(Async::gather($jobs)as$r)if(strlen($r->body)!==1024)throw new RuntimeException('response');$finished=true;$ticker->await();});
 try{asyncara_http_limits(64,8);throw new RuntimeException('Late reconfiguration accepted');}catch(Exception $e){if($e instanceof RuntimeException)throw$e;}
 if($ticks<2||asyncara_info()['pendingOperations']||asyncara_info()['nativeMemoryBytes'])throw new RuntimeException('Progress or cleanup');
}finally{fclose($pipes[0]);$stats=json_decode(trim(stream_get_contents($pipes[1])),true,512,JSON_THROW_ON_ERROR);fclose($pipes[1]);proc_close($p);}
if($stats['peak']!==$limit||$stats['connections']!==$limit)throw new RuntimeException('Wrong connection admission '.json_encode($stats));echo json_encode(['status'=>'PASS','limit'=>$limit,'ticks'=>$ticks,'server'=>$stats]),"\n";
