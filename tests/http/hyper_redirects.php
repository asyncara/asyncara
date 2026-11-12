<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Runtime,Scheduler};
use Asyncara\HTTP\HttpClient;
use Asyncara\Exception\{OperationException,CancelledException,TimeoutException};
function check(bool $v,string $why):void{if(!$v)throw new RuntimeException($why);}
function rejected(callable $f):void{try{$f();}catch(OperationException){return;}throw new RuntimeException('Invalid redirect accepted');}
function event($p):array{$l='';while(!str_ends_with($l,"\n")){Scheduler::current()->readable($p);$s=fgets($p);if($s!==false)$l.=$s;check(!feof($p),'Peer ended');}return json_decode($l,true,512,JSON_THROW_ON_ERROR);}
$c=$argv[1];$o=$argv[2];check(asyncara_info()['httpBackend']==='hyper-prototype','Expected Hyper');
$peer=proc_open(['python3',__DIR__.'/hyper_redirect_peer.py',$c],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',$o.'/peer.log','w']],$pipes);
try{
 $ports=json_decode(fgets($pipes[1]),true,512,JSON_THROW_ON_ERROR);stream_set_blocking($pipes[1],false);
 Async::run(function()use($c,$o,$ports,$pipes){Async::timeout(20,function()use($c,$o,$ports,$pipes){
  $client=new HttpClient(timeout:3);$base='http://127.0.0.1:'.$ports['http'];$other='http://127.0.0.1:'.$ports['other'];$https='https://localhost:'.$ports['https'];
  $r=static fn($code,$to)=>'/r/'.$code.'?to='.rawurlencode($to);
  if(getenv('ASYNCARA_REDIRECT_MEMORY_WARMUP')==='1') {
   $cold='PASS';$start=hrtime(true);
   try{(new HttpClient(timeout:3,caFile:$c.'/ca.pem'))->get($https.'/echo')->await();}
   catch(OperationException $e){if($e->getMessage()!=='HTTP operation timed out')throw $e;$cold='TIMED_OUT';}
   check(!asyncara_info()['pendingOperations']&&!asyncara_info()['nativeMemoryBytes'],'Cold cleanup');
   echo json_encode(['memoryWarmup'=>$cold,'seconds'=>(hrtime(true)-$start)/1e9]),"\n";
  }

  check($client->get($base.$r(302,'/echo'))->await()->status===302,'Redirect opt-in');
  foreach([301,302,303,307,308]as$code)foreach(['GET','HEAD','POST','PUT']as$method){
   $body=in_array($method,['POST','PUT'])?'request-body':null;$headers=['Authorization: sentinel','Cookie: sentinel','Content-Type: application/example'];
   $response=$client->request($method,$base.$r($code,'../echo?answer=42#fragment'),$body,$headers,true)->await();check($response->status===200,'Final status');
   if($method==='HEAD'){check($response->body==='','HEAD body');continue;}
   $expected=($code===303||($method==='POST'&&in_array($code,[301,302])))?'GET':$method;$value=$response->json();
   check($value['method']===$expected&&$value['target']==='/echo?answer=42','Redirect method/relative target');
   check($value['authorization']&&$value['cookie'],'Same-origin identity removed');
   check($value['size']===($expected==='GET'?0:strlen($body??'')),'Request content replay');
   if($expected!==$method)check(!$value['contentType'],'Dropped body retained content headers');
  }
  $v=$client->get($base.$r(302,$other.'/echo'),['Authorization: sentinel','Cookie: sentinel'],true)->await()->json();check(!$v['authorization']&&!$v['cookie'],'Cross-origin credentials leaked');
  $secure=new HttpClient(timeout:3,caFile:$c.'/ca.pem');check($secure->get($https.$r(302,'/echo'),followRedirects:true)->await()->status===200,'TLS redirect');
  check($secure->get($base.$r(302,$https.'/echo'),followRedirects:true)->await()->status===200,'Verified HTTPS upgrade');
  rejected(fn()=>$secure->get($https.$r(302,$base.'/echo'),followRedirects:true)->await());
  foreach(['/loop','/ambiguous',$r(302,'file:///etc/passwd'),$r(302,'http://user:secret@127.0.0.1/')]as$bad)rejected(fn()=>$client->get($base.$bad,followRedirects:true)->await());
  $chain='/echo';for($i=0;$i<5;$i++)$chain=$r(302,$chain);check($client->get($base.$chain,followRedirects:true)->await()->status===200,'Five hops rejected');
  rejected(fn()=>$client->get($base.$r(302,$chain),followRedirects:true)->await());
  // Never collect a large redirect body. Independent peer keeps it unfinished
  // until the client closes it, then a final bounded stream transfers 2 MiB.
  $stream=$client->getStream($base.'/large-redirect',followRedirects:true);check($stream->headers()->await()->status===200,'Intermediate headers exposed');$hash=hash_init('sha256');$size=0;
  foreach($stream as$chunk){$size+=strlen($chunk);hash_update($hash,$chunk);Async::sleep(.0001);}
  check($size===2097152&&hash_final($hash)===hash('sha256',str_repeat(implode('',array_map(chr(...),range(0,255))),8192)),'Redirect streamed integrity');
  check($client->get($base.'/state')->await()->json()['unfinishedRedirectClosed']===1,'Redirect was not closed before completion');
  $pending=$client->get($base.$r(302,'/hold'),followRedirects:true);check(isset(event($pipes[1])['pending']),'Missing pending read');
  for($i=0;$i<2;$i++){Async::sleep(.002);check(!$pending->isCompleted(),'Redirect I/O preceded timer progress');}
  $waiter=Async::spawn(fn()=>$pending->await());Async::sleep(0);$waiter->cancel();try{$waiter->await();}catch(CancelledException){}
  check(!$pending->isCompleted(),'Waiter cancelled chain');$pending->cancel();try{$pending->await();throw new RuntimeException('Owner cancellation ignored');}catch(CancelledException){}
  $short=new HttpClient(timeout:.08);$start=Scheduler::now();$timed=$short->get($base.$r(302,'/hold'),followRedirects:true);check(isset(event($pipes[1])['pending']),'Missing timeout barrier');
  try{$timed->await();throw new RuntimeException('Chain deadline ignored');}catch(TimeoutException|OperationException){}check(Scheduler::now()-$start<1,'Chain waited full per-hop deadline');
  try{(new HttpClient(timeout:.12))->get($base.'/timed/3',followRedirects:true)->await();throw new RuntimeException('Deadline reset for each hop');}catch(TimeoutException|OperationException){}
  $s=$client->getStream($base.$r(302,'/hold'),followRedirects:true);check(isset(event($pipes[1])['pending']),'Stream cancellation barrier');$s->close();
  check($client->get($base.'/echo')->await()->status===200,'Pool recovery');
 });});
 $n=asyncara_info();$d=Runtime::diagnostics();check(!$n['pendingOperations']&&!$n['nativeMemoryBytes']&&!$n['openFileHandles'],'Native cleanup');
 foreach(['activeTasks','pendingFutures','ioWatchers','activeTimers']as$k)check($d[$k]===0,$k);
 echo json_encode(['status'=>'PASS','mode'=>getenv('ASYNCARA_REDIRECT_MEMORY_WARMUP')==='1'?'WARMED_MEMORY_DIAGNOSTIC':'DEFAULT_FIXTURE','twoTicksWhilePending'=>true,'native'=>$n,'runtime'=>$d]),"\n";
}finally{fclose($pipes[0]);stream_set_blocking($pipes[1],true);stream_get_contents($pipes[1]);fclose($pipes[1]);check(proc_close($peer)===0,'Peer cleanup');}
