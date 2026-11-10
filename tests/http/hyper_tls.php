<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Runtime,Scheduler};
use Asyncara\HTTP\HttpClient;
use Asyncara\Exception\{OperationException,CancelledException};
function check(bool $v,string $why):void{if(!$v)throw new RuntimeException($why);}
function rejects(callable $f):void{try{$f();}catch(OperationException){return;}throw new RuntimeException('Rejected trust/request accepted');}
function event($p):array{
    $line='';while(!str_ends_with($line,"\n")){Scheduler::current()->readable($p);$s=fgets($p);if($s!==false)$line.=$s;check(!feof($p),'Peer ended');}
    return json_decode($line,true,512,JSON_THROW_ON_ERROR);
}
$certs=$argv[1];$out=$argv[2];Runtime::configure(['httpMaxConnections'=>8,'httpMaxHostConnections'=>4]);
check(asyncara_info()['httpBackend']==='hyper-prototype','Expected Hyper backend');
$caText=file_get_contents($certs.'/ca.pem');$wrongText=file_get_contents($certs.'/wrong.pem');
$peer=proc_open(['python3',__DIR__.'/hyper_tls_peer.py',$certs],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',$out.'/peer.log','w']],$pipes);
try{
 $ports=json_decode(fgets($pipes[1]),true,512,JSON_THROW_ON_ERROR);stream_set_blocking($pipes[1],false);
 Async::run(function()use($ports,$pipes,$certs,$out,$caText,$wrongText):void{
  Async::timeout(12,function()use($ports,$pipes,$certs,$out,$caText,$wrongText):void{
   $client=new HttpClient(concurrency:4,timeout:3,caFile:$certs.'/ca.pem');
   foreach(['tls12'=>'TLSv1.2','tls13'=>'TLSv1.3']as$key=>$version){
    $url='https://localhost:'.$ports[$key];$a=$client->get($url)->await()->json();$b=$client->get($url)->await()->json();
    check($a['version']===$version&&$a['alpn']==='http/1.1'&&$a['sni']==='localhost','TLS version/SNI/ALPN');
    check($a['connection']===$b['connection'],'Verified connection not pooled');
    check(hash('sha256',$client->get($url.'/large')->await()->body)==='fbbab289f7f94b25736c58be46a994c441fd02552cc6022352e3d86d2fab7c83','TLS large body integrity');
   }
   $url='https://127.0.0.1:'.$ports['tls13'];
   $numeric=$client->get($url)->await()->json();check($numeric['sni']===null,'IP sent as DNS SNI');
   rejects(fn()=>(new HttpClient(caFile:$certs.'/wrong.pem'))->get($url)->await());
   rejects(fn()=>$client->get('https://127.0.0.1:'.$ports['dns'])->await());
   check($client->get('https://localhost:'.$ports['dns'])->await()->status===200,'DNS certificate identity');
   foreach(['expired','usage','mtls']as$invalid)rejects(fn()=>$client->get('https://127.0.0.1:'.$ports[$invalid])->await());
   // Explicit trust changes cannot borrow a connection verified under old roots.
   $rotation=$out.'/rotation.pem';Asyncara\IO\Files::write($rotation,$caText)->await();
   $snapshot=new HttpClient(caFile:$rotation);$old=$snapshot->get($url)->await()->json();
   Asyncara\IO\Files::write($rotation,$wrongText)->await();
   rejects(fn()=>(new HttpClient(caFile:$rotation))->get($url)->await());
   check($snapshot->get($url)->await()->status===200,'Existing trust snapshot changed');
   $env=getenv('SSL_CERT_FILE');putenv('SSL_CERT_FILE='.$certs.'/ca.pem');
   try{check((new HttpClient())->get($url)->await()->status===200,'Default SSL_CERT_FILE trust');}finally{putenv($env===false?'SSL_CERT_FILE':'SSL_CERT_FILE='.$env);}
   // Independent peer prevents body completion until both timers execute.
   $pending=[$client->get($url.'/hold'),$client->get($url.'/hold')];
   for($i=0;$i<2;$i++)check(isset(event($pipes[1])['pending']),'Missing body barrier');
   for($i=0;$i<2;$i++){Async::sleep(.002);foreach($pending as$f)check(!$f->isCompleted(),'TLS body completed before release');}
   $waiter=Async::spawn(fn()=>$pending[0]->await());Async::sleep(0);$waiter->cancel();
   try{$waiter->await();throw new RuntimeException('Wait cancellation lost');}catch(CancelledException){}
   check(!$pending[0]->isCompleted(),'Shared TLS owner cancelled');fwrite($pipes[0],"release\n");foreach($pending as$f)check($f->await()->status===200,'TLS pending result');
   $stall='https://127.0.0.1:'.$ports['stall'];$f=$client->get($stall);check(event($pipes[1])===['handshake'=>'pending'],'TLS not pending');
   for($i=0;$i<2;$i++){Async::sleep(.002);check(!$f->isCompleted(),'Handshake prevented timer progress');}
   $f->cancel();try{$f->await();throw new RuntimeException('TLS cancellation lost');}catch(CancelledException){}
   // Native connect budget includes TLS, independently of file/DNS setup cost.
   $start=Scheduler::now();$f=Scheduler::current()->native('http',[$stall,null,['caPem'=>$caText,'connectTimeoutMs'=>50],1000]);
   check(event($pipes[1])===['handshake'=>'pending'],'Deadline handshake did not start');rejects(fn()=>$f->await());
   check(Scheduler::now()-$start<.8,'Connect timeout did not include TLS');
   check($client->get($url)->await()->status===200,'Recovery after handshake cancellation');
  });
 });
 $n=asyncara_info();$d=Runtime::diagnostics();check($n['hyperSubmitted']===$n['hyperCompleted'],'Completion identity');
 check(!$n['pendingOperations']&&!$n['nativeMemoryBytes']&&!$n['openFileHandles'],'Native ownership');
 foreach(['activeTasks','pendingFutures','ioWatchers','activeTimers']as$k)check($d[$k]===0,$k);
 echo json_encode(['status'=>'PASS','ticksDuringPendingBody'=>2,'ticksDuringPendingHandshake'=>2,'native'=>$n,'runtime'=>$d]),"\n";
}finally{fclose($pipes[0]);stream_set_blocking($pipes[1],true);stream_get_contents($pipes[1]);fclose($pipes[1]);check(proc_close($peer)===0,'Peer shutdown');}
