<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,CancellationSource,Future,Runtime};
use Asyncara\HTTP\HttpClient;
use Asyncara\Exception\{CancelledException,OperationException};
$config=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);
function verify(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function reject(callable $f,string $type=OperationException::class,?string $contains=null):void{$error=null;try{$f();}catch(Throwable $e){$error=$e;}verify($error instanceof $type,'Expected '.$type);if($contains!==null)verify(str_contains($error->getMessage(),$contains),'Unexpected failure: '.$error->getMessage());}
$checks=[];$ticks=0;$streamIds=[];
Runtime::configure(['httpMaxHostConnections'=>1,'httpMaxConnections'=>4]);
Async::run(function()use($config,&$checks,&$ticks,&$streamIds):void{
    $url=$config['url'];$client=new HttpClient(concurrency:8,timeout:5,caFile:$config['ca'],proxy:$config['proxy']??null,proxyCaFile:$config['proxyCA']??null,proxyCredentials:$config['proxyCredentials']??null);
    $warm=$client->get($url.'/ok')->await();verify($warm->headers['x-test-protocol']===['h2'],'Actual HTTP/2 peer');
    verify($warm->headers['x-repeat']===['one','two'],'Repeated HTTP/2 headers');$connection=$warm->headers['x-test-connection'];
    $held=[];for($n=0;$n<4;$n++)$held[]=$client->get($url.'/hold/shared');
    Async::timeout(2,function()use($client,$url):void{while($client->get($url.'/state/shared')->await()->json()['held']!==4)Async::sleep(0);});
    for($n=0;$n<2;$n++){Async::sleep(.005);foreach($held as$f)verify(!$f->isCompleted(),'Timer ran after held I/O');$ticks++;}
    $cancel=new CancellationSource();$waiter=Async::spawn(fn()=>$held[0]->await($cancel->token));Async::sleep(0);$cancel->cancel();reject($waiter->await(...),CancelledException::class);
    verify(!$held[0]->isCompleted(),'Waiter destroyed shared HTTP/2 stream');
    $held[1]->cancel();reject($held[1]->await(...),CancelledException::class);
    $client->get($url.'/release/shared')->await();
    foreach([0,2,3]as$n){$r=$held[$n]->await();verify($r->body==='released'&&$r->headers['x-test-connection']===$connection,'Multiplexed sibling/connection lost');$streamIds[]=$r->headers['x-test-stream'][0];}
    verify(count(array_unique($streamIds))===3,'Streams not independent');$checks[]='multiplex_two_ticks_shared_waiter_owner_cancel';
    $data=str_repeat("ab\0\xff",16384);$reply=$client->request('PUT',$url.'/echo',$data)->await()->json();verify($reply['bytes']===strlen($data)&&$reply['sha256']===hash('sha256',$data)&&$reply['method']==='PUT','HTTP/2 upload integrity');
    verify($client->request('HEAD',$url.'/head')->await()->body==='','HEAD framing');
    verify($client->get($url.'/gzip')->await()->body===str_repeat(implode('',array_map(chr(...),range(0,255))),256),'HTTP/2 decompression');
    verify($client->request('GET',$url.'/redirect',followRedirects:true)->await()->body==='ok','HTTP/2 redirect');$checks[]='buffered_upload_head_gzip_redirect';
    foreach(['reset','short','header-limit','oversize']as$path){
        $sibling=$client->get($url.'/hold/'.$path);
        Async::timeout(2,function()use($client,$url,$path):void{while($client->get($url.'/state/'.$path)->await()->json()['held']!==1)Async::sleep(0);});
        reject(fn()=>$client->get($url.'/'.$path)->await(),contains:match($path){'header-limit'=>'HTTP header limit exceeded','oversize'=>'HTTP body limit exceeded',default=>null});verify(!$sibling->isCompleted(),'Failure affected independent held stream');
        $client->get($url.'/release/'.$path)->await();verify($sibling->await()->body==='released','Sibling lost after '.$path);$checks[]=$path.'_rejected_sibling_survives';
    }
    $timed=new HttpClient(concurrency:2,timeout:.2,caFile:$config['ca'],proxy:$config['proxy']??null,proxyCaFile:$config['proxyCA']??null,proxyCredentials:$config['proxyCredentials']??null);
    $deadline=$timed->get($url.'/hold/deadline');
    Async::timeout(2,function()use($client,$url):void{while($client->get($url.'/state/deadline')->await()->json()['held']!==1)Async::sleep(0);});
    reject($deadline->await(...),contains:'Timeout');$checks[]='pending_stream_deadline';
    // Streaming deliberately forces HTTP/1.1; the h2-only peer must reject it.
    $stream=$client->getStream($url.'/ok');reject(fn()=>$stream->consume(static fn($chunk)=>null)); // consume already closes and joins, propagating the producer failure.
    $checks[]='streaming_remains_http11';
    verify($client->get($url.'/ok')->await()->body==='ok','Recovery after rejection');
});
gc_collect_cycles();$native=asyncara_info();$diag=Runtime::diagnostics();
verify(!$native['pendingOperations']&&!$native['nativeMemoryBytes']&&!Future::pendingCount(),'HTTP/2 native cleanup');
foreach(['activeTasks','pendingFutures','ioWatchers','activeTimers']as$key)verify($diag[$key]===0,'HTTP/2 scheduler cleanup '.$key);
echo json_encode(['status'=>'PASS','ticksWhileHeld'=>$ticks,'streamIds'=>$streamIds,'checks'=>$checks,'native'=>$native]),"\n";
