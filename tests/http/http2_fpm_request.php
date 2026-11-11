<?php
declare(strict_types=1);
putenv('ASYNCARA_HTTP_BACKEND=curl-multi');putenv('ASYNCARA_BACKEND='.$config['backend']);
foreach(['http_proxy','HTTP_PROXY','https_proxy','HTTPS_PROXY','all_proxy','ALL_PROXY']as$key)putenv($key);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Future,Runtime};
use Asyncara\HTTP\HttpClient;
$before=asyncara_info();foreach(['pendingOperations','nativeMemoryBytes','nativeWorkers','httpCachedHandles']as$key)if($before[$key]!==0)throw new RuntimeException('Cross-request state '.$key);
$id=$_SERVER['AUDIT_ID'];$start=microtime(true);$mode=$_GET['mode']??'';$url=$config['url'];
if($mode==='fatal'){
 asyncara_submit('http',[$url.'/fpm',null,['method'=>'GET','headers'=>[],'caFile'=>$config['ca']],5000]);asyncara_poll([],[],0);
 if(!asyncara_info()['pendingOperations'])throw new RuntimeException('Fatal control lost pending operation');
 ini_set('memory_limit','8M');str_repeat('x',16*1024*1024);exit;
}
Runtime::configure(['httpMaxConnections'=>4,'httpMaxHostConnections'=>1]);$count=$mode==='io'?4:1;
$result=Async::run(function()use($count,$url,$config):array{
 $client=new HttpClient(concurrency:4,caFile:$config['ca']);$warm=$client->get($url.'/ok')->await();
 if($warm->headers['x-test-protocol']!==['h2'])throw new RuntimeException('HTTP/2 not negotiated');$connection=(int)$warm->headers['x-test-connection'][0];
 $jobs=[];for($n=0;$n<$count;$n++)$jobs[]=$client->get($url.'/fpm');
 $ticks=0;$ticker=Async::spawn(function()use($jobs,&$ticks):void{while(array_filter($jobs,static fn($job)=>!$job->isCompleted())){Async::sleep(.005);if(array_filter($jobs,static fn($job)=>!$job->isCompleted()))$ticks++;}});
 $intervals=array_map(static fn($response)=>$response->json(),Async::gather($jobs));$ticker->await();
 if($ticks<2||max(array_column($intervals,'start'))>=min(array_column($intervals,'end')))throw new RuntimeException('Original overlap/two-tick assertion failed');
 if(array_unique(array_column($intervals,'connection'))!==[$connection]||count(array_unique(array_column($intervals,'stream')))!==$count)throw new RuntimeException('Streams not multiplexed');
 return ['ticks'=>$ticks,'http'=>$intervals];
});
gc_collect_cycles();$after=asyncara_info();if($after['pendingOperations']||$after['nativeMemoryBytes']||$after['nativeWorkers']||Future::pendingCount())throw new RuntimeException('HTTP/2 ownership');
header('Content-Type: application/json');echo json_encode(['id'=>$id,'pid'=>getmypid(),'start'=>$start,'end'=>microtime(true),'before'=>$before,'after'=>$after,'result'=>$result],JSON_THROW_ON_ERROR);
