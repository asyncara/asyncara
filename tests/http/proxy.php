<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Scheduler};use Asyncara\HTTP\HttpClient;use Asyncara\Exception\OperationException;
function expectError(callable $f):string{try{$f();}catch(OperationException $e){return $e->getMessage();}throw new RuntimeException('Expected transport failure');}
$root=dirname(__DIR__,2);$origin=proc_open(['python3',dirname(__DIR__).'/fixtures/http_stream_server.py',"$root/build/test-certs/cert.pem","$root/build/test-certs/key.pem"],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',"$root/build/proxy-origin.log",'a']],$op);$ports=json_decode(fgets($op[1]),true,512,JSON_THROW_ON_ERROR);
$proxy=proc_open(['python3',__DIR__.'/proxy.py',json_encode($ports)],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',"$root/build/proxy.log",'a']],$pp);$p=json_decode(fgets($pp[1]),true,512,JSON_THROW_ON_ERROR);$ticks=0;
try{
 Async::run(function()use($root,$p,$ports,&$ticks){
  $url='http://127.0.0.1:'.$p['proxy'];$client=new HttpClient(proxy:$url,caFile:"$root/build/test-certs/cert.pem");
  if(strlen($client->get('http://proxy-target.invalid/small')->await()->body)!==65536)throw new RuntimeException('HTTP proxy not used');
  $s=$client->getStream('https://localhost:'.$ports['https'].'/small');$bytes=0;$s->consume(function($c)use(&$bytes){$bytes+=strlen($c);});if($bytes!==65536)throw new RuntimeException('CONNECT streaming');
  expectError(fn()=>(new HttpClient(proxy:$url))->get('https://localhost:'.$ports['https'].'/small')->await());
  $pending=true;$ticker=Async::spawn(function()use(&$pending,&$ticks){while($pending){Async::sleep(.001);if($pending)$ticks++;}});$start=Scheduler::now();
  $timeoutError=expectError(fn()=>(new HttpClient(timeout:2,connectTimeout:.04,caFile:"$root/build/test-certs/cert.pem"))->get('https://localhost:'.$p['silent'].'/')->await());
  $pending=false;$ticker->await();$elapsed=Scheduler::now()-$start;
  if($elapsed>.8||$ticks<2)throw new RuntimeException('TLS connect timeout blocked or ignored: '.json_encode(['elapsed'=>$elapsed,'ticks'=>$ticks,'transportError'=>$timeoutError,'native'=>asyncara_info(),'scheduler'=>\Asyncara\Runtime::diagnostics()]));
  if(asyncara_info()['pendingOperations']||asyncara_info()['nativeMemoryBytes'])throw new RuntimeException('Proxy cleanup');
 });
}finally{fclose($pp[0]);$stats=stream_get_contents($pp[1]);fclose($pp[1]);proc_close($proxy);fclose($op[0]);fclose($op[1]);proc_close($origin);}
$stats=json_decode(trim($stats),true,512,JSON_THROW_ON_ERROR);if($stats['http']!==1||$stats['connect']!==2||$stats['silent']!==1)throw new RuntimeException('Proxy/timeout route not exercised');
echo json_encode(['status'=>'PASS','ticksWhilePending'=>$ticks,'fixture'=>$stats]),"\n";
