<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Runtime};
use Asyncara\Concurrency\Event;
use Asyncara\IO\TcpStream;
use Asyncara\Exception\TimeoutException;
$n=(int)($argv[1]??10000);$wins=['readiness'=>0,'timeout'=>0];$start=hrtime(true);
function requireTrue(bool $x,string $why):void{if(!$x)throw new RuntimeException($why);}
Async::run(function()use($n,&$wins):void{
 for($i=0;$i<$n;$i++){
  $pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP);
  $a=new TcpStream($pair[0]);$b=new TcpStream($pair[1]);
  try{
   // Same-turn expiry and actual socket readiness. Either documented winner
   // is legal; lost completion, wrong data and retained watchers are not.
   $entered=new Event();$job=Async::spawn(function()use($a,$entered):string{$entered->set();return Async::timeout(0,fn()=>$a->read(1));});
   $entered->wait();$b->write('x');
   try{requireTrue($job->await()==='x','wrong competed read');$wins['readiness']++;}
   catch(TimeoutException){$wins['timeout']++;}
  }finally{$a->close();$b->close();}
 }
 // Real socket send-buffer backpressure. No peer consumption until two ticks.
 $pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP);
 $a=new TcpStream($pair[0]);$b=new TcpStream($pair[1]);$release=new Event();$entered=new Event();$payload=str_repeat('p',4*1024*1024);
 try{
  $writer=Async::spawn(function()use($a,$entered,$payload):void{$entered->set();$a->write($payload);});
  $reader=Async::spawn(function()use($b,$release,$payload):void{$release->wait();$received=0;while($received<strlen($payload)){$chunk=$b->read(32768);requireTrue($chunk!==''&&strspn($chunk,'p')===strlen($chunk),'partial read contents');$received+=strlen($chunk);}requireTrue($received===strlen($payload),'partial transfer length');});
  $entered->wait();for($i=0;$i<2;$i++){Async::sleep(.001);requireTrue(!$writer->isCompleted(),'write did not remain pending through two ticks');}
  $release->set();$writer->await();$reader->await();
 }finally{$a->close();$b->close();}
});
gc_collect_cycles();$s=Runtime::diagnostics();requireTrue(!$s['activeTasks']&&!$s['ioWatchers']&&!$s['activeTimers']&&!$s['pendingFutures'],'deadline cleanup');
echo json_encode(['status'=>'PASS','iterations'=>$n,'competingWinners'=>$wins,'partialWriteBytes'=>4*1024*1024,'writePendingTicks'=>2,'wallSeconds'=>(hrtime(true)-$start)/1e9,'scheduler'=>$s])."\n";
