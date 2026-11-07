<?php
declare(strict_types=1);
namespace Asyncara {
 // Test-only namespace functions. The production Scheduler, heap, turn,
 // suspend/resume and timeout code are loaded unchanged. No kernel claim here.
 final class TestClock {public static int $ns=1000000000;public static int $polls=0;public static array $timeouts=[];}
 function hrtime(bool $asNumber=false):array|int{if(!$asNumber)return [intdiv(TestClock::$ns,1000000000),TestClock::$ns%1000000000];return TestClock::$ns;}
 function asyncara_poll(array $read,array $write,int $milliseconds):array{
  if($read||$write||$milliseconds<0)throw new \RuntimeException('Virtual test attempted unbounded/native I/O wait');
  TestClock::$polls++;TestClock::$timeouts[$milliseconds]=(TestClock::$timeouts[$milliseconds]??0)+1;
  TestClock::$ns+=$milliseconds*1000000;
  return ['read'=>[],'write'=>[],'results'=>[],'httpReadable'=>[],'httpWritable'=>[]];
 }
}
namespace {
 require dirname(__DIR__,2).'/autoload.php';
 use Asyncara\{Async,Scheduler,Runtime,TestClock};
 use Asyncara\Exception\TimeoutException;
 $n=(int)($argv[1]??10000);$seed=(int)($argv[2]??17);mt_srand($seed);$start=hrtime(true);$counts=[];
 function ensure(bool $x,string $m):void{if(!$x)throw new RuntimeException($m);}
 try{
  Async::run(function()use($n,&$counts):void{
   $fired=[];$expected=[];$handles=[];
   for($i=0;$i<$n;$i++){$delay=mt_rand(0,100)*.001;$expected[]=[$delay,$i];$handles[]=Scheduler::current()->delay($delay,function()use(&$fired,$i):void{$fired[]=$i;});}
   usort($expected,fn($a,$b)=>$a[0]<=>$b[0]?:$a[1]<=>$b[1]);Async::sleep(.2);
   ensure($fired===array_column($expected,1),'deadline heap FIFO/order');$counts['orderedTimers']=$n;
   $fired=[];for($i=0;$i<$n;$i++){Scheduler::current()->delay(3600,static function():void{throw new RuntimeException('cancelled timer fired');})->cancel();Scheduler::current()->delay(0,function()use(&$fired,$i):void{$fired[]=$i;});}
   // Existing expired deadlines, not a replacement timer algorithm.
   TestClock::$ns+=86400*1000000000;Async::sleep(0);ensure($fired===range(0,$n-1),'past deadline/jump');$counts['cancelledTimers']=$n;$counts['pastAndEqualTimers']=$n;
   $done=0;while($done<$n){$batch=min(64,$n-$done);$tasks=[];for($i=0;$i<$batch;$i++)$tasks[]=Async::spawn(static function():bool{try{Async::timeout(0,static fn()=>Async::sleep(1));}catch(TimeoutException){return true;}return false;});
   ensure(Async::gather($tasks)===array_fill(0,$batch,true),'timeout transitions');$done+=$batch;}$counts['timeouts']=$n;
   $ticks=0;$repeat=Async::repeat(.001,function()use(&$ticks):void{$ticks++;});
   while($ticks<$n)Async::sleep(.001);$repeat->cancel();try{$repeat->await();}catch(Asyncara\Exception\CancelledException){}$counts['repeatTicks']=$ticks;
   // Positive sub-ms deadline must round up to a 1ms native wait.
   $before=TestClock::$ns;Async::sleep(.0000001);ensure(TestClock::$ns-$before===1000000,'millisecond rounding');
  });gc_collect_cycles();$s=Runtime::diagnostics();ensure(!$s['activeTasks']&&!$s['activeTimers']&&!$s['pendingFutures'],'virtual resources');
  echo json_encode(['status'=>'PASS','php'=>PHP_VERSION,'seed'=>$seed,'virtualNs'=>TestClock::$ns,'polls'=>TestClock::$polls,'timeouts'=>TestClock::$timeouts,'counts'=>$counts,'wallSeconds'=>(hrtime(true)-$start)/1e9,'scope'=>'virtual scheduler only; no kernel waiting'])."\n";
 }catch(Throwable $e){fwrite(STDERR,json_encode(['status'=>'FAIL','seed'=>$seed,'error'=>(string)$e,'counts'=>$counts])."\n");exit(1);}
}
