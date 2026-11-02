<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Context,Runtime,Hook,Future};
use Asyncara\HTTP\HttpClient;
use Asyncara\IO\Files;
use Asyncara\SQLite\Connection;
$before=Runtime::information();
if($before->pendingOperations||$before->nativeMemoryBytes||$before->openFileHandles||Runtime::timeHooksEnabled()||Runtime::diagnostics()!==[]||Runtime::configuration()['taskLimit']!==20000||Runtime::configuration()['futureLimit']!==40000||Future::pendingCount()!==0)throw new RuntimeException('Cross-request runtime state');
final class RequestStateProbe { public static ?string $id=null; }
if(isset($GLOBALS['asyncara_request_probe'])||RequestStateProbe::$id!==null)throw new RuntimeException('Cross-request PHP state');
$GLOBALS['asyncara_request_probe']=RequestStateProbe::$id=$_SERVER['AUDIT_ID'];
$mode=$_GET['mode']??'lifecycle';$start=microtime(true);
if($mode==='fatal'||$mode==='fatal-burst'){
 usleep(25000); // Give concurrent fatal requests time to occupy distinct workers.
 ini_set('memory_limit','8M');
 for($i=0;$i<($mode==='fatal-burst'?4:1);$i++)asyncara_submit('read',[$_SERVER['AUDIT_LARGE_FILE']]);
 if($mode==='fatal-burst')usleep(100000); // Deliberate fault fixture: populate all worker allocator arenas before conversion.
 while(true)asyncara_poll([],[],1000);
}
Runtime::configure(['taskLimit'=>1234,'futureLimit'=>4321]);Runtime::enableHooks(Hook::TIME);
if($mode==='timeout')Async::run(function():void{
    for($i=0;$i<8;$i++)asyncara_submit('file_open',[__FILE__,'r']);
    Async::sleep(30);
});
if($mode==='pending'){
    for($i=0;$i<8;$i++)asyncara_submit('file_open',[__FILE__,'r']);
    $unsettled=new Future();
    asyncara_submit('http',[$_SERVER['AUDIT_URL'],null,[],10000]);
    header('Content-Type: application/json');
    echo json_encode(['pid'=>getmypid(),'id'=>$_SERVER['AUDIT_ID'],'start'=>$start,'end'=>microtime(true),'before'=>$before,'pending'=>Runtime::information()]);
    exit;
}
$result=Async::run(function()use($mode):array{
 if(Context::get('request')!==null)throw new RuntimeException('Cross-request context');
 Context::set('request',$_SERVER['AUDIT_ID']);
 $db=Connection::open(':memory:')->await();
 try{
  if($db->query('select 42 as n')->await()[0]['n']!==42)throw new RuntimeException('SQL result');
  if(Files::read(__FILE__)->await()==='')throw new RuntimeException('File result');
  if($mode==='lifecycle'){usleep(1000);return ['ticks'=>0,'http'=>[]];}
  $client=new HttpClient(4);$jobs=[];for($i=0;$i<4;$i++)$jobs[]=$client->get($_SERVER['AUDIT_URL']);
  $ticks=0;$ticker=Async::spawn(function()use($jobs,&$ticks):void{while(count(array_filter($jobs,fn($j)=>!$j->isCompleted()))){Async::sleep(.005);if(count(array_filter($jobs,fn($j)=>!$j->isCompleted())))$ticks++;}});
  $responses=Async::gather($jobs);$ticker->await();
  $intervals=array_map(fn($r)=>$r->json(),$responses);
  if($ticks<2||max(array_column($intervals,'start'))>=min(array_column($intervals,'end')))throw new RuntimeException('I/O did not overlap or blocked loop: '.json_encode(['ticks'=>$ticks,'intervals'=>$intervals]));
  return ['ticks'=>$ticks,'http'=>$intervals];
 }finally{$db->close()->await();}
});
$after=Runtime::information();
for($i=0;$i<8;$i++)asyncara_submit('file_open',[__FILE__,'r']);
header('Content-Type: application/json');
echo json_encode(['pid'=>getmypid(),'id'=>$_SERVER['AUDIT_ID'],'start'=>$start,'end'=>microtime(true),'before'=>$before,'after'=>$after,'result'=>$result],JSON_THROW_ON_ERROR);
