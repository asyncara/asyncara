<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Future,Runtime};use Asyncara\HTTP\HttpClient;
use Asyncara\Exception\{OperationException,CancelledException};
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
[$script,$certs,$out]=$argv;
$bounds=in_array('--bounds',$argv,true);
if($bounds)Runtime::configure(['nativeMemoryLimit'=>1048576]);
$peer=proc_open(['python3',__DIR__.'/compression_peer.py',$certs.'/server.pem',$certs.'/server.key'],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',$out.'/peer.log','x']],$pipes);
try{
 $ports=json_decode(fgets($pipes[1]),true,flags:JSON_THROW_ON_ERROR);
 if(!$bounds)Async::run(function()use($ports,$certs){
  foreach(['http://127.0.0.1:'.$ports['http'],'https://localhost:'.$ports['https']]as$url){
   $client=new HttpClient(concurrency:4,caFile:str_starts_with($url,'https:')?$certs.'/ca.pem':null);
   foreach(['/gzip','/deflate','/case','/identity','/byte-chunks']as$path){$r=$client->get($url.$path)->await();check(strlen($r->body)===65536&&hash('sha256',$r->body)===$ports['decoded'],'Decoded identity '.$path);}
   check($client->get($url.'/empty')->await()->body==='','Empty compressed member');
   $body=$client->get($url.'/concat')->await()->body;check(strlen($body)===131072&&hash('sha256',substr($body,0,65536))===$ports['decoded']&&substr($body,0,65536)===substr($body,65536),'Concatenated gzip members');
   check($client->request('HEAD',$url.'/gzip')->await()->body==='','HEAD representation metadata');
   check($client->get($url.'/not-modified')->await()->body==='','304 representation metadata');
   check($client->get($url.'/echo')->await()->body==='gzip, deflate','Advertised buffered codecs');
   foreach(['/truncated','/crc','/junk','/raw-deflate','/unknown','/stacked','/duplicate','/wire-truncated','/bomb']as$path){
    $failed=false;try{$client->get($url.$path)->await();}catch(OperationException){$failed=true;}
    check($failed,'Malformed/unsupported/oversized input accepted: '.$path);check(asyncara_info()['pendingOperations']===0,'Failed codec operation retained');
    check($client->get($url.'/identity')->await()->status===200,'Recovery after decoding failure');
   }
   $pending=$client->get($url.'/held');$deadline=hrtime(true)+2_000_000_000;
   while($client->get($url.'/waiting')->await()->body!=='1'){check(hrtime(true)<$deadline,'Peer gate not reached');Async::sleep(.001);}
   for($tick=0;$tick<2;$tick++){Async::sleep(.003);check(!$pending->isCompleted(),'Timer did not precede compressed I/O completion');}
   $client->get($url.'/release')->await();check(hash('sha256',$pending->await()->body)===$ports['decoded'],'Held decoded identity');
   foreach(['cancel','timeout']as$mode){
    $owner=$mode==='timeout'?new HttpClient(timeout:.15,caFile:str_starts_with($url,'https:')?$certs.'/ca.pem':null):$client;
    $pending=$owner->get($url.'/held');$deadline=hrtime(true)+2_000_000_000;
    while($client->get($url.'/waiting')->await()->body!=='1'){check(hrtime(true)<$deadline,'Cancellation peer gate absent');Async::sleep(.001);}
    if($mode==='cancel')$pending->cancel();
    $error=null;try{$pending->await();}catch(Throwable $e){$error=$e;}
    check($mode==='cancel'?$error instanceof CancelledException:$error instanceof OperationException,'Wrong cancellation/deadline result');
    $client->get($url.'/release')->await();$deadline=hrtime(true)+2_000_000_000;
    while($client->get($url.'/waiting')->await()->body!=='0'){check(hrtime(true)<$deadline,'Cancelled peer did not drain');Async::sleep(.001);}
    check(asyncara_info()['pendingOperations']===0&&asyncara_info()['nativeMemoryBytes']===0,'Cancelled decoder retained');
   }
   // ResponseStream remains raw encoded bytes, with its existing backpressure.
   $raw=implode('',iterator_to_array($client->getStream($url.'/gzip')));check(hash('sha256',$raw)===$ports['encoded'],'Streaming encoded bytes changed');
   $info=asyncara_info();check($info['pendingOperations']===0&&$info['nativeMemoryBytes']===0,'Decoded resources retained');
  }
 });
 if($bounds)Async::run(function()use($ports){
  $client=new HttpClient();$before=asyncara_info()['nativeMemoryRejections'];$error=null;
  try{$client->get('http://127.0.0.1:'.$ports['http'].'/bomb')->await();}catch(OperationException $e){$error=$e;}
  check($error!==null&&str_contains($error->getMessage(),'Native memory'),'Decoded output bypassed aggregate capacity');
  check(asyncara_info()['nativeMemoryRejections']>$before&&asyncara_info()['nativeMemoryBytes']===0,'Codec capacity cleanup');
 });
 gc_collect_cycles();$info=asyncara_info();check(Future::pendingCount()===0&&$info['pendingOperations']===0&&$info['nativeMemoryBytes']===0,'Final resources retained');
 echo json_encode(['status'=>'PASS','mode'=>$bounds?'bounds':'functional','native'=>$info]),"\n";
}finally{fclose($pipes[0]);stream_get_contents($pipes[1]);fclose($pipes[1]);check(proc_close($peer)===0,'Peer exit');}
