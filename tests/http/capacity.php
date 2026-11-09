<?php
declare(strict_types=1);
require (getenv('ASYNCARA_SOURCE_ROOT') ?: dirname(__DIR__,2)).'/autoload.php';
use Asyncara\{Async,Runtime,Scheduler};
use Asyncara\HTTP\HttpClient;
function check(bool $value,string $message):void {if(!$value)throw new RuntimeException($message);}
$server=proc_open(['python3',__DIR__.'/capacity_server.py'],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',dirname(__DIR__,2).'/build/capacity-server.log','a']],$pipes);
try {
 $port=json_decode(fgets($pipes[1]),true,512,JSON_THROW_ON_ERROR)['port'];
 Runtime::configure(['nativeMemoryLimit'=>3*1024*1024]);
 $result=Async::run(function()use($port,$pipes):array {
  $client=new HttpClient(concurrency:4,timeout:10);$pending=[];
  for($i=0;$i<4;$i++)$pending[]=$client->get('http://127.0.0.1:'.$port.'/');
  $deadline=Scheduler::now()+5;
  while(asyncara_info()['nativeMemoryBytes']<2*1024*1024){
   check(Scheduler::now()<$deadline,'Bodies did not reach controlled capacity boundary');
   foreach($pending as$p)check(!$p->isCompleted(),'Premature completion before release');
   Async::sleep(.001);
  }
  $ticks=0;
  for($i=0;$i<2;$i++){Async::sleep(.002);foreach($pending as$p)check(!$p->isCompleted(),'I/O completed before ticker');$ticks++;}
  $before=asyncara_info();check(fwrite($pipes[0],"release\n")===8,'Control write failed');
  foreach($pending as$p){$response=$p->await();check($response->status===200 && $response->body===str_repeat('x',524288),'Body mismatch');}
  return ['ticksWhilePending'=>$ticks,'responses'=>4,'bodyBytes'=>524288,'nativeBeforeRelease'=>$before];
 });
 $after=asyncara_info();check($after['pendingOperations']===0 && $after['nativeMemoryBytes']===0,'Native ownership escaped collection');
 echo json_encode(['status'=>'PASS','result'=>$result,'after'=>$after],JSON_THROW_ON_ERROR),"\n";
}finally{
 fclose($pipes[0]);stream_get_contents($pipes[1]);fclose($pipes[1]);check(proc_close($server)===0,'Peer failed');
}
