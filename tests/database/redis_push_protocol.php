<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Future,Runtime};
use Asyncara\Concurrency\Event;
use Asyncara\IO\TcpServer;
use Asyncara\Redis\Connection;
use Asyncara\Exception\OperationException;
function verify(bool $value,string $why):void{if(!$value)throw new RuntimeException($why);}
function frame(array $values):string{$wire='*'.count($values)."\r\n";foreach($values as$value)$wire.='$'.strlen($value)."\r\n".$value."\r\n";return$wire;}
$notes=">2\r\n+invalidate\r\n*1\r\n$3\r\nkey\r\n";
$counts=[];
Async::run(function()use($notes,&$counts):void{
 Async::timeout(5,function()use($notes,&$counts):void{
  $server=new TcpServer();$port=(int)substr(strrchr($server->address(),':'),1);$release=new Event();
  $peer=Async::spawn(function()use($server,$notes,$release):void{
   $c=$server->accept();try{
    $request=frame(['PING']).frame(['ECHO','second']);verify($c->readExactly(strlen($request))===$request,'Request framing');
    $c->write(substr($notes,0,3));Async::sleep(0);$c->write(substr($notes,3));$release->wait();
    $c->write("+PONG\r\n$6\r\nsecond\r\n");verify($c->read()==='','Client failed to release descriptor');
   }finally{$c->close();}
  });
  $c=Connection::connect('127.0.0.1',$port,notificationLimit:1,notificationByteLimit:strlen($notes))->await();
  try{
   $operation=$c->pipeline([['PING'],['ECHO','second']]);
   while(!$c->statistics()['notifications']){verify(!$operation->isCompleted(),'Operation completed before notification');Async::sleep(.001);}
   $ticks=0;for($n=0;$n<2;$n++){Async::sleep(.002);verify(!$operation->isCompleted(),'Reply arrived before peer release');$ticks++;}
   verify($c->statistics()['notificationBytes']===strlen($notes),'Exact wire budget');
   verify($c->drainNotifications()===[['invalidate',['key']]],'Wrong separately owned push');
   verify($c->statistics()['notificationBytes']===0,'Drain budget retained');$release->set();
   verify($operation->await()===['PONG','second'],'Push shifted pipeline results');$counts['ticksWhileCommandPending']=$ticks;
  }finally{$c->close();$server->close();}$peer->await();
 });
 foreach([
  'nested-push'=>["*1\r\n".$notes,"Redis push cannot be nested"],
  'nested-push-map'=>["%1\r\n+key\r\n".$notes,"Redis push cannot be nested"],
  'count-bound'=>[$notes.$notes."+PONG\r\n","notification queue limit"],
  'truncated-after-push'=>[$notes,"Redis connection closed"],
 ]as$name=>[$wire,$error]){
  Async::timeout(5,function()use($name,$wire,$error,&$counts):void{
   $server=new TcpServer();$port=(int)substr(strrchr($server->address(),':'),1);
   $peer=Async::spawn(function()use($server,$wire,$name):void{
    $s=$server->accept();try{$request=frame(['PING']);verify($s->readExactly(strlen($request))===$request,'Malformed request');$s->write($wire);if($name!=='truncated-after-push')verify($s->read()==='','Rejected reply did not close socket');}finally{$s->close();}
   });
   $c=Connection::connect('127.0.0.1',$port,notificationLimit:1)->await();
   try{
    try{$c->command(['PING'])->await();throw new RuntimeException('Bad frame accepted: '.$name);}catch(OperationException $e){verify(str_contains($e->getMessage(),$error),'Wrong error: '.$e->getMessage());}
    verify($c->statistics()['closed']&&$c->statistics()['notifications']===0&&$c->statistics()['notificationBytes']===0,'Error ownership');
   }finally{$c->close();$c->close();$server->close();}$peer->await();$counts[$name]='PASS';
  });
 }
 foreach(['bad-ack-map','bad-message-map','wrong-ack-channel']as$name){
  Async::timeout(5,function()use($name,&$counts):void{
   $server=new TcpServer();$port=(int)substr(strrchr($server->address(),':'),1);
   $peer=Async::spawn(function()use($server,$name):void{
    $s=$server->accept();try{
     $request=frame(['SUBSCRIBE','channel']);verify($s->readExactly(strlen($request))===$request,'Subscribe request');
     if($name==='bad-message-map')$s->write("*3\r\n+subscribe\r\n+channel\r\n:1\r\n");
     $s->write($name==='wrong-ack-channel'?"*3\r\n+subscribe\r\n+wrong\r\n:1\r\n":"%3\r\n+a\r\n:1\r\n+b\r\n:2\r\n+c\r\n:3\r\n");
     verify($s->read()==='','Malformed subscription retained socket');
    }finally{$s->close();}
   });
   $c=Connection::connect('127.0.0.1',$port)->await();
   set_error_handler(static function(int $number,string $text):never{throw new ErrorException($text,0,$number);});
   try{
    try{$c->subscribe(['channel'])->await();if($name==='bad-message-map')$c->nextMessage();throw new RuntimeException('Malformed subscription accepted');}
    catch(OperationException $e){verify(str_contains($e->getMessage(),'Unexpected Redis'),'Wrong subscription error');}
    verify($c->statistics()['closed'],'Malformed subscription owner survived');
   }finally{restore_error_handler();$c->close();$server->close();}$peer->await();$counts[$name]='PASS';
  });
 }
});
gc_collect_cycles();$info=asyncara_info();$metrics=Runtime::diagnostics();verify(!$info['pendingOperations']&&!$info['nativeMemoryBytes']&&!Future::pendingCount()&&!$metrics['ioWatchers']&&!$metrics['activeTimers'],'Retained native/request owners');
echo json_encode(['status'=>'PASS','php'=>PHP_VERSION,'cases'=>$counts,'native'=>$info,'scheduler'=>$metrics],JSON_THROW_ON_ERROR),"\n";
