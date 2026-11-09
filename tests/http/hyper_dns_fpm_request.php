<?php
declare(strict_types=1);
// Additional DNS lifecycle fixture. The original numeric/Phase 4 fixtures remain unchanged.
putenv('ASYNCARA_HTTP_BACKEND=hyper-prototype');
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Runtime};use Asyncara\HTTP\HttpClient;
$before=asyncara_info();
foreach(['hyperSubmitted','hyperCompleted','hyperConnections','pendingOperations','nativeMemoryBytes']as$key)
 if($before[$key]!==0)throw new RuntimeException('Cross-request owner '.$key);
if($before['httpBackend']!=='hyper-prototype'||!$before['hyperPrototypeBuilt'])throw new RuntimeException('Wrong backend');
$_SERVER['AUDIT_URL']=str_replace('http://127.0.0.1:', 'http://localhost:', $_SERVER['AUDIT_URL']);
$id=$_SERVER['AUDIT_ID'];$start=microtime(true);$mode=$_GET['mode']??'';
if($mode==='fatal'){
 Async::run(function():void {
  (new HttpClient())->get($_SERVER['AUDIT_URL']);
  while(asyncara_info()['nativeWorkers']!==4) Async::sleep(0);
  ini_set('memory_limit','8M');str_repeat('x',16*1024*1024);
 });exit;
}
$count=$mode==='io'?4:1;
$result=Async::run(function()use($count){
 $c=new HttpClient(concurrency:4);$jobs=[];
 for($n=0;$n<$count;$n++)$jobs[]=$c->get($_SERVER['AUDIT_URL']);
 $ticks=0;$ticker=Async::spawn(function()use($jobs,&$ticks){
  while(array_filter($jobs,static fn($j)=>!$j->isCompleted())){
   Async::sleep(.005);if(array_filter($jobs,static fn($j)=>!$j->isCompleted()))$ticks++;
  }
 });
 $intervals=array_map(static fn($r)=>$r->json(),Async::gather($jobs));$ticker->await();
 if($ticks<2||max(array_column($intervals,'start'))>=min(array_column($intervals,'end')))throw new RuntimeException('Original overlap/two-tick assertion failed');
 return ['ticks'=>$ticks,'http'=>$intervals];
});
$after=asyncara_info();if($after['hyperSubmitted']!==$count||$after['hyperCompleted']!==$count||$after['pendingOperations']||$after['nativeMemoryBytes']||$after['nativeWorkers']!==4)throw new RuntimeException('Hyper native ownership');
header('Content-Type: application/json');echo json_encode(['id'=>$id,'pid'=>getmypid(),'start'=>$start,'end'=>microtime(true),'before'=>$before,'after'=>$after,'result'=>$result],JSON_THROW_ON_ERROR);
