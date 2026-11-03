<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Asyncara\{Async,IO\Files};
$path=$argv[1];$results=[];
for($sample=0;$sample<3;$sample++){
 $before=getrusage();$start=hrtime(true);$latencies=[];
 Async::run(function()use($path,&$latencies):void{
  for($i=0;$i<24;$i++){
   $begin=hrtime(true);$data=Files::read($path)->await();
   if(strlen($data)!==12582912||$data[0]!=="\0"||$data[strlen($data)-1]!=="\0")throw new RuntimeException('Incorrect result');
   unset($data);$latencies[]=(hrtime(true)-$begin)/1e6;
  }
 });
 $after=getrusage();sort($latencies);$results[]=['seconds'=>(hrtime(true)-$start)/1e9,'latencyMs'=>$latencies,'medianMs'=>($latencies[11]+$latencies[12])/2,'p95Ms'=>$latencies[22],'p99Ms'=>$latencies[23],'maxRssKiB'=>$after['ru_maxrss'],'cpuUserSeconds'=>$after['ru_utime.tv_sec']-$before['ru_utime.tv_sec']+($after['ru_utime.tv_usec']-$before['ru_utime.tv_usec'])/1e6,'native'=>asyncara_info()];
}
echo json_encode($results,JSON_PRETTY_PRINT),"\n";
