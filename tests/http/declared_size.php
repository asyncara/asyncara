<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Future,Scheduler};use Asyncara\HTTP\HttpClient;use Asyncara\Exception\OperationException;
function verify(bool$ok,string$why):void{if(!$ok)throw new RuntimeException($why);}
$c=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);
Async::run(function()use($c):void{
 $client=new HttpClient(timeout:5,caFile:$c['ca']??null,proxy:$c['proxy']??null,proxyCaFile:$c['proxyCA']??null,proxyCredentials:$c['proxyCredentials']??null);
 $url=$c['url'].(isset($c['ca'])?'/declared':'');$warm=$client->get($url.'/state')->await();
 if(isset($c['ca']))verify($warm->headers['x-test-protocol']===['h2'],'Actual HTTP/2 transport');
 $sibling=null;
 if(isset($c['ca'])){
  $sibling=$client->get($c['url'].'/hold/declared-sibling');
  Async::timeout(1,function()use($client,$c):void{while($client->get($c['url'].'/state/declared-sibling')->await()->json()['held']!==1)Async::sleep(0);});
 }
 $pending=$client->get($url.'/announced');$start=Scheduler::now();$error=null;
 try{Async::timeout(1,$pending->await(...));}catch(Throwable$caught){$error=$caught;}
 echo json_encode(['announcedSeconds'=>Scheduler::now()-$start,'exception'=>$error?->getMessage(),'class'=>$error===null?null:$error::class]),"\n";
 try{verify($error instanceof OperationException&&str_contains($error->getMessage(),'HTTP body limit exceeded'),'Declared impossible body was not rejected before receiving it');}
 finally{if(!$pending->isCompleted())$pending->cancel();}
 Async::timeout(1,function()use($client,$url):void{while($client->get($url.'/state')->await()->json()['closed']!==1)Async::sleep(0);});
 if($sibling!==null){
  verify(!$sibling->isCompleted(),'Rejected body destroyed independent HTTP/2 stream');
  $client->get($c['url'].'/release/declared-sibling')->await();$r=$sibling->await();
  verify($r->body==='released'&&$r->headers['x-test-connection']===$warm->headers['x-test-connection'],'Rejection closed shared HTTP/2 transport');
 }
 verify($client->request('HEAD',$url.'/head')->await()->body==='','HEAD metadata treated as body');
 $r=$client->get($url.'/not-modified')->await();verify($r->status===304&&$r->body==='','304 metadata treated as body');
 $stream=$client->getStream($url.'/stream');$size=0;$hash=hash_init('sha256');
 foreach($stream as$chunk){$size+=strlen($chunk);hash_update($hash,$chunk);}
 verify($size===16777217&&hash_final($hash)===hash('sha256',str_repeat('x',16777217)),'Large bounded stream rejected or corrupted');
 verify(asyncara_info()['nativeMemoryPeak']<1048576,'Streaming full-buffered the body');
 $r=$client->get($url.'/exact')->await();verify(strlen($r->body)===16777216,'Exact limit rejected');unset($r);
 $error=null;try{$client->get($url.'/unknown')->await();}catch(OperationException$e){$error=$e;}
 verify($error!==null&&str_contains($error->getMessage(),'HTTP body limit exceeded'),'Unknown length escaped incremental bound');
 $stats=$client->get($url.'/state')->await()->json();verify($stats['bodyBytesOnAnnounced']===0,'Guard required body delivery');
});
gc_collect_cycles();verify(!asyncara_info()['pendingOperations']&&!asyncara_info()['nativeMemoryBytes']&&!Future::pendingCount(),'Resource retention');echo "PASS declared size, bodyless metadata, streaming and incremental limits\n";
