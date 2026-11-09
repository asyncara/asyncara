<?php
declare(strict_types=1);
require (getenv('ASYNCARA_SOURCE_ROOT') ?: dirname(__DIR__,2)).'/autoload.php';
[$script,$mode,$url,$count,$concurrency,$size]=$argv;
$count=(int)$count;$concurrency=(int)$concurrency;$size=(int)$size;
if ($count%$concurrency) throw new ValueError('Count must divide concurrency');
$body=str_repeat('x',$size);$latencies=[];$elapsed=0;$cpu=0;
function cpu():float{$r=getrusage();return $r['ru_utime.tv_sec']+$r['ru_utime.tv_usec']/1e6+$r['ru_stime.tv_sec']+$r['ru_stime.tv_usec']/1e6;}
function verifyResponse(int $status,string $received):void {global $body;if($status!==200||$received!==$body)throw new RuntimeException('Response mismatch');}
if ($mode==='asyncara_raw') {
 // Diagnostic control: native submission/collection only, no public Task/Fiber
 // lifecycle. Never present this as HttpClient application throughput.
 asyncara_http_limits($concurrency,$concurrency);
 foreach([10,$count/$concurrency]as$phase=>$perWorker){
  $pending=[];$times=[];$start=hrtime(true);$before=cpu();
  $submit=static function(int $done)use($url,&$pending):void{$s=hrtime(true);$id=asyncara_submit('http',[$url,null,['method'=>'GET','headers'=>[]],10000]);$pending[$id]=[$done,$s];};
  for($w=0;$w<$concurrency;$w++)$submit(0);
  while($pending){
   $batch=asyncara_poll([],[],-1);
   foreach($batch['results']as$item){
    [$done,$s]=$pending[$item['id']];unset($pending[$item['id']]);
    if($item['error']!==null)throw new RuntimeException($item['error']);
    verifyResponse($item['value']['status'],$item['value']['body']);$times[]=(hrtime(true)-$s)/1e6;
    if(++$done<$perWorker)$submit($done);
   }
  }
  if($phase){$elapsed=(hrtime(true)-$start)/1e9;$cpu=cpu()-$before;$latencies=$times;}
 }
} elseif ($mode==='asyncara') {
 if(function_exists('asyncara_http_limits')) Asyncara\Runtime::configure(['httpMaxConnections'=>$concurrency,'httpMaxHostConnections'=>$concurrency]);
 Asyncara\Async::run(function()use($url,$count,$concurrency,&$latencies,&$elapsed,&$cpu){
  $client=new Asyncara\HTTP\HttpClient(concurrency:$concurrency,timeout:10);
  foreach([10,$count/$concurrency]as$phase=>$perWorker){
   $times=[];$start=hrtime(true);$before=cpu();$tasks=[];
   for($w=0;$w<$concurrency;$w++)$tasks[]=Asyncara\Async::spawn(function()use($client,$url,$perWorker,&$times){for($i=0;$i<$perWorker;$i++){$s=hrtime(true);$r=$client->get($url)->await();verifyResponse($r->status,$r->body);$times[]=(hrtime(true)-$s)/1e6;}});
   Asyncara\Async::gather($tasks);
   if($phase){$elapsed=(hrtime(true)-$start)/1e9;$cpu=cpu()-$before;$latencies=$times;}
  }
 });
} elseif($mode==='curl_multi') {
 $multi=curl_multi_init();curl_multi_setopt($multi,CURLMOPT_MAX_TOTAL_CONNECTIONS,$concurrency);curl_multi_setopt($multi,CURLMOPT_MAX_HOST_CONNECTIONS,$concurrency);
 $handles=[];for($i=0;$i<$concurrency;$i++){$h=curl_init($url);curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT_MS=>10000,CURLOPT_NOSIGNAL=>true,CURLOPT_HTTP_VERSION=>CURL_HTTP_VERSION_1_1,CURLOPT_ACCEPT_ENCODING=>'']);$handles[]=$h;}
 foreach([10,$count/$concurrency]as$phase=>$perWorker){
  $done=[];$starts=[];$times=[];$start=hrtime(true);$before=cpu();
  foreach($handles as$h){$id=spl_object_id($h);$done[$id]=0;$starts[$id]=hrtime(true);curl_multi_add_handle($multi,$h);}
  do{
   if(curl_multi_exec($multi,$running)!==CURLM_OK)throw new RuntimeException('multi failed');
   $added=false;
   while($info=curl_multi_info_read($multi)){
    $h=$info['handle'];$id=spl_object_id($h);if($info['result']!==CURLE_OK)throw new RuntimeException(curl_error($h));verifyResponse(curl_getinfo($h,CURLINFO_RESPONSE_CODE),curl_multi_getcontent($h));$times[]=(hrtime(true)-$starts[$id])/1e6;
    curl_multi_remove_handle($multi,$h);if(++$done[$id]<$perWorker){$starts[$id]=hrtime(true);curl_multi_add_handle($multi,$h);$running++;$added=true;}
   }
   // Re-added handles need a perform before select, otherwise no socket exists yet.
   if($running&&!$added&&curl_multi_select($multi,.1)<0)throw new RuntimeException('select failed');
  }while($running);
  if($phase){$elapsed=(hrtime(true)-$start)/1e9;$cpu=cpu()-$before;$latencies=$times;}
 }
 unset($h,$handles,$multi);
} elseif($mode==='swoole') {
 Swoole\Coroutine\run(function()use($url,$count,$concurrency,&$latencies,&$elapsed,&$cpu){
  $u=parse_url($url);$clients=[];for($w=0;$w<$concurrency;$w++){$c=new Swoole\Coroutine\Http\Client($u['host'],$u['port']);$c->set(['timeout'=>10,'keep_alive'=>true]);$clients[]=$c;}
  foreach([10,$count/$concurrency]as$phase=>$perWorker){
   $done=new Swoole\Coroutine\Channel($concurrency);$times=[];$start=hrtime(true);$before=cpu();
   foreach($clients as$c)Swoole\Coroutine::create(function()use($c,$u,$perWorker,&$times,$done){try{for($i=0;$i<$perWorker;$i++){$s=hrtime(true);if(!$c->get($u['path'].'?'.($u['query']??'')))throw new RuntimeException('Swoole '.$c->errCode);verifyResponse($c->statusCode,$c->body);$times[]=(hrtime(true)-$s)/1e6;}$done->push(null);}catch(Throwable $e){$done->push($e);}});
   for($w=0;$w<$concurrency;$w++)if(($e=$done->pop())instanceof Throwable)throw$e;
   if($phase){$elapsed=(hrtime(true)-$start)/1e9;$cpu=cpu()-$before;$latencies=$times;}
  }
  foreach($clients as$c)$c->close();
 });
}else throw new ValueError('Unknown mode');
$processUsage=getrusage();
sort($latencies);if(count($latencies)!==$count)throw new RuntimeException('Lost responses '.count($latencies));
$percentile=static fn(float$p)=>$latencies[min($count-1,(int)ceil($p*$count)-1)];
echo json_encode(['mode'=>$mode,'php'=>PHP_VERSION,'jit'=>function_exists('opcache_get_status')?(opcache_get_status(false)['jit']??null):null,'curl'=>function_exists('curl_version')?curl_version()['version']:null,'swoole'=>defined('SWOOLE_VERSION')?SWOOLE_VERSION:null,'operations'=>$count,'concurrency'=>$concurrency,'bytesPerBody'=>$size,'warmupRequests'=>10*$concurrency,'wallSeconds'=>$elapsed,'cpuSeconds'=>$cpu,'requestsPerSecond'=>$count/$elapsed,'medianMs'=>$percentile(.5),'p95Ms'=>$percentile(.95),'p99Ms'=>$percentile(.99),'rssPeakKiB'=>getrusage()['ru_maxrss'],'phpPeakBytes'=>memory_get_peak_usage(true),'processUserCpuSeconds'=>$processUsage['ru_utime.tv_sec']+$processUsage['ru_utime.tv_usec']/1e6,'processSystemCpuSeconds'=>$processUsage['ru_stime.tv_sec']+$processUsage['ru_stime.tv_usec']/1e6,'scheduler'=>$mode==='asyncara'?Asyncara\Runtime::diagnostics():null,'native'=>function_exists('asyncara_info')?asyncara_info():null],JSON_THROW_ON_ERROR),"\n";
