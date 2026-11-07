<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Runtime};
use Asyncara\IO\Files;
use Asyncara\SQLite\Connection;
use Asyncara\Exception\CancelledException;
$n=(int)($argv[1]??10000);$start=hrtime(true);$samples=[];$jobs=0;$path=tempnam(sys_get_temp_dir(),'asyncara-count-');
function verify(bool $condition,string $why):void {if(!$condition)throw new RuntimeException($why);}
try {
 // Independent connections; native handles, query serialization and close are
 // exercised every cycle. Checkpoints run after all operation owners drain.
 for($base=0;$base<$n;$base+=32){$batch=min(32,$n-$base);
  Async::run(function()use($base,$batch,$path,&$jobs):void{
   $tasks=[];
   for($i=0;$i<$batch;$i++)$tasks[]=Async::spawn(function()use($base,$i,$path,&$jobs):void{
    $file=Files::open($path)->await();$jobs++;
    try {verify($file->read(1)->await()==='','empty file');$jobs++;}
    finally {$file->close()->await();$jobs++;}
    $db=Connection::open(':memory:')->await();$jobs++;
    try {verify($db->query('select ? as n',[$base+$i])->await()[0]['n']===$base+$i,'query result');$jobs++;}
    finally {$db->close()->await();$jobs++;}
    $cancelled=Files::read($path);$cancelled->cancel();$jobs++;
    try{$cancelled->await();}catch(CancelledException){} // already-complete race allowed
   });
   Async::gather($tasks);
  });
  if(($base+$batch)%1024===0||$base+$batch===$n){gc_collect_cycles();$info=Runtime::information();$s=Runtime::diagnostics();
   verify(!$info->pendingOperations&&!$info->nativeMemoryBytes&&!$info->openFileHandles&&!$info->sqliteConnections,'native owner survived collection');
   verify(!$s['activeTasks']&&!$s['pendingFutures']&&!$s['activeTimers']&&!$s['ioWatchers'],'scheduler owner survived collection');
   preg_match('/VmRSS:\s+(\d+)/',file_get_contents('/proc/self/status'),$rss);
   $samples[]=['cycles'=>$base+$batch,'workerJobs'=>$jobs,'native'=>$info,'scheduler'=>$s,'fds'=>count(scandir('/proc/self/fd'))-2,'threads'=>count(scandir('/proc/self/task'))-2,'rssKiB'=>(int)$rss[1],'phpBytes'=>memory_get_usage()];
  }
 }
 $fds=array_column($samples,'fds');$threads=array_column($samples,'threads');verify(count(array_unique($fds))===1,'descriptor accumulation');verify(max($threads)<=5,'worker bound');
 echo json_encode(['status'=>'PASS','php'=>PHP_VERSION,'wallSeconds'=>(hrtime(true)-$start)/1e9,'cycles'=>$n,'workerJobs'=>$jobs,'samples'=>$samples,'rssIsLeakProof'=>false],JSON_THROW_ON_ERROR)."\n";
} catch(Throwable $e){fwrite(STDERR,json_encode(['status'=>'FAIL','error'=>(string)$e,'workerJobs'=>$jobs,'samples'=>$samples])."\n");exit(1);}
finally {unlink($path);}
