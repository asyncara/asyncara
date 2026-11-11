<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Future,Runtime};
use Asyncara\Redis\{Connection,ServerException};
use Asyncara\Exception\{OperationException,CancelledException};
$config=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function rejected(callable $body,string $text):void{try{$body();}catch(OperationException $e){check(str_contains($e->getMessage(),$text),'Wrong rejection '.$e->getMessage());return;}throw new RuntimeException('Missing rejection '.$text);}
function connectRedis(array $c,bool $resp3=true,int $limit=1024,int $bytes=1048576):Connection{return Connection::connect('127.0.0.1',$c['port'],password:$c['password'],user:'asyncara',resp3:$resp3,tls:$c['tls'],caFile:$c['ca'],notificationLimit:$limit,notificationByteLimit:$bytes)->await();}
$events=[];
Async::run(function()use($config,&$events):void{
 foreach([[0,1],[1025,1],[1,0],[1,1048577]]as[$count,$bytes]){
  try{Connection::connect(notificationLimit:$count,notificationByteLimit:$bytes);throw new RuntimeException('Invalid limit admitted');}catch(ValueError){}
 }
 $writer=connectRedis($config);
 try{
  foreach([false,true]as$resp3){
   $c=connectRedis($config,$resp3);$s=connectRedis($config,$resp3);
   try{
    check($c->pipeline([['SET','asyncara:binary',"a\0b"],['GET','asyncara:binary'],['MISSING_ASYNCARA_COMMAND'],['GET','asyncara:absent']])->await()[1]==="a\0b",'Binary pipeline');
    check($c->transaction([['SET','asyncara:tx','yes'],['GET','asyncara:tx']])->await()===['OK','yes'],'Transaction framing');
    $s->subscribe(['asyncara:channel','asyncara:channel2'])->await();
    $owner=Async::spawn($s->nextMessage(...));$waiter=Async::spawn($owner->await(...));$ticks=0;
    for($i=0;$i<2;$i++){Async::sleep(.002);check(!$owner->isCompleted(),'Message completed before peer release');$ticks++;}
    $waiter->cancel();try{$waiter->await();throw new RuntimeException('Cancelled waiter succeeded');}catch(CancelledException){}
    check(!$owner->isCompleted(),'Waiter cancelled shared owner');
    $writer->command(['PUBLISH','asyncara:channel',"binary\0value"])->await();
    check($owner->await()===['message','asyncara:channel',"binary\0value"],'Pub/Sub message association');
    check($s->statistics()['notifications']===0,'Pub/Sub entered command notification queue');
    $events[]=['protocol'=>$resp3?3:2,'ticksWhileMessagePending'=>$ticks,'sharedWaiter'=>'PASS'];
   }finally{$s->close();$s->close();$c->close();}
  }
  $c=connectRedis($config);
  try{
   check($c->command(['CLIENT','TRACKING','ON','BCAST','PREFIX','asyncara:push:','NOLOOP'])->await()==='OK','Tracking setup');
   // Each completed writer command causes an independent real server push.
   for($i=0;$i<4;$i++){
    $writer->command(['SET','asyncara:push:'.$i,'value'])->await();
    $reply=$c->pipeline([['PING'],['ECHO','round-'.$i],['MISSING_ASYNCARA_COMMAND']])->await();
    check($reply[0]==='PONG'&&$reply[1]==='round-'.$i&&$reply[2] instanceof ServerException,'Push shifted command replies');
   }
   $stats=$c->statistics();check($stats['notifications']===4&&$stats['notificationBytes']>0,'Push accumulation');
   $notes=$c->drainNotifications();check(count($notes)===4,'Push drain count');
   foreach($notes as$i=>$note)check($note===['invalidate',['asyncara:push:'.$i]],'Push ordering/value');
   check($c->drainNotifications()===[]&&$c->statistics()['notificationBytes']===0,'Drain released accounting');
   $writer->command(['SET','asyncara:push:after-drain','value'])->await();
   check($c->command(['PING'])->await()==='PONG'&&count($c->drainNotifications())===1,'Drain capacity recovery');
   $events[]=['tracking'=>'PASS','notifications'=>5,'peakWireBytes'=>$stats['notificationBytes']];
  }finally{$c->close();}
  foreach(['count','bytes']as$bound){
   $c=connectRedis($config,limit:$bound==='count'?2:1024,bytes:$bound==='bytes'?1:1048576);
   try{
    $c->command(['CLIENT','TRACKING','ON','BCAST','PREFIX','asyncara:limit:','NOLOOP'])->await();
    if($bound==='count')for($i=0;$i<2;$i++){$writer->command(['SET','asyncara:limit:'.$i,'v'])->await();check($c->command(['PING'])->await()==='PONG','Pre-bound reply');}
    $writer->command(['SET','asyncara:limit:overflow','v'])->await();
    rejected(fn()=>$c->command(['PING'])->await(),'notification queue limit');
    check($c->statistics()['closed']&&$c->statistics()['notifications']===0&&$c->statistics()['notificationBytes']===0,'Bound cleanup');
    rejected(fn()=>$c->command(['PING'])->await(),'closed');$events[]=['capacity'=>$bound,'result'=>'PASS'];
   }finally{$c->close();}
  }
  $c=connectRedis($config);
  try{
   $owner=$c->command(['BLPOP','asyncara:blocked',0]);$waiter=Async::spawn($owner->await(...));
   Async::sleep(.002);$waiter->cancel();try{$waiter->await();}catch(CancelledException){}
   check(!$owner->isCompleted(),'Waiter cancelled query owner');
   $writer->command(['LPUSH','asyncara:blocked','released'])->await();check($owner->await()===['asyncara:blocked','released'],'Shared blocking reply');
   $owner=$c->command(['BLPOP','asyncara:blocked',0]);Async::sleep(.002);$owner->cancel();
   try{$owner->await();throw new RuntimeException('Operation cancellation ignored');}catch(CancelledException){}
   check($c->statistics()['closed'],'Cancellation retained connection');
  }finally{$c->close();}
  check($writer->command(['PING'])->await()==='PONG','Independent connection damaged');
 }finally{$writer->close();}
});
gc_collect_cycles();$native=asyncara_info();$d=Runtime::diagnostics();
check(!$native['pendingOperations']&&!$native['nativeMemoryBytes']&&!Future::pendingCount()&&!$d['activeTasks']&&!$d['activeTimers']&&!$d['ioWatchers'],'Owners retained after completion');
echo json_encode(['status'=>'PASS','php'=>PHP_VERSION,'events'=>$events,'native'=>$native,'scheduler'=>$d],JSON_THROW_ON_ERROR),"\n";
