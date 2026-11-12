<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Future,Runtime};use Asyncara\HTTP\HttpClient;use Asyncara\Exception\{CancelledException,OperationException};
$c=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);
function verify(bool$ok,string$why):void{if(!$ok)throw new RuntimeException($why);}
function reject(callable$f,string$type=OperationException::class):void{try{$f();}catch(Throwable$e){verify($e instanceof $type,'Wrong rejection '.$e::class);return;}throw new RuntimeException('Unexpected success');}
$ticks=0;
Async::run(function()use($c,&$ticks):void{
 verify(asyncara_info()['httpBackend']==='hyper-prototype','Wrong transport');$control=new HttpClient();$url=$c['origin'].'/small';
 $client=new HttpClient(proxy:$c['held'],caFile:$c['ca'],timeout:5);$pending=$client->get($url);
 Async::timeout(2,function()use($control,$c):void{while($control->get($c['control'].'/state')->await()->json()['held']!==1)Async::sleep(0);});
 for($n=0;$n<2;$n++){Async::sleep(.005);verify(!$pending->isCompleted(),'Timer after CONNECT');$ticks++;}
 $control->get($c['control'].'/release')->await();verify(strlen($pending->await()->body)===65536,'Partial CONNECT headers lost');
 foreach(['informational','framing']as$mode)verify(strlen((new HttpClient(proxy:$c[$mode],caFile:$c['ca']))->get($url)->await()->body)===65536,'CONNECT switch '.$mode);
 foreach(['denied','upgrade','malformed','headers','oversize','short','prefix','storm']as$mode)reject(fn()=>(new HttpClient(proxy:$c[$mode],caFile:$c['ca'],timeout:3))->get($url)->await());
 $client=new HttpClient(proxy:$c['stall'],caFile:$c['ca'],timeout:5);$pending=$client->get($url);
 Async::timeout(2,function()use($control,$c):void{while($control->get($c['control'].'/state')->await()->json()['held']!==1)Async::sleep(0);});
 $pending->cancel();reject($pending->await(...),CancelledException::class);
 Async::timeout(2,function()use($control,$c):void{while($control->get($c['control'].'/state')->await()->json()['held']!==0)Async::sleep(0);});
 reject(fn()=>(new HttpClient(proxy:$c['stall'],caFile:$c['ca'],connectTimeout:.1,timeout:2))->get($url)->await());
 Async::timeout(2,function()use($control,$c):void{while($control->get($c['control'].'/state')->await()->json()['held']!==0)Async::sleep(0);});
 $state=$control->get($c['control'].'/state')->await()->json();foreach($state['counts']as$mode=>$count)verify($count>=1,'Rejected before reaching '.$mode);
});
gc_collect_cycles();verify(!asyncara_info()['pendingOperations']&&!asyncara_info()['nativeMemoryBytes']&&!Future::pendingCount(),'CONNECT operation leak');echo json_encode(['status'=>'PASS','ticks'=>$ticks,'native'=>asyncara_info(),'diagnostics'=>Runtime::diagnostics()]),"\n";
