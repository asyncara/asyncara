<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Future,Scheduler};
use Asyncara\HTTP\HttpClient;
use Asyncara\Exception\OperationException;
$config=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);
function verify(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function rejected(callable $call,string $class=OperationException::class):void{
    $error=null;try{$call();}catch(Throwable $caught){$error=$caught;}
    verify($error instanceof $class,'Expected proxy rejection '.$class);
}
$cwd=getcwd();$ticks=0;
try{
 Async::run(function()use($config,&$ticks):void{
    chdir($config['proxyDirectory']);
    $client=new HttpClient(concurrency:4,proxy:$config['proxy'],caFile:$config['originCA'],proxyCaFile:'ca.pem');
    chdir($config['originDirectory']);
    $http='http://proxy-target.invalid/small';$tls=$config['origin'];
    chdir($config['proxyDirectory']);
    $native=Scheduler::current()->native('http',[$http,null,['method'=>'GET','headers'=>[],'proxy'=>$config['proxy'],'proxyCaFile'=>'ca.pem'],5000]);
    chdir($config['originDirectory']);verify(strlen($native->await()['body'])===65536,'Native proxy trust snapshot');
    foreach([$http,$tls.'/small']as$url){
        $response=$client->get($url)->await();verify(strlen($response->body)===65536,'Proxy body');
        verify(!isset($response->headers['x-proxy-only']),'CONNECT metadata escaped into origin headers');
    }
    $held=$client->getStream($tls.'/headers-first');
    try{
        verify($held->headers()->await()->status===200,'Proxy stream head');
        $body=Async::spawn(function()use($held):string{$body='';foreach($held as$chunk)$body.=$chunk;return$body;});
        for($i=0;$i<2;$i++){Async::sleep(.005);verify(!$body->isCompleted(),'Held peer completed before release');$ticks++;}
        $client->get($tls.'/release-body')->await();verify($body->await()==='x','Held proxy body');
    }finally{$held->close();}
    $data=str_repeat('payload',9000);$uploaded=$client->requestStream('POST',$tls.'/upload',[$data],strlen($data))->await()->json();
    verify($uploaded['bytes']===strlen($data)&&$uploaded['sha256']===hash('sha256',$data),'CONNECT upload');
    verify($client->get($tls.'/gzip')->await()->body===str_repeat(implode('',array_map(chr(...),range(0,255))),256),'CONNECT gzip');
    $early=$client->getStream($tls.'/headers-first');$early->headers()->await();$early->close();$client->get($tls.'/release-body')->await();
    rejected(fn()=>(new HttpClient(proxy:$config['proxy'],caFile:$config['originCA']))->get($tls.'/small')->await());
    rejected(fn()=>(new HttpClient(proxy:$config['proxy'],caFile:$config['originCA'],proxyCaFile:$config['originCA']))->get($tls.'/small')->await());
    rejected(fn()=>(new HttpClient(proxy:$config['proxy'],caFile:$config['proxyCA'],proxyCaFile:$config['proxyCA']))->get($tls.'/small')->await());
    rejected(fn()=>(new HttpClient(proxy:$config['mismatchProxy'],caFile:$config['originCA'],proxyCaFile:$config['proxyCA']))->get($tls.'/small')->await());
    rejected(fn()=>new HttpClient(proxyCaFile:$config['proxyCA']),ValueError::class);
    rejected(fn()=>new HttpClient(proxy:'http://127.0.0.1:1',proxyCaFile:$config['proxyCA']),ValueError::class);
    rejected(fn()=>Scheduler::current()->native('http',[$http,null,['method'=>'GET','headers'=>[],'proxyCaFile'=>$config['proxyCA']],1000])->await(),ValueError::class);
    verify(strlen($client->get($tls.'/small')->await()->body)===65536,'Proxy recovery');
 });
}finally{chdir($cwd);}
gc_collect_cycles();verify(asyncara_info()['pendingOperations']===0&&asyncara_info()['nativeMemoryBytes']===0&&Future::pendingCount()===0,'Proxy resource ownership');
echo json_encode(['status'=>'PASS','ticksWhileHeld'=>$ticks,'backend'=>asyncara_info()['eventLoopBackend'],'php'=>PHP_VERSION]),"\n";
