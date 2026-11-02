<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Asyncara\{Async,Runtime,Scheduler};
use Asyncara\HTTP\HttpClient;
use Asyncara\IO\Files;
use Asyncara\Exception\{CancelledException,OperationException,TimeoutException};
function demand(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
function fails(string $type, callable $body): void {
    try { $body(); } catch (Throwable $e) { if ($e instanceof $type) return; throw $e; }
    throw new RuntimeException('Expected '.$type);
}
$root=dirname(__DIR__);
$server=proc_open(['python3',__DIR__.'/fixtures/http_stream_server.py',"$root/build/test-certs/cert.pem","$root/build/test-certs/key.pem"],
    [0=>['pipe','r'],1=>['pipe','w'],2=>['file',"$root/build/http-stream-server.log",'a']],$pipes);
$ports=json_decode(fgets($pipes[1]),true,512,JSON_THROW_ON_ERROR);
$url='http://127.0.0.1:'.$ports['http'];$secure='https://localhost:'.$ports['https'];
$file=sys_get_temp_dir().'/asyncara-download-'.bin2hex(random_bytes(8));
$block=implode('',array_map(chr(...),range(0,255)));$block=str_repeat($block,256);
$ctx=hash_init('sha256');for($i=0;$i<512;$i++)hash_update($ctx,$block);$expected=hash_final($ctx);
$metrics=[];$stage='response';$weak=[];
try {
    Async::run(function () use ($url,$secure,$root,$file,$block,$expected,&$metrics,&$stage,&$weak): void {
        $client=new HttpClient(concurrency:2,timeout:10);
        $neverDownload=$client->download($url.'/large',$file);$neverDownload->cancel();
        fails(CancelledException::class,$neverDownload->await(...));
        demand(asyncara_info()['pendingOperations']===0,'unstarted download leaked a producer');
        $never=$client->getStream($url.'/large');$never->close();
        $weak[]=WeakReference::create($never);
        fails(CancelledException::class,$never->headers()->await(...));
        $early=$client->getStream($url.'/headers-first');
        demand($early->headers()->await()->status===200,'headers before gated body');
        $client->get($url.'/release-body')->await();
        demand(implode('',iterator_to_array($early))==='x','gated body');
        $early->close();
        $empty=$client->getStream($url.'/empty');demand($empty->headers()->await()->status===204,'empty response metadata');
        demand(iterator_to_array($empty)===[],'empty body');$empty->close();
        $compressed=$client->getStream($url.'/gzip');demand($compressed->headers()->await()->headers['content-encoding']===['gzip'],'encoded metadata');
        $prefix='';$compressed->consume(function($chunk)use(&$prefix){if($prefix==='')$prefix=substr($chunk,0,2);});demand($prefix==="\x1f\x8b",'stream unexpectedly decoded compressed body');
        $stream=$client->getStream($url.'/large');
        $weak[]=WeakReference::create($stream);
        $ticks=0;$peak=0;$maxBuffered=0;$paused=false;$stop=false;
        $sampler=Async::spawn(function () use (&$ticks,&$peak,&$maxBuffered,&$paused,&$stop): void {
            while (!$stop) {
                $info=asyncara_info();$peak=max($peak,$info['nativeMemoryBytes']);
                $maxBuffered=max($maxBuffered,$info['httpStreamingBufferedBytes']);
                $paused=$paused || $info['httpStreamingPaused']>0;$ticks++;Async::sleep(.001);
            }
        });
        // Intentionally do not consume until producer backpressure reaches curl.
        $until=Scheduler::now()+3;
        while (!asyncara_info()['httpStreamingPaused'] && Scheduler::now()<$until) Async::sleep(.001);
        demand(asyncara_info()['httpStreamingPaused']===1,'curl was never paused');
        $turns=Scheduler::current()->statistics()['turns'];Async::sleep(.05);
        demand(Scheduler::current()->statistics()['turns']-$turns<300,'paused transfer spins');
        $hash=hash_init('sha256');$bytes=0;
        try { foreach($stream as $chunk) {
            demand(strlen($chunk)<=65536,'chunk exceeded native bound');
            $bytes+=strlen($chunk);hash_update($hash,$chunk);Async::sleep(.0002);
        }} finally {$stream->close();$stop=true;$sampler->await();}
        demand($bytes===33554432 && hash_final($hash)===$expected,'large response corruption');
        demand($paused && $ticks>10 && $maxBuffered<=65536 && $peak<1048576,'backpressure, native bound or timer progress failed');
        $metrics=['bytes'=>$bytes,'timerTicks'=>$ticks,'nativePeakDuringTransfer'=>$peak,'maxBufferedBytes'=>$maxBuffered];
        $stage='download'; demand($client->download($url.'/large',$file)->await()===33554432,'download length');
        $hash=hash_init('sha256');foreach(Files::readStream($file)as$chunk)hash_update($hash,$chunk);
        demand(hash_final($hash)===$expected,'download corruption');
        $stage='file uploads'; foreach(['/upload','/slow-upload'] as $path) {
            $result=$client->upload($url.$path,$file)->await()->json();
            demand($result['bytes']===33554432 && $result['sha256']===$expected && $result['method']==='PUT','streamed upload '.$path);
        }
        $stage='multipart'; $result=$client->multipart($url.'/multipart',['title'=>'Asyncara','binary'=>"a\0b"],'data',$file,'sample.bin')->await()->json();
        demand(count($result['parts'])===3 && $result['parts'][2]['sha256']===$expected && $result['parts'][2]['filename']==='sample.bin','multipart upload');
        $stage='fixed size'; $result=$client->requestStream('POST',$url.'/upload',[$block,$block],131072)->await()->json();
        demand($result['bytes']===131072 && $result['method']==='POST','known-length upload');
        $stage='empty'; demand($client->requestStream('PUT',$url.'/upload',[],0)->await()->json()['bytes']===0,'empty upload');
        $stage='invalid size'; fails(ValueError::class,fn()=>$client->requestStream('PUT',$url.'/upload',['short'],99)->await());
        $stage='invalid size'; fails(ValueError::class,fn()=>$client->requestStream('PUT',$url.'/upload',[str_repeat('a',65537)])->await());
        fails(OperationException::class,fn()=>$client->upload($url.'/upload',$file.'-missing')->await());
        $stage='early rejection'; $slow=(static function()use($block){yield $block;Async::sleep(30);yield $block;})();
        $start=Scheduler::now();demand($client->requestStream('PUT',$url.'/reject',$slow)->await()->status===413,'early response');
        demand(Scheduler::now()-$start<2,'early rejection did not cancel upload producer');
        $stage='cancel upload'; fails(TimeoutException::class,fn()=>Async::timeout(.02,fn()=>Async::await($client->upload($url.'/slow-upload',$file))));
        $cancel=$client->upload($url.'/slow-upload',$file);Async::sleep(.01);$cancel->cancel();
        fails(CancelledException::class,$cancel->await(...));
        // Producer failure must win over the induced native cancellation.
        $stage='producer failure'; $bad=(static function(){yield 'x';throw new DomainException('upload producer');})();
        fails(DomainException::class,fn()=>$client->requestStream('PUT',$url.'/upload',$bad)->await());
        $stage='small download'; foreach(['/small'=>$block,'/redirect'=>$block,'/chunks'=>substr($block,0,1).substr($block,0,7).substr($block,0,61).substr($block,0,2048).$block] as $path=>$wanted) {
            $data='';$s=$client->getStream($url.$path,followRedirects:true);demand($s->headers()->await()->status===200,'redirect head');
            $s->consume(function($chunk)use(&$data){$data.=$chunk;});
            demand($data===$wanted,'redirect/chunked body '.$path);
        }
        fails(OperationException::class,fn()=>$client->getStream($url.'/broken')->consume(static fn($chunk)=>null));
        fails(OperationException::class,fn()=>(new HttpClient(timeout:.01))->getStream($url.'/stall')->consume(static fn($chunk)=>null));
        fails(OperationException::class,fn()=>$client->getStream($secure.'/small')->consume(static fn($chunk)=>null));
        $stage='tls'; $tls=new HttpClient(caFile:"$root/build/test-certs/cert.pem");$count=0;
        $tls->getStream($secure.'/small')->consume(function($chunk)use(&$count){$count+=strlen($chunk);});demand($count===65536,'verified TLS response');
        demand($tls->requestStream('PUT',$secure.'/upload',[$block],65536)->await()->json()['bytes']===65536,'TLS upload');
        fails(OperationException::class,fn()=>$tls->getStream(str_replace('localhost','127.0.0.1',$secure).'/small')->consume(static fn($chunk)=>null));
        $stage='early close'; for($i=0;$i<20;$i++) {
            $s=$client->getStream($url.'/large');
            try {foreach($s as $_)break;}finally{$s->close();}
            demand(asyncara_info()['pendingOperations']===0,'early-close retained transfer');
        }
        $s=$client->getStream($url.'/large');Async::sleep(.05);$s->close();
        demand(asyncara_info()['pendingOperations']===0,'paused close retained transfer');
        $s=(new HttpClient(timeout:.04))->getStream($url.'/large');Async::sleep(.08);
        fails(OperationException::class,fn()=>$s->consume(static fn($chunk)=>null));
        fails(TimeoutException::class,fn()=>Async::timeout(.01,fn()=>$client->getStream($url.'/stall')->consume(static fn($chunk)=>null)));
        fails(DomainException::class,fn()=>$client->getStream($url.'/large')->consume(static fn($chunk)=>throw new DomainException('consumer')));
        demand(asyncara_info()['pendingOperations']===0,'failure cleanup');
        // Both buffered and streaming requests use the same client admission.
        $stage='admission'; $one=new HttpClient(concurrency:1);$s=$one->getStream($url.'/large');Async::sleep(.03);
        $pending=$one->get($url.'/small');Async::sleep(.03);demand(!$pending->isCompleted(),'admission bypass');
        $s->close();demand($pending->await()->body===$block,'permit not released');
        demand(asyncara_info()['httpStreamingTransfers']===0,'stream handle retained');
        demand(asyncara_info()['httpStreamingUploads']===0 && asyncara_info()['openFileHandles']===0,'upload/file handle retained');
    });
    fails(DomainException::class,fn()=>Async::run(function()use($url){(new HttpClient())->getStream($url.'/large');Async::sleep(.03);throw new DomainException('root');}));
    $metrics['collectedPhpCycles']=gc_collect_cycles();
    foreach($weak as$reference)demand($reference->get()===null,'stream owner survived cycle collection');
    demand(asyncara_info()['nativeMemoryBytes']===0,'native memory retained after streams');
    // Extension path comes from the same tested environment, not a stale module.
    $selected=getenv('ASYNCARA_EXTENSION')?:'build/asyncara.so';
    $module=str_starts_with($selected,'/')?$selected:$root.'/'.$selected;
    foreach(['normal','fatal'] as $mode) {
        $child=proc_open([PHP_BINARY,'-n','-d','extension='.$module,__DIR__.'/http_stream_shutdown.php',$url,$mode],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['redirect',1]],$childPipes,null,array_merge(getenv(),['USE_ZEND_ALLOC'=>'1']));
        fclose($childPipes[0]);$output=stream_get_contents($childPipes[1]);
        fclose($childPipes[1]);$code=proc_close($child);
        demand($code===($mode==='fatal'?255:0),'paused shutdown failed: '.$output);
        if($mode==='fatal')demand(str_contains($output,'Allowed memory size'),'wrong fatal failure');
    }
    echo 'HTTP streaming, 32MiB download, bounds, pause, TLS and cleanup: PASS '.json_encode($metrics,JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) { fwrite(STDERR, 'Streaming stage: '.$stage."\n"); throw $e; } finally {
    fclose($pipes[0]);fclose($pipes[1]);proc_close($server);
    if(is_file($file))unlink($file);
}
