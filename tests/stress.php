<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Asyncara\{Async,Runtime,Scheduler};
use Asyncara\SQLite\Connection;
use Asyncara\IO\Files;
use Asyncara\Concurrency\Queue;
$rounds=(int)($argv[1]??100);
$path=tempnam(sys_get_temp_dir(),'asyncara-stress-');
$fdStart=count(glob('/proc/self/fd/*'));$samples=[];
try{
for($round=0;$round<$rounds;$round++){
 Async::run(function()use($path){
  $db=Connection::open(':memory:')->await();
  $db->query('create table t(v text)')->await();
  $q=new Queue(8);
  Async::taskGroup(function($g)use($q,$db,$path){
   $g->spawn(function()use($q){for($i=0;$i<100;$i++)$q->put($i);});
   $g->spawn(function()use($q,$db){for($i=0;$i<100;$i++){$n=$q->get();$db->query('insert into t values (?)',[(string)$n])->await();$q->taskDone();}});
   $g->spawn(function()use($path){for($i=0;$i<20;$i++){Files::write($path,str_repeat('x',4096))->await();if(strlen(Files::read($path)->await())!==4096)throw new RuntimeException('Bad file length');}});
  });
  $q->join();$q->close();$db->close()->await();
 });
 gc_collect_cycles();
 if($round%10===0)$samples[]=['round'=>$round,'memory'=>memory_get_usage(),'fds'=>count(glob('/proc/self/fd/*'))];
 if(Runtime::information()->pendingOperations!==0)throw new RuntimeException('Native work retained');
}
$fdEnd=count(glob('/proc/self/fd/*'));
if($fdEnd>$fdStart+2)throw new RuntimeException('Descriptor growth');
if(count($samples)>1&&end($samples)['memory']-$samples[0]['memory']>2097152)throw new RuntimeException('Memory growth exceeds 2 MiB tolerance');
echo json_encode(['rounds'=>$rounds,'fdStart'=>$fdStart,'fdEnd'=>$fdEnd,'samples'=>$samples],JSON_PRETTY_PRINT),"\n";
}finally{unlink($path);}
