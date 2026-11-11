<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Future};
use Asyncara\HTTP\HttpClient;
use Asyncara\Redis\Connection;
putenv('ASYNCARA_BACKEND='.$config['backend']);putenv('ASYNCARA_HTTP_BACKEND=curl-multi');
foreach(['http_proxy','HTTP_PROXY','https_proxy','HTTPS_PROXY','all_proxy','ALL_PROXY']as$key)putenv($key);
$before=asyncara_info();foreach(['pendingOperations','nativeMemoryBytes','nativeWorkers']as$key)if($before[$key])throw new RuntimeException('Cross-request native state');
$id=$_SERVER['AUDIT_ID'];$start=microtime(true);$mode=$_GET['mode']??'';$prefix='asyncara-fpm-'.getmypid().'-';
$connect=static fn()=>Connection::connect('127.0.0.1',$config['port'],password:$config['password'],user:'asyncara',resp3:true,tls:$config['tls'],caFile:$config['ca'])->await();
$result=Async::run(function()use($connect,$prefix,$id,$mode):array{
 $c=$connect();$writer=$connect();
 try{
  $clients=$c->command(['CLIENT','LIST'])->await();
  if(str_contains($clients,'name='.$prefix))throw new RuntimeException('Previous request retained Redis connection');
  $c->command(['CLIENT','SETNAME',$prefix.$id])->await();
  $writer->command(['CLIENT','SETNAME',$prefix.$id.'-writer'])->await();
  if($mode==='fatal'){
   $pending=$c->command(['BLPOP','asyncara:never',0]);
   Async::timeout(2,function()use($writer,$prefix,$id,$pending):void{
    while(true){
     if($pending->isCompleted())throw new RuntimeException('Fatal workload has no pending query');
     foreach(explode("\n",$writer->command(['CLIENT','LIST'])->await())as$line)
      if(str_contains($line,'name='.$prefix.$id.' ')&&preg_match('/ flags=[^ ]*b/',$line))return;
     Async::sleep(.001);
    }
   });
   ini_set('memory_limit','8M');str_repeat('x',16*1024*1024);exit;
  }
  $url=$_SERVER['AUDIT_URL'];$jobs=[];$client=new HttpClient(concurrency:4);
  $count=$mode==='io'?4:1;for($i=0;$i<$count;$i++)$jobs[]=$client->get($url);
  $ticks=0;$ticker=Async::spawn(function()use($jobs,&$ticks):void{while(array_filter($jobs,static fn($j)=>!$j->isCompleted())){Async::sleep(.005);if(array_filter($jobs,static fn($j)=>!$j->isCompleted()))$ticks++;}});
  $key='asyncara:fpm:'.getmypid().':'.$id;
  $c->command(['CLIENT','TRACKING','ON','BCAST','PREFIX',$key,'NOLOOP'])->await();
  $writer->command(['SET',$key,'value'])->await();
  if($c->pipeline([['PING'],['ECHO',$id]])->await()!==['PONG',$id])throw new RuntimeException('Push shifted FPM command replies');
  $notes=$c->drainNotifications();if($notes!==[['invalidate',[$key]]])throw new RuntimeException('Lost FPM push');
  $c->command(['CLIENT','TRACKING','OFF'])->await();$writer->command(['DEL',$key])->await();
  $intervals=array_map(static fn($r)=>$r->json(),Async::gather($jobs));$ticker->await();
  if($ticks<2||max(array_column($intervals,'start'))>=min(array_column($intervals,'end')))throw new RuntimeException('Original overlap/two-tick assertion failed');
  return ['ticks'=>$ticks,'http'=>$intervals,'redisNotifications'=>count($notes),'redisCommands'=>9];
 }finally{$c->close();$writer->close();}
});
gc_collect_cycles();$after=asyncara_info();if($after['pendingOperations']||$after['nativeMemoryBytes']||$after['nativeWorkers']||Future::pendingCount())throw new RuntimeException('FPM pending owner');
header('Content-Type: application/json');echo json_encode(['id'=>$id,'pid'=>getmypid(),'start'=>$start,'end'=>microtime(true),'before'=>$before,'after'=>$after,'result'=>$result],JSON_THROW_ON_ERROR);
