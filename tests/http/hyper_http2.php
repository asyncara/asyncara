<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,CancellationSource,Future,Runtime,Scheduler};
use Asyncara\HTTP\HttpClient;
use Asyncara\Exception\{CancelledException,OperationException};
$config=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);
function verify(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function reject(callable $f,string $type=OperationException::class):void{$error=null;try{$f();}catch(Throwable $e){$error=$e;}verify($error instanceof $type,'Expected '.$type.', got '.($error?->getMessage()??'success'));}
$checks=[];$ticks=0;$streamIds=[];
Runtime::configure(['httpMaxHostConnections'=>1,'httpMaxConnections'=>4]);
$work=function()use($config,&$checks,&$ticks,&$streamIds):void{
    verify(asyncara_info()['httpBackend']==='hyper-prototype','Wrong native transport');
    $url=$config['url'];$client=new HttpClient(concurrency:16,timeout:5,caFile:$config['ca']);
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
    verify(count(array_unique($streamIds))===3,'Streams not independent');$checks[]='multiplex_two_ticks_shared_waiter_owner_cancel';echo end($checks),"\n";
    $data=str_repeat("ab\0\xff",16384);$reply=$client->request('PUT',$url.'/echo',$data)->await()->json();verify($reply['bytes']===strlen($data)&&$reply['sha256']===hash('sha256',$data)&&$reply['method']==='PUT','HTTP/2 upload integrity');
    verify($client->request('HEAD',$url.'/head')->await()->body==='','HEAD framing');
    verify($client->get($url.'/gzip')->await()->body===str_repeat(implode('',array_map(chr(...),range(0,255))),256),'HTTP/2 decompression');
    verify($client->request('GET',$url.'/redirect',followRedirects:true)->await()->body==='ok','HTTP/2 redirect');$checks[]='buffered_upload_head_gzip_redirect';echo end($checks),"\n";
    $trailers=$client->get($url.'/trailers')->await();verify($trailers->body==='ok'&&$trailers->headers['x-trailer']===['done'],'HTTP/2 trailers');
    verify($client->get($url.'/informational')->await()->body==='ok','Informational response');
    foreach(['reset','short','header-limit','oversize']as$path){
        $sibling=$client->get($url.'/hold/'.$path);
        Async::timeout(2,function()use($client,$url,$path):void{while($client->get($url.'/state/'.$path)->await()->json()['held']!==1)Async::sleep(0);});
        reject(fn()=>$client->get($url.'/'.$path)->await());verify(!$sibling->isCompleted(),'Failure affected independent held stream');
        $client->get($url.'/release/'.$path)->await();verify($sibling->await()->body==='released','Sibling lost after '.$path);$checks[]=$path.'_rejected_sibling_survives';echo end($checks),"\n";
    }
    $timed=new HttpClient(concurrency:2,timeout:.2,caFile:$config['ca']);
    $deadline=$timed->get($url.'/hold/deadline');
    Async::timeout(2,function()use($client,$url):void{while($client->get($url.'/state/deadline')->await()->json()['held']!==1)Async::sleep(0);});
    reject($deadline->await(...));$checks[]='pending_stream_deadline';echo end($checks),"\n";
    $stream=$client->getStream($url.'/large');verify($stream->headers()->await()->headers['x-test-protocol']===['h2'],'Stream actually HTTP/2');
    Async::sleep(.01);$peak=asyncara_info()['httpStreamingBufferedBytes'];verify($peak<=131072,'Response stream buffer exceeded bound');
    verify($client->get($url.'/ok')->await()->headers['x-test-connection']===$connection,'Paused stream blocks sibling');
    $hash=hash_init('sha256');$size=0;foreach($stream as$chunk){$size+=strlen($chunk);hash_update($hash,$chunk);}
    verify($size===2097152&&hash_final($hash)===hash('sha256',str_repeat('x',2097152)),'Stream integrity');
    $stream=$client->getStream($url.'/large');$stream->headers()->await();$stream->close();
    verify($client->get($url.'/ok')->await()->headers['x-test-connection']===$connection,'Early stream close destroyed shared transport');$checks[]='response_stream_backpressure_close';echo end($checks),"\n";
    foreach([null,262144]as$length){
        $chunks=(static function()use($data){for($i=0;$i<4;$i++){yield$data;Async::sleep(0);}})();
        $reply=$client->requestStream('PUT',$url.'/echo',$chunks,$length)->await()->json();
        verify($reply['bytes']===262144&&$reply['sha256']===hash('sha256',str_repeat($data,4)),'Streaming upload');
    }
    $producerStarted=false;$producerClosed=false;$chunks=(static function()use($data,&$producerStarted,&$producerClosed){$producerStarted=true;try{yield$data;Async::sleep(30);yield$data;}finally{$producerClosed=true;}})();
    // The peer waits for DATA before replying, proving that this generator
    // acquired its lifetime. An unopened generator has no finally to execute.
    // The separate zero-window test covers a response before producer start.
    $began=Scheduler::now();$result=$client->requestStream('PUT',$url.'/early-started',$chunks)->await();
    if($result->status!==413||Scheduler::now()-$began>=2)fwrite(STDERR,json_encode(['earlyStatus'=>$result->status,'elapsed'=>Scheduler::now()-$began,'started'=>$producerStarted,'closed'=>$producerClosed])."\n");
    verify($result->status===413&&Scheduler::now()-$began<2,'Early response retained upload until timeout');unset($chunks);
    if(!$producerClosed)fwrite(STDERR,json_encode(['earlyStatus'=>$result->status,'elapsed'=>Scheduler::now()-$began,'started'=>$producerStarted,'closed'=>$producerClosed])."\n");
    verify($producerStarted&&$producerClosed,'Started upload producer retained after early response');
    for($i=0;$i<16;$i++)verify($client->get($url.'/ok')->await()->body==='ok','Stale body callback after collection');
    $checks[]='upload_known_unknown_early_response_callback_revocation';echo end($checks),"\n";
    $broken=(static function(){yield'x';throw new DomainException('producer');})();
    reject(fn()=>$client->requestStream('PUT',$url.'/echo',$broken)->await(),DomainException::class);unset($broken);
    foreach(['reset','short']as$path){$broken=$client->getStream($url.'/'.$path);reject(fn()=>$broken->consume(static fn($chunk)=>null));}
    verify($client->get($url.'/ok')->await()->headers['x-test-connection']===$connection,'Producer/response failure destroyed siblings');
    verify($client->get($url.'/goaway')->await()->body==='ok','GOAWAY completion');
    verify($client->get($url.'/ok')->await()->headers['x-test-connection']!==$connection,'GOAWAY transport reused');
    $pending=$client->get($url.'/hold/disconnect');
    Async::timeout(2,function()use($client,$url):void{while($client->get($url.'/state/disconnect')->await()->json()['held']!==1)Async::sleep(0);});
    reject(fn()=>$client->get($url.'/disconnect')->await());reject($pending->await(...));
    verify($client->get($url.'/ok')->await()->body==='ok','Abrupt connection recovery');$checks[]='trailers_informational_producer_failure_goaway_disconnect';echo end($checks),"\n";
};
if(in_array('--server',$argv,true)){
    $app=new Asyncara\App();
    $app->get('/check',function()use($work,&$ticks,&$checks){try{$work();}catch(Throwable$error){fwrite(STDERR,(string)$error."\n");throw$error;}return Asyncara\Server\Response::json(['ticks'=>$ticks,'checks'=>$checks]);});
    $app->get('/stop',function()use($app){$app->stop();return Asyncara\Server\Response::text('stopped');});
    $app->listen('127.0.0.1:0',['timeoutMs'=>10000],static function($address){echo "READY $address\n";flush();});
}else Async::run($work);
gc_collect_cycles();$native=asyncara_info();$diag=Runtime::diagnostics();
verify(!$native['pendingOperations']&&!$native['nativeMemoryBytes']&&!Future::pendingCount(),'HTTP/2 native cleanup');
verify($native['hyperSubmitted']===$native['hyperCompleted'],'Missing native completions');
foreach(['activeTasks','ioWatchers','activeTimers']as$key)verify($diag[$key]===0,'HTTP/2 scheduler cleanup '.$key);
echo json_encode(['status'=>'PASS','ticksWhileHeld'=>$ticks,'streamIds'=>$streamIds,'checks'=>$checks,'native'=>$native]),"\n";
