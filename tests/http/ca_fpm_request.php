<?php
declare(strict_types=1);
// Private-CA variation; historical acceptance and its original assertions stay intact.
putenv('ASYNCARA_HTTP_BACKEND='.$caConfig['transport']);
putenv('ASYNCARA_BACKEND='.$caConfig['backend']);
foreach(['http_proxy','HTTP_PROXY','https_proxy','HTTPS_PROXY','all_proxy','ALL_PROXY']as$key)putenv($key);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Future};
use Asyncara\HTTP\HttpClient;
$before=asyncara_info();
foreach(['hyperSubmitted','hyperCompleted','hyperConnections','pendingOperations','nativeMemoryBytes']as$key)
    if($before[$key]!==0)throw new RuntimeException('Cross-request owner '.$key);
$id=$_SERVER['AUDIT_ID'];$start=microtime(true);$mode=$_GET['mode']??'';
$url=str_replace('http://','https://',$_SERVER['AUDIT_URL']);
if($mode==='fatal'){
    $trust=$caConfig['transport']==='hyper-prototype'?['caPem'=>file_get_contents($caConfig['trustDirectory'].'/ca.pem')]:['caFile'=>$caConfig['trustDirectory'].'/ca.pem'];
    asyncara_submit('http',[$url,null,['method'=>'GET','headers'=>[]]+$trust,5000]);
    asyncara_poll([],[],0);
    if(!asyncara_info()['pendingOperations'])throw new RuntimeException('Fatal control lost pending transfer');
    ini_set('memory_limit','8M');str_repeat('x',16*1024*1024);exit;
}
$count=$mode==='io'?4:1;$cwd=getcwd();
try{
    $result=Async::run(function()use($count,$url,$caConfig):array{
        chdir($caConfig['trustDirectory']);$client=new HttpClient(concurrency:4,caFile:'ca.pem');
        $jobs=[];for($n=0;$n<$count;$n++)$jobs[]=$client->get($url);
        chdir($caConfig['wrongDirectory']);
        $ticks=0;$ticker=Async::spawn(function()use($jobs,&$ticks):void{
            while(array_filter($jobs,static fn($job)=>!$job->isCompleted())){
                Async::sleep(.005);if(array_filter($jobs,static fn($job)=>!$job->isCompleted()))$ticks++;
            }
        });
        $intervals=array_map(static fn($response)=>$response->json(),Async::gather($jobs));$ticker->await();
        if($ticks<2||max(array_column($intervals,'start'))>=min(array_column($intervals,'end')))
            throw new RuntimeException('Original overlap/two-tick assertion failed');
        return ['ticks'=>$ticks,'http'=>$intervals];
    });
}finally{chdir($cwd);}
gc_collect_cycles();$after=asyncara_info();
if($after['pendingOperations']||$after['nativeMemoryBytes']||$after['nativeWorkers']||Future::pendingCount())throw new RuntimeException('Native trust ownership');
if($caConfig['transport']==='hyper-prototype'&&($after['hyperSubmitted']!==$count||$after['hyperCompleted']!==$count))throw new RuntimeException('Hyper submission count');
header('Content-Type: application/json');echo json_encode(['id'=>$id,'pid'=>getmypid(),'start'=>$start,'end'=>microtime(true),'before'=>$before,'after'=>$after,'result'=>$result],JSON_THROW_ON_ERROR);
