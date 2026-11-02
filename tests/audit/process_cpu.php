<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async};
use Asyncara\Pool\ProcessPool;
Async::run(function():void{
 $pool=new ProcessPool(2,__DIR__.'/cpu_jobs.php');
 try{
  Async::gather([$pool->submit('ready'),$pool->submit('ready')]);
  $results=Async::gather([$pool->submit('cpu'),$pool->submit('cpu')]);
  $wall=(max(array_column($results,'end'))-min(array_column($results,'start')))/1e9;
  $cpu=array_sum(array_column($results,'cpuSeconds'));$ratio=$cpu/$wall;
  echo json_encode(['workers'=>$results,'wallSeconds'=>$wall,'cpuSeconds'=>$cpu,'cpuToWallRatio'=>$ratio]),"\n";
  if(count(array_unique(array_column($results,'pid')))!==2||$ratio<=1.1)throw new RuntimeException('This run did not demonstrate simultaneous CPU consumption on multiple cores');
 }finally{$pool->close();}
});
echo "Independent multicore CPU execution: PASS\n";
