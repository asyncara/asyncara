<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Runtime};use Asyncara\HTTP\HttpClient;
use Asyncara\Exception\{OperationException,CancelledException};
function check(bool $v,string $why):void{if(!$v)throw new RuntimeException($why);}
function rejects(callable $fn):void{try{$fn();}catch(OperationException){return;}throw new RuntimeException('Unsupported or invalid operation accepted');}
Runtime::configure(['httpMaxConnections'=>4,'httpMaxHostConnections'=>4]);
$root=dirname(__DIR__,2);
$server=proc_open(['python3',dirname(__DIR__).'/fixtures/http_stream_server.py',"$root/build/test-certs/cert.pem","$root/build/test-certs/key.pem"],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',"$root/build/hyper-fixture.log",'a']],$pipes);
try{
 $ports=json_decode(fgets($pipes[1]),true,512,JSON_THROW_ON_ERROR);$url='http://127.0.0.1:'.$ports['http'];
 Async::run(function()use($url):void{
  $c=new HttpClient(concurrency:4,timeout:2);
  for($i=0;$i<100;$i++)check($c->get($url.'/empty')->await()->status===204,'Repeated empty response');
  // The peer cannot complete until an independent task observes both timer ticks.
  $pending=$c->get($url.'/headers-first');$ticks=0;
  for($i=0;$i<2;$i++){Async::sleep(.002);check(!$pending->isCompleted(),'Body completed before release');$ticks++;}
  $c->get($url.'/release-body')->await();check($pending->await()->body==='x'&&$ticks===2,'Pending I/O timer progress');
  $jobs=[];for($i=0;$i<16;$i++)$jobs[]=$c->get($url.'/small');
  foreach($jobs as$j)check(strlen($j->await()->body)===65536,'Queued or partial response');
  check(asyncara_info()['hyperConnections']<=4,'Connection bound');
  $chunks=$c->get($url.'/chunks')->await();check(strlen($chunks->body)===67653&&$chunks->headers['trailer']===['value'],'Chunk/trailer parsing');
  $decoded=$c->get($url.'/gzip')->await();
  check($decoded->body===str_repeat(implode('',array_map('chr',range(0,255))),256),'Buffered gzip decoding');
  rejects(fn()=>$c->get($url.'/large')->await());
  rejects(fn()=>$c->get($url.'/broken')->await());
  rejects(fn()=>(new HttpClient(timeout:.02))->get($url.'/stall')->await());
  $p=$c->get($url.'/stall');Async::sleep(.002);$p->cancel();
  try{$p->await();throw new RuntimeException('Cancel not delivered');}catch(CancelledException){}
  rejects(fn()=>$c->get('http://localhost:1/')->await());
  rejects(fn()=>$c->get('https://127.0.0.1:1/')->await());
  check($c->get($url.'/echo-headers',['X-Marker: value'])->await()->json()['marker']==='value','Custom header');
  rejects(fn()=>$c->get($url.'/small',['Host: attacker.invalid'])->await());
  check(strlen($c->get($url.'/small',followRedirects:true)->await()->body)===65536,'Redirect policy on terminal response');
  $post=$c->post($url.'/small',body:'body')->await()->json();
  check($post['method']==='POST'&&$post['bytes']===4&&$post['sha256']===hash('sha256','body'),'Buffered POST');
  rejects(fn()=>(new HttpClient(proxy:'http://127.0.0.1:1'))->get($url)->await());
  check($c->get($url.'/empty')->await()->status===204,'Recovery after failures');
 });
 $i=asyncara_info();check($i['hyperSubmitted']===$i['hyperCompleted'],'Completion identity');check($i['pendingOperations']===0&&$i['nativeMemoryBytes']===0,'Job/buffer ownership');
 echo json_encode(['status'=>'PASS','native'=>$i]),"\n";
}finally{foreach($pipes as$p)fclose($p);check(proc_close($server)===0,'Server exit');}
