<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Runtime,Scheduler};
use Asyncara\HTTP\HttpClient;
use Asyncara\IO\Files;
use Asyncara\Exception\{CancelledException,OperationException,TimeoutException};
function verify(bool $v,string $message):void {if(!$v)throw new RuntimeException($message);}
function rejects(callable $f,string $class):void {try{$f();}catch(Throwable $e){if($e instanceof $class)return;throw $e;}throw new RuntimeException('Expected '.$class);}
function event($pipe,string $name):void {
    $line='';while(!str_ends_with($line,"\n")){Scheduler::current()->readable($pipe);$s=fgets($pipe);if($s!==false)$line.=$s;verify(!feof($pipe),'Peer stopped');}
    verify(json_decode($line,true,512,JSON_THROW_ON_ERROR)['pending']===$name,'Peer gate identity');
}
[$script,$certs,$out]=$argv;
verify(asyncara_info()['httpBackend']==='hyper-prototype','Hyper identity');
$peer=proc_open(['python3',__DIR__.'/multipart_peer.py',$certs],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',$out.'/peer.log','x']],$pipes);
$metrics=[];
if(getenv('ASYNCARA_MULTIPART_DIAGNOSTIC')==='1') Asyncara\Trace::enable(4096);
try {
    $ports=json_decode(fgets($pipes[1]),true,512,JSON_THROW_ON_ERROR);stream_set_blocking($pipes[1],false);
    Async::run(function()use($ports,$certs,$out,$pipes,&$metrics):void {
        Async::timeout(30,function()use($ports,$certs,$out,$pipes,&$metrics):void {
            $block=str_repeat("a\0b\r\nx",10922).'xyza';verify(strlen($block)===65536,'Chunk');
            $file=$out.'/payload.bin';$empty=$out.'/empty.bin';$hash=hash_init('sha256');
            for($i=0;$i<512;$i++)hash_update($hash,$block);$digest=hash_final($hash);
            Files::writeStream($file,(static function()use($block){for($i=0;$i<512;$i++)yield $block;})())->await();Files::write($empty,'')->await();
            foreach($ports as$scheme=>$port) {
                $url=$scheme.'://localhost:'.$port;$client=new HttpClient(concurrency:2,timeout:10,caFile:$scheme==='https'?$certs.'/ca.pem':null);
                $name='value"\\name';$value="one\0two\r\n";$filename='binary"\\payload.bin';
                $files=[['field'=>'files[]','path'=>$file,'filename'=>$filename],['field'=>'files[]','path'=>$empty,'filename'=>'empty.txt','contentType'=>'text/plain']];
                echo json_encode(['stage'=>'large','scheme'=>$scheme,'pending'=>Asyncara\Future::pendingCount()]),"\n";
                $pending=$client->multipartFiles($url.'/hold/'.$scheme,[$name=>$value,'empty'=>''],$files);
                event($pipes[1],$scheme);$peak=0;$upload=0;
                for($i=0;$i<2;$i++){Async::sleep(.002);verify(!$pending->isCompleted(),'Upload ended before ticks');$n=asyncara_info();$peak=max($peak,$n['nativeMemoryBytes']);$upload=max($upload,$n['httpUploadBufferedBytes']);}
                verify($peak<2097152&&$upload<=65536,'Unbounded upload buffering');
                fwrite($pipes[0],'release '.$scheme."\n");$result=$pending->await()->json();verify($result['method']==='POST'&&count($result['parts'])===4,'MIME part count');
                $expected=[[$name,null,strlen($value),hash('sha256',$value)],['empty',null,0,hash('sha256','')],['files[]',$filename,33554432,$digest],['files[]','empty.txt',0,hash('sha256','')]];
                foreach($expected as$i=>[$field,$filename,$bytes,$sha]){$p=$result['parts'][$i];verify($p['field']===$field&&$p['filename']===$filename&&$p['bytes']===$bytes&&$p['sha256']===$sha,'MIME content identity '.$i);}
                verify($result['parts'][3]['type']==='text/plain','Per-file content type');
                $one=$client->multipart($url,[], 'one',$empty,'zero.txt')->await()->json();verify(count($one['parts'])===1&&$one['parts'][0]['filename']==='zero.txt','Single multipart');
                echo json_encode(['stage'=>'cancel','scheme'=>$scheme,'pending'=>Asyncara\Future::pendingCount()]),"\n";
                $cancel=$scheme.'cancel';$pending=$client->multipartFiles($url.'/hold/'.$cancel,[],$files);event($pipes[1],$cancel);
                $waiter=Async::spawn(fn()=>$pending->await());Async::sleep(0);$waiter->cancel();rejects($waiter->await(...),CancelledException::class);verify(!$pending->isCompleted(),'Waiter destroyed owner');
                $pending->cancel();rejects($pending->await(...),CancelledException::class);fwrite($pipes[0],'release '.$cancel."\n");
                // Start the owner deadline after the peer barrier; a connect
                // timeout before request admission cannot satisfy that barrier.
                echo json_encode(['stage'=>'deadline','scheme'=>$scheme,'pending'=>Asyncara\Future::pendingCount()]),"\n";
                $expire=$scheme.'expire';$pending=$client->multipartFiles($url.'/hold/'.$expire,[],$files);event($pipes[1],$expire);
                rejects(fn()=>Async::timeout(.05,fn()=>Async::await($pending)),TimeoutException::class);fwrite($pipes[0],'release '.$expire."\n");
                echo json_encode(['stage'=>'early-response','scheme'=>$scheme,'pending'=>Asyncara\Future::pendingCount()]),"\n";
                $early=$scheme.'reject';$pending=$client->multipartFiles($url.'/reject/'.$early,[],$files);event($pipes[1],$early);verify($pending->await()->status===413,'Early response');fwrite($pipes[0],'release '.$early."\n");
                echo json_encode(['stage'=>'missing-file','scheme'=>$scheme,'pending'=>Asyncara\Future::pendingCount()]),"\n";
                rejects(fn()=>$client->multipartFiles($url,[],[['field'=>'missing','path'=>$out.'/absent']])->await(),OperationException::class);
                verify(!asyncara_info()['pendingOperations']&&!asyncara_info()['openFileHandles'],'Upload ownership cleanup');
                $metrics[]=compact('scheme','peak','upload')+['peerReadSeconds'=>$result['readSeconds'],'peerParseSeconds'=>$result['parseSeconds']];
            }
            $client=new HttpClient();$url='http://127.0.0.1:'.$ports['http'];
            foreach([['x'=>str_repeat('x',65537)],array_fill_keys(array_map(fn($i)=>'f'.$i,range(1,65)),''),["bad\r\nheader"=>'x'],['x'=>new stdClass()]]as$fields)
                rejects(fn()=>$client->multipart($url,$fields,'file',$empty)->await(),ValueError::class);
            rejects(fn()=>$client->multipart($url,[],'x',$empty,headers:['Content-Type: invalid'])->await(),ValueError::class);
            // Configuration copies detach caller reference entries before admission.
            $value='before';$field=['x'=>&$value];$pending=$client->multipart($url,$field,'file',$empty);$value='after';
            verify($pending->await()->json()['parts'][0]['sha256']===hash('sha256','before'),'Borrowed field reference');
        });
    });
    $n=asyncara_info();$d=Runtime::diagnostics();verify($n['nativeMemoryPeak']<2097152&&memory_get_peak_usage(true)<16777216,'Peak memory bound');verify(!$n['pendingOperations']&&!$n['nativeMemoryBytes']&&!$n['openFileHandles'],'Final native cleanup');
    if($d['pendingFutures']!==0) {
        $property=new ReflectionProperty(Asyncara\Future::class,'pending');$pending=$property->getValue();$objects=[];
        foreach($pending as$future=>$_){$listeners=new ReflectionProperty(Asyncara\Future::class,'listeners');$objects[]=['class'=>get_class($future),'complete'=>$future->isCompleted(),'listeners'=>count($listeners->getValue($future)),'canceller'=>$future->canceller!==null,'diagnosticOrigin'=>is_array($_)?$_:null];}
        echo json_encode(['recorded'=>$d,'currentPending'=>Asyncara\Future::pendingCount(),'pending'=>$objects]),"\n";
    }
    foreach(['activeTasks','pendingFutures','activeTimers','ioWatchers']as$key)verify($d[$key]===0,$key);
    echo json_encode(['status'=>'PASS','metrics'=>$metrics,'native'=>$n,'runtime'=>$d]),"\n";
}finally{if(getenv('ASYNCARA_MULTIPART_DIAGNOSTIC')==='1'){echo json_encode(['cpu'=>getrusage(),'native'=>asyncara_info()]),"\n";Asyncara\Trace::dump($out.'/trace.json');}fclose($pipes[0]);stream_set_blocking($pipes[1],true);stream_get_contents($pipes[1]);fclose($pipes[1]);verify(proc_close($peer)===0,'Peer shutdown');}
