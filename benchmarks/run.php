<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Asyncara\{Async,Runtime,Scheduler};
use Asyncara\HTTP\HttpClient;
use Asyncara\IO\Files;
use Asyncara\SQLite\Connection;
use Asyncara\Pool\ProcessPool;
use Asyncara\Concurrency\Queue;
$mode=$argv[1]??'tasks';$url=$argv[2]??'';$samples=[];
function percentile(array $a,float $p):?float{sort($a);return $a[(int)floor((count($a)-1)*$p)]??null;}
for($sample=0;$sample<3;$sample++){
 $latency=[];$operations=0;$start=hrtime(true);$cpu=getrusage();
 if($mode==='sequential-http'){
  for($i=0;$i<24;$i++){$t=hrtime(true);$c=curl_init($url);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10]);$body=curl_exec($c);if($body!=='hello asyncara')throw new RuntimeException('HTTP baseline failed');$latency[]=(hrtime(true)-$t)/1e6;}$operations=24;
 }elseif($mode==='async-http'){
  Async::run(function()use($url,&$latency){$client=new HttpClient(4);Async::taskGroup(function($g)use($client,$url,&$latency){for($w=0;$w<4;$w++)$g->spawn(function()use($client,$url,&$latency){for($i=0;$i<6;$i++){$t=hrtime(true);$body=$client->get($url,headers:['Connection: close'])->await()->body;if($body!=='hello asyncara')throw new RuntimeException('HTTP async failed');$latency[]=(hrtime(true)-$t)/1e6;}});});});$operations=24;
 }elseif($mode==='tasks'||$mode==='timers'){
  Async::run(function()use($mode,&$latency){$tasks=[];for($i=0;$i<1000;$i++)$tasks[]=Async::spawn(function()use($mode,&$latency){$t=hrtime(true);Async::sleep($mode==='timers'?.005:0);$latency[]=(hrtime(true)-$t)/1e6;});Async::gather($tasks);});$operations=1000;
 }elseif($mode==='amp-timers'){
  $tasks=[];for($i=0;$i<1000;$i++)$tasks[]=Amp\async(function()use(&$latency){$t=hrtime(true);Amp\delay(.005);$latency[]=(hrtime(true)-$t)/1e6;});Amp\Future\await($tasks);$operations=1000;
 }elseif($mode==='files'||$mode==='sequential-files'){
  $path=tempnam(sys_get_temp_dir(),'asyncara-bench-');file_put_contents($path,str_repeat('x',16384));
  try{
   if($mode==='files')Async::run(function()use($path,&$latency){Async::taskGroup(function($g)use($path,&$latency){for($w=0;$w<4;$w++)$g->spawn(function()use($path,&$latency){for($i=0;$i<50;$i++){$t=hrtime(true);if(strlen(Files::read($path)->await())!==16384)throw new RuntimeException('Bad file');$latency[]=(hrtime(true)-$t)/1e6;}});});});
   else for($i=0;$i<200;$i++){$t=hrtime(true);if(strlen(file_get_contents($path))!==16384)throw new RuntimeException('Bad file');$latency[]=(hrtime(true)-$t)/1e6;}
  }finally{unlink($path);}$operations=200;
 }elseif($mode==='sqlite'){
  Async::run(function()use(&$latency){$db=Connection::open(':memory:')->await();try{for($i=0;$i<200;$i++){$t=hrtime(true);$r=$db->query('select ? as value',[$i])->await();if($r[0]['value']!==$i)throw new RuntimeException('Bad SQL');$latency[]=(hrtime(true)-$t)/1e6;}}finally{$db->close()->await();}});$operations=200;
 }elseif($mode==='queue'){
  Async::run(function(){$q=new Queue(32);Async::taskGroup(function($g)use($q){$g->spawn(function()use($q){for($i=0;$i<10000;$i++)$q->put($i);});$g->spawn(function()use($q){for($i=0;$i<10000;$i++){if($q->get()!==$i)throw new RuntimeException('Queue order');$q->taskDone();}});});$q->join();});$operations=10000;
 }elseif($mode==='cpu'||$mode==='sequential-cpu'){
  if($mode==='cpu')Async::run(function()use(&$latency){$pool=new ProcessPool(4,dirname(__DIR__).'/tests/fixtures/jobs.php');try{$tasks=[];for($i=0;$i<4;$i++)$tasks[]=Async::spawn(function()use($pool,&$latency){$t=hrtime(true);$result=$pool->submit('cpu',[500000])->await();$latency[]=(hrtime(true)-$t)/1e6;return$result;});$r=Async::gather($tasks);if(count(array_unique(array_column($r,'pid')))!==4)throw new RuntimeException('Missing parallel workers');}finally{$pool->close();}});
  else{$registry=require dirname(__DIR__).'/tests/fixtures/jobs.php';for($i=0;$i<4;$i++){$t=hrtime(true);$registry['cpu'](500000);$latency[]=(hrtime(true)-$t)/1e6;}}$operations=4;
 }else throw new InvalidArgumentException('Unknown benchmark');
 $seconds=(hrtime(true)-$start)/1e9;$endCpu=getrusage();
 $cpuSeconds=($endCpu['ru_utime.tv_sec']-$cpu['ru_utime.tv_sec'])+($endCpu['ru_utime.tv_usec']-$cpu['ru_utime.tv_usec'])/1e6+($endCpu['ru_stime.tv_sec']-$cpu['ru_stime.tv_sec'])+($endCpu['ru_stime.tv_usec']-$cpu['ru_stime.tv_usec'])/1e6;
 $samples[]=['seconds'=>$seconds,'operations'=>$operations,'operationsPerSecond'=>$operations/$seconds,'medianMs'=>percentile($latency,.5),'p95Ms'=>percentile($latency,.95),'p99Ms'=>percentile($latency,.99),'parentCpuSeconds'=>$cpuSeconds,'parentMaxRssKiB'=>$endCpu['ru_maxrss'],'voluntarySwitches'=>$endCpu['ru_nvcsw']-$cpu['ru_nvcsw'],'involuntarySwitches'=>$endCpu['ru_nivcsw']-$cpu['ru_nivcsw'],'diagnostics'=>str_starts_with($mode,'sequential')||$mode==='amp-timers'?null:Runtime::diagnostics()];
 gc_collect_cycles();
}
echo json_encode(['mode'=>$mode,'php'=>PHP_VERSION,'samples'=>$samples],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";
