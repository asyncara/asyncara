<?php
declare(strict_types=1);
require (getenv('ASYNCARA_AUDIT_SOURCE') ?: dirname(__DIR__,2)).'/autoload.php';
use Asyncara\{Async,Future,Runtime,Scheduler};
use Asyncara\Concurrency\Event;
use Asyncara\Pool\{ProcessPool,ConnectionPool};
use Asyncara\SQLite\Connection;
use Asyncara\IO\Files;
use Asyncara\Exception\CancelledException;
function auditCheck(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);}
switch($argv[1]) {
case 'root_failure_timer':
 try {Async::run(function(){Async::callLater(60,fn()=>null);throw new DomainException('root');});}catch(DomainException){}
 auditCheck(Async::run(fn()=>42)===42,'runtime did not restart');break;
case 'foreign_fiber':
 Async::run(function(){
  $foreign=new Fiber(fn()=>Async::sleep(.01));
  try{$foreign->start();throw new RuntimeException('Foreign Fiber registered a wait against the current Task');}catch(LogicException){}
  auditCheck(Async::spawn(fn()=>42)->await()===42,'foreign Fiber corrupted the ready queue');
 });break;
case 'sqlite_parallel_transaction':
 Async::run(function(){ $db=Connection::open(':memory:')->await();try{$rows=$db->transaction(fn($db)=>Async::gather([$db->query('SELECT 1 AS n'),$db->query('SELECT 2 AS n')]))->await();auditCheck($rows[0][0]['n']===1&&$rows[1][0]['n']===2,'query order');}finally{$db->close()->await();}});break;
case 'root_failure_shielded_wait':
 foreach(['sleep','wait']as $kind){
  $cleaned=false;
  try{Async::run(function()use($kind,&$cleaned){
   $started=new Event();
   Async::spawn(function()use($kind,$started,&$cleaned){Async::shield(function()use($kind,$started,&$cleaned){$started->set();if($kind==='sleep')Async::sleep(.02);else Async::wait([new Future()],.02);$cleaned=true;});});
   $started->wait();Async::callLater(60,fn()=>null);throw new DomainException('root');
  });}catch(DomainException){}
  auditCheck($cleaned,'shielded cleanup wait was discarded');
 }break;
case 'cleanup_delayed_callback':
 $cleaned=false;
 try{Async::run(function()use(&$cleaned){
  $started=new Event();
  Async::spawn(function()use($started,&$cleaned){try{$started->set();Async::sleep(60);}finally{$cleaned=true;Async::callLater(60,fn()=>null);}});
  $started->wait();throw new DomainException('root');
 });}catch(DomainException){}
 auditCheck($cleaned,'child cleanup did not execute');break;
case 'pool_pending_close':
 Async::run(function(){ $pool=new ProcessPool(1,dirname(__DIR__).'/fixtures/jobs.php');$job=$pool->submit('delay',[2000000]);Async::sleep(.02);$pool->close();try{$job->await();}catch(CancelledException){} });break;
case 'pool_acquire_cancel':
 $opened=$closed=0;
 Async::run(function()use(&$opened,&$closed){$started=new Event();$finish=new Event();$pool=new ConnectionPool(function()use(&$opened,$started,$finish){return Async::spawn(function()use(&$opened,$started,$finish){$started->set();$finish->wait();$opened++;return new stdClass();});},function($c)use(&$closed){$closed++;},1);$task=Async::spawn(fn()=>$pool->withConnection(fn($c)=>null));$started->wait();$task->cancel();$finish->set();try{$task->await();}catch(CancelledException){}$pool->close();});
 auditCheck($opened===$closed,'cancelled acquisition leaked resource');break;
case 'pool_close_failure':
 $closed=0;$opened=0;
 Async::run(function()use(&$closed,&$opened){
  $both=new Event();$pool=new ConnectionPool(function()use(&$opened){$opened++;return new stdClass();},function($c)use(&$closed){$closed++;if($closed===1)throw new DomainException('close');},2);
  $one=Async::spawn(fn()=>$pool->withConnection(fn($c)=>$both->wait()));
  $two=Async::spawn(fn()=>$pool->withConnection(fn($c)=>$both->set()));
  Async::gather([$one,$two]);
  try{$pool->close();throw new LogicException('Missing closer failure');}catch(DomainException){}
 });auditCheck($opened===2&&$closed===2,'closer failure skipped remaining resources');break;
case 'pool_close_queued_borrower':
 $closed=0;
 Async::run(function()use(&$closed){
  $pool=new ConnectionPool(fn()=>new stdClass(),function($c)use(&$closed){$closed++;},1);
  $started=new Event();$finish=new Event();
  $a=Async::spawn(fn()=>$pool->withConnection(function($c)use($started,$finish){$started->set();$finish->wait();}));
  $started->wait();$b=Async::spawn(fn()=>$pool->withConnection(fn($c)=>null));Async::sleep(0);
  $finish->set();Async::sleep(0);
  try{$pool->close();throw new RuntimeException('Pool closed while a borrower held a queued lease');}catch(LogicException){}
  Async::gather([$a,$b]);$pool->close();
 });auditCheck($closed===1,'queued borrower leaked connection');break;
case 'waiter_ready_cancel':
 Async::run(function(){ $s=new Asyncara\Concurrency\Semaphore(1);$s->acquire();$got=false;$t=Async::spawn(function()use($s,&$got){$s->acquire();$got=true;$s->release();});Async::sleep(0);$s->release();$t->cancel();try{$t->await();}catch(CancelledException){}$s->acquire();$s->release();});break;
case 'idle':
 $before=getrusage();$t=hrtime(true);Async::run(fn()=>Async::sleep(.25));$after=getrusage();
 $cpu=($after['ru_utime.tv_sec']-$before['ru_utime.tv_sec'])+($after['ru_utime.tv_usec']-$before['ru_utime.tv_usec'])/1e6+($after['ru_stime.tv_sec']-$before['ru_stime.tv_sec'])+($after['ru_stime.tv_usec']-$before['ru_stime.tv_usec'])/1e6;
 echo json_encode(['wallSeconds'=>(hrtime(true)-$t)/1e9,'cpuSeconds'=>$cpu,'diagnostics'=>Runtime::diagnostics()]),"\n";auditCheck($cpu<.075,'idle wait consumed CPU');break;
case 'tasks':
 $n=(int)($argv[2]??1000);$counts=array_fill(0,$n,0);$start=hrtime(true);
 Async::run(function()use($n,&$counts){$jobs=[];for($i=0;$i<$n;$i++)$jobs[]=Async::spawn(function()use($i,&$counts){for($round=0;$round<3;$round++){$counts[$i]++;Async::sleep(0);}});Async::gather($jobs);});
 auditCheck(min($counts)===3&&max($counts)===3,'task starvation');echo json_encode(['tasks'=>$n,'seconds'=>(hrtime(true)-$start)/1e9,'diagnostics'=>Runtime::diagnostics()]),"\n";gc_collect_cycles();break;
case 'latency':
 $samples=[];$done=false;
 Async::run(function()use(&$samples,&$done){
  $workers=[];for($i=0;$i<64;$i++)$workers[]=Async::spawn(function()use(&$done){while(!$done)Async::sleep(0);});
  try{for($i=0;$i<1000;$i++){$due=hrtime(true)+1000000;Async::sleep(.001);$samples[]=max(0,(hrtime(true)-$due)/1e6);}}
  finally{$done=true;Async::gather($workers);}
 });sort($samples);echo json_encode(['samples'=>count($samples),'yieldingTasks'=>64,'timerLagMs'=>['median'=>$samples[499],'p95'=>$samples[949],'p99'=>$samples[989],'max'=>max($samples)],'diagnostics'=>Runtime::diagnostics()]),"\n";break;
default:throw new LogicException('unknown case');
}
echo $argv[1],": PASS\n";
