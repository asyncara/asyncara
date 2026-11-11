<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Asyncara\{Async,Future,Runtime};
use Asyncara\HTTP\HttpClient;
use Asyncara\IO\{File,Files};
use Asyncara\SQLite\Connection;

function demand(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
putenv('ASYNCARA_BACKEND='.$config['backend']);putenv('ASYNCARA_HTTP_BACKEND=curl-multi');
foreach(['http_proxy','HTTP_PROXY','https_proxy','HTTPS_PROXY','all_proxy','ALL_PROXY']as$key)putenv($key);
$before=asyncara_info();
foreach(['pendingOperations','nativeMemoryBytes','nativeWorkers','openFileHandles']as$key)demand($before[$key]===0,'Cross-request native owner');
demand($before['eventLoopBackend']===$config['backend'],'Wrong FPM backend');
$id=$_SERVER['AUDIT_ID'];demand(preg_match('/\A[0-9]+\z/',$id)===1,'Invalid fixture request identity');
$prefix=$config['root'].'/'.getmypid().'-';
demand(glob($prefix.'*/a/.asyncara*')===[],'Previous request retained a staged file');
$directory=$prefix.$id;mkdir($directory,0700);mkdir($directory.'/a');mkdir($directory.'/b');
file_put_contents($directory.'/a/value','first');file_put_contents($directory.'/a/only','present');file_put_contents($directory.'/b/value','second');
$cwd=getcwd();$start=microtime(true);$mode=$_GET['mode']??'';
try{
    $result=Async::run(function()use($directory,$mode):array{
        if($mode==='fatal'){
            chdir($directory.'/a');$pending=File::stage('target');chdir($directory.'/b');$file=$pending->await();
            $file->write('unpublished')->await();
            demand(asyncara_info()['openFileHandles']===1&&count(glob($directory.'/a/.asyncara*'))===1,'Fatal fixture has no captured staged owner');
            ini_set('memory_limit','8M');asyncara_submit('read',[$_SERVER['AUDIT_LARGE_FILE']]);
            while(true)asyncara_poll([],[],1000); // Original fatal conversion workload, with an extra live stage.
        }
        $client=new HttpClient(concurrency:4);$jobs=[];$count=$mode==='io'?4:1;
        for($i=0;$i<$count;$i++)$jobs[]=$client->get($_SERVER['AUDIT_URL']);
        $ticks=0;$ticker=Async::spawn(function()use($jobs,&$ticks):void{
            while(array_filter($jobs,static fn($j)=>!$j->isCompleted())){
                Async::sleep(.005);if(array_filter($jobs,static fn($j)=>!$j->isCompleted()))$ticks++;
            }
        });
        chdir($directory.'/a');
        $open=File::open('value');$read=Files::readStream('value',2);
        $write=Files::writeStream('output',['written']);$stage=File::stage('target');
        $exists=Files::exists('only');$database=Connection::open('probe.sqlite');
        chdir($directory.'/b');
        $file=$open->await();try{demand($file->read(20)->await()==='first','FPM open moved');}finally{$file->close()->await();}
        $body='';try{foreach($read as$part)$body.=$part;}finally{$read->close();}demand($body==='first','FPM read stream moved');
        demand($write->await()===7&&file_get_contents($directory.'/a/output')==='written'&&!file_exists($directory.'/b/output'),'FPM write stream moved');
        $file=$stage->await();try{$file->write('published')->await();$file->publish()->await();}finally{$file->close()->await();}
        demand(file_get_contents($directory.'/a/target')==='published'&&!file_exists($directory.'/b/target'),'FPM stage moved');
        demand($exists->await(),'FPM exists moved');
        $db=$database->await();try{demand($db->query('SELECT 42 AS n')->await()[0]['n']===42,'FPM SQLite result');}finally{$db->close()->await();}
        demand(file_exists($directory.'/a/probe.sqlite')&&!file_exists($directory.'/b/probe.sqlite'),'FPM SQLite moved');
        $intervals=array_map(static fn($r)=>$r->json(),Async::gather($jobs));$ticker->await();
        demand($ticks>=2&&max(array_column($intervals,'start'))<min(array_column($intervals,'end')),'Original overlap/two-tick assertion failed');
        return ['pathChecks'=>6,'ticks'=>$ticks,'http'=>$intervals];
    });
}finally{
    chdir($cwd);
    foreach(['a','b']as$d){foreach(glob($directory.'/'.$d.'/*')as$f)unlink($f);rmdir($directory.'/'.$d);}rmdir($directory);
}
gc_collect_cycles();$after=asyncara_info();$d=Runtime::diagnostics();
foreach(['pendingOperations','nativeMemoryBytes','openFileHandles']as$key)demand($after[$key]===0,'Completed FPM owner retained');
demand($after['nativeWorkers']===4&&!Future::pendingCount()&&!$d['activeTasks']&&!$d['activeTimers']&&!$d['ioWatchers'],'FPM worker or scheduler state');
header('Content-Type: application/json');
echo json_encode(['id'=>$id,'pid'=>getmypid(),'start'=>$start,'end'=>microtime(true),'before'=>$before,'after'=>$after,'result'=>$result],JSON_THROW_ON_ERROR);
