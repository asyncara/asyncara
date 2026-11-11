<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,CancellationSource,Future,Runtime,Scheduler};
use Asyncara\HTTP\HttpClient;
use Asyncara\Exception\{OperationException,CancelledException};
$cfg=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);
ini_set('zend.exception_ignore_args','0');
function verify(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function credentials():array{return ['username'=>'asyncara-test','password'=>'synthetic:proxy-secret'];}
// Do not leak the test closure's captured synthetic credentials via this helper.
// The checked production frames still retain their ordinary trace behavior.
function rejected(#[\SensitiveParameter] callable $f,string $type=ValueError::class):void{
    $caught=null;try{$f();}catch(Throwable $e){$caught=$e;}
    verify($caught instanceof $type,'Expected '.$type);
    $exposed=str_contains((string)$caught.print_r($caught->getTrace(),true),credentials()['password']);
    if($exposed)foreach($caught->getTrace()as$frame)if(str_contains(print_r($frame,true),credentials()['password']))
        fwrite(STDERR,json_encode(['exposingFunction'=>($frame['class']??'').($frame['function']??''),'line'=>$frame['line']??null])."\n");
    verify(!$exposed,'Credential exposed in trace');
}
$checks=[];$ticks=0;
$exercise=function(?string $onlyKind=null)use($cfg,&$checks,&$ticks):void{
    $control=new HttpClient();
    foreach(['httpProxy','httpsProxy']as$kind){
        if($onlyKind!==null&&$kind!==$onlyKind)continue;
        $proxy=$cfg[$kind];$trust=$kind==='httpsProxy'?$cfg['ca']:null;
        $username='asyncara-test';$password=credentials()['password'];
        $client=new HttpClient(concurrency:4,timeout:5,proxy:$proxy,caFile:$cfg['ca'],proxyCaFile:$trust,
            proxyCredentials:['username'=>&$username,'password'=>&$password]);
        verify($username==='asyncara-test'&&$password===credentials()['password'],'Constructor changed caller values');
        $username='wrong';$password='changed';
        foreach(['http://proxy-target.invalid',$cfg['origin']]as$url){
            verify($client->get($url.'/ok',['Authorization: synthetic-origin-token'])->await()->body==='origin-ok','Authenticated GET');
            verify($client->get($url.'/redirect',followRedirects:true)->await()->body==='origin-ok','Redirect authentication');
            $stream=$client->getStream($url.'/stream');$bytes=0;
            $stream->consume(static function(string $chunk)use(&$bytes):void{$bytes+=strlen($chunk);});
            verify($bytes===524288,'Stream integrity');
            $data=str_repeat("data\0",4000);$response=$client->post($url.'/upload',body:$data)->await()->json();
            verify($response['bytes']===strlen($data)&&$response['sha256']===hash('sha256',$data),'Upload integrity');
        }
        $body=str_repeat('x',65536);
        verify($client->requestStream('POST',$cfg['origin'].'/upload',[$body],strlen($body))->await()->json()['bytes']===65536,'Streamed CONNECT upload');
        $control->get($cfg['control'].'/reset')->await();
        $held=[$client->get($cfg['origin'].'/hold'),$client->get($cfg['origin'].'/hold')];
        Async::timeout(2,function()use($control,$cfg):void{while($control->get($cfg['control'].'/state')->await()->json()['held']!==2)Async::sleep(0);});
        for($i=0;$i<2;$i++){Async::sleep(.005);foreach($held as$f)verify(!$f->isCompleted(),'Timer after I/O');$ticks++;}
        $token=new CancellationSource();$waiter=Async::spawn(fn()=>$held[0]->await($token->token));Async::sleep(0);$token->cancel();
        rejected($waiter->await(...),CancelledException::class);verify(!$held[0]->isCompleted(),'Shared owner cancelled');
        $held[1]->cancel();rejected($held[1]->await(...),CancelledException::class);
        $control->get($cfg['control'].'/release')->await();verify($held[0]->await()->body==='origin-ok','Sibling lost');
        foreach([null,['username'=>'wrong','password'=>credentials()['password']]]as$bad){
            $other=new HttpClient(proxy:$proxy,caFile:$cfg['ca'],proxyCaFile:$trust,proxyCredentials:$bad);
            verify($other->get('http://proxy-target.invalid/ok')->await()->status===407,'Reused previous credentials');
            rejected(fn()=>$other->get($cfg['origin'].'/ok')->await(),OperationException::class);
        }
        verify($client->get($cfg['origin'].'/ok')->await()->body==='origin-ok','Recovery failed');
        foreach(['Proxy-Authorization: synthetic','pRoXy-AuThOrIzAtIoN;']as$header)
            rejected(fn()=>$client->get($cfg['origin'].'/ok',[$header])->await());
        $checks[]=$kind.'_forward_connect_stream_upload_redirect_shared_cancel_reuse';
    }
    // Native option ownership: mutate the source after submission.
    $pass=credentials()['password'];$options=['proxy'=>$cfg['httpsProxy'],'proxyCaFile'=>$cfg['ca'],
        'proxyUsername'=>'asyncara-test','proxyPassword'=>&$pass];
    $f=Scheduler::current()->native('http',['http://proxy-target.invalid/ok',null,$options,5000]);$pass='wrong';unset($options);
    verify($f->await()['body']==='origin-ok','Native credential snapshot');
    foreach([[],['username'=>'a'],['username'=>'a','password'=>['bad']],['username'=>'a:b','password'=>''],
        ['username'=>'','password'=>''],['username'=>str_repeat('x',4097),'password'=>''],
        ['username'=>'a','password'=>"bad\0secret"],['username'=>'a','password'=>"bad\r\n"],
        ['username'=>'a','password'=>'','extra'=>1]]as$bad){
        rejected(fn()=>new HttpClient(proxy:$cfg['httpProxy'],proxyCredentials:$bad));
    }
    foreach([null,'socks5://127.0.0.1:1','http://u:p@localhost:1']as$proxy)
        rejected(fn()=>new HttpClient(proxy:$proxy,proxyCredentials:credentials()));
    foreach([['proxyUsername'=>'a'],['proxyPassword'=>credentials()['password']],
        ['proxyUsername'=>'a:b','proxyPassword'=>credentials()['password']],
        ['proxyUsername'=>'a','proxyPassword'=>credentials()['password'],'headers'=>["bad\r\n"]]]as$bad)
        rejected(fn()=>Scheduler::current()->native('http',[$cfg['direct'],null,['proxy'=>$cfg['httpProxy']]+$bad,1000])->await());
    rejected(fn()=>asyncara_submit('http',[$cfg['direct'],null,['proxyPassword'=>credentials()['password']],1000]));
    $checks[]='bounded_validation_native_snapshot_trace_redaction';
    verify($control->get($cfg['direct'].'/ok')->await()->body==='origin-ok','Direct recovery');
    $stats=$control->get($cfg['control'].'/state')->await()->json();
    verify($stats['leaked']===0&&$stats['originAuthOnConnect']===0,'Authentication crossed hop boundary');
};
if(in_array($argv[2]??'',['server','server-split'],true)){
    $app=new Asyncara\App();
    $shared=new HttpClient(proxy:$cfg['httpsProxy'],caFile:$cfg['ca'],proxyCaFile:$cfg['ca'],proxyCredentials:credentials());
    $app->get('/probe',fn()=>Asyncara\Server\Response::text($shared->get($cfg['origin'].'/ok')->await()->body));
    $runExercise=function(?string $kind=null)use($exercise):Asyncara\Server\Response{
        try{$exercise($kind);}catch(Throwable $error){
            // Test diagnostics only: no exception argument dump or payloads.
            fwrite(STDERR,json_encode(['failure'=>$error::class,'message'=>str_replace(credentials()['password'],'[redacted]',$error->getMessage()),'line'=>$error->getLine()])."\n");throw $error;
        }
        return Asyncara\Server\Response::text('checked');
    };
    $app->get('/exercise',fn()=>$runExercise());
    // Separate memory diagnostic scope: unchanged per-request deadlines.
    // The ordinary integration still runs the entire suite in one handler.
    if(($argv[2]??'')==='server-split')foreach(['httpProxy','httpsProxy']as$kind)
        $app->get('/exercise/'.$kind,fn()=>$runExercise($kind));
    $app->get('/diagnostics',fn()=>Asyncara\Server\Response::json($app->stats()));
    $app->get('/stop',function()use($app):Asyncara\Server\Response{$app->stop();return Asyncara\Server\Response::text('stopped');});
    $app->listen('127.0.0.1:0',['connections'=>32,'timeoutMs'=>10000],static function(string $address):void{echo "READY $address\n";flush();});
}else Async::run($exercise);
gc_collect_cycles();$native=asyncara_info();$diag=Runtime::diagnostics();
verify(!$native['pendingOperations']&&!$native['nativeMemoryBytes']&&!Future::pendingCount(),'Native owner leak');
foreach(['activeTasks','pendingFutures','activeTimers','ioWatchers']as$key)verify($diag[$key]===0,'Scheduler leak '.$key);
echo json_encode(['status'=>'PASS','ticks'=>$ticks,'checks'=>$checks,'native'=>$native]),"\n";
