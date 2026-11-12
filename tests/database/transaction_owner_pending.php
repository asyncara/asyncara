<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/autoload.php';
use Asyncara\{Async,Runtime,Future};
use Asyncara\Concurrency\Event;
use Asyncara\Exception\CancelledException;
function demand(bool $v,string $m):void { if(!$v)throw new RuntimeException($m); }
$pg=(bool)getenv('ASYNCARA_PG_MTLS_FIXTURE');$f=json_decode(file_get_contents(getenv($pg?'ASYNCARA_PG_MTLS_FIXTURE':'ASYNCARA_MY_MTLS_FIXTURE')),true,flags:JSON_THROW_ON_ERROR);$c=$pg?Asyncara\PostgreSQL\Connection::class:Asyncara\MySQL\Connection::class;
$ticks=0;
Async::run(function()use($pg,$f,$c,&$ticks):void {
 $blocker=$c::connect($f['options'],timeout:5)->await();$db=$c::connect($f['options'],timeout:5)->await();$ready=new Event();$pending=null;
 $lock=$pg?'SELECT pg_advisory_lock(192721)':"SELECT GET_LOCK('transaction_owner_pending',5)";
 $unlock=$pg?'SELECT pg_advisory_unlock(192721)':"SELECT RELEASE_LOCK('transaction_owner_pending')";
 try {
  $blocker->query($lock)->await();
  $owner=Async::spawn(function()use($db,$ready,$lock,&$pending):void{$db->beginTransaction()->await();$pending=$db->query($lock);$ready->set();$pending->await();});
  $ready->wait();for($i=0;$i<2;$i++){Async::sleep(.002);demand(!$pending->isCompleted(),'Query not pending under independent lock');$ticks++;}
  $owner->cancel();try{$owner->await();throw new RuntimeException('Owner cancel ignored');}catch(CancelledException){}
  demand(!$db->inTransaction(),'Owner terminal kept transaction');
  $error=null;try{$pending->await();}catch(Throwable $e){$error=$e;}demand($error!==null,'Pending query survived owner termination');
  $blocker->query($unlock)->await();
 }finally{$db->close();$blocker->close();}
});
gc_collect_cycles();$d=Runtime::diagnostics();foreach(['activeTasks','activeTimers','ioWatchers','pendingNative']as$key)demand($d[$key]===0,'Leaked '.$key);
echo json_encode(['status'=>'PASS','ticks'=>$ticks,'runtime'=>$d]),"\n";
