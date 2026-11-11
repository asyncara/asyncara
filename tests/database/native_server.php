<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{App,Async,CancellationSource,Future,Runtime};
use Asyncara\Server\{Request,Response};
use Asyncara\HTTP\HttpClient;
use Asyncara\IO\Files;
use Asyncara\Pool\ConnectionPool;
use Asyncara\Exception\{CancelledException,TimeoutException};
function demand(bool $v,string $why):void{if(!$v)throw new RuntimeException($why);}
$pg=(bool)getenv('ASYNCARA_PG_MTLS_FIXTURE');
$fixturePath=getenv($pg?'ASYNCARA_PG_MTLS_FIXTURE':'ASYNCARA_MY_MTLS_FIXTURE');
$fixture=json_decode(file_get_contents($fixturePath),true,flags:JSON_THROW_ON_ERROR);
$class=$pg?Asyncara\PostgreSQL\Connection::class:Asyncara\MySQL\Connection::class;
$options=$fixture['options'];$created=0;
$factory=function()use($class,$options,&$created){$created++;return $class::connect($options,timeout:5);};
$pool=new ConnectionPool($factory,static fn($db)=>$db->close(),size:4);
$app=new App();$http=new HttpClient();$ticks=0;$completed=0;
$app->get('/status',fn()=>Response::text('ready'));
$app->get('/work/{id}',function(Request $request)use($pool,$pg,$app,$http,$fixturePath,&$completed):Response{
    $id=(int)$request->param('id');$path=dirname($fixturePath).'/server-io-'.$id.'.tmp';
    $result=$pool->withConnection(function($db)use($id,$pg,$app,$http,$path):array{
        $file=Files::replaceAtomic($path,str_repeat((string)$id,512));
        $reply=$http->get('http://'.$app->stats()['address'].'/status');
        $redis=null;
        try{
            $redis=Asyncara\Redis\Connection::connect('127.0.0.1',(int)getenv('ASYNCARA_SERVER_REDIS_PORT'),
                password:'synthetic-server-password',user:'asyncara',resp3:true,tls:true,caFile:getenv('ASYNCARA_SERVER_REDIS_CA'))->await();
            $value=$db->prepare($pg?'SELECT $1::int AS n':'SELECT ? AS n')->execute([$id])->await()[0]['n'];
            $db->query('CREATE TEMPORARY TABLE IF NOT EXISTS asyncara_server_tx(n int)')->await();
            $db->query('DELETE FROM asyncara_server_tx')->await();
            $db->transaction(fn($tx)=>$tx->query($pg?'INSERT INTO asyncara_server_tx VALUES($1)':'INSERT INTO asyncara_server_tx VALUES(?)',[$id])->await())->await();
            try{$db->transaction(function($tx):never{$tx->query('DELETE FROM asyncara_server_tx')->await();throw new DomainException('rollback');})->await();}catch(DomainException){}
            demand((int)$db->query('SELECT n FROM asyncara_server_tx')->await()[0]['n']===$id,'Transaction affinity/rollback');
            $file->await();$bytes=Files::read($path)->await();
            demand($bytes===str_repeat((string)$id,512)&&$reply->await()->body==='ready','Handler file/HTTP integrity');
            $key='asyncara:server:'.$id;
            $values=$redis->pipeline([['SET',$key,$bytes],['GET',$key],['DEL',$key]])->await();
            demand($values[0]==='OK'&&$values[1]===$bytes&&$values[2]===1,'Redis TLS/ACL RESP3 pipeline');
            return ['id'=>(int)$value,'bytes'=>strlen($bytes),'http'=>true,'transaction'=>true,'redis'=>true];
        }finally{
            $redis?->close();$redis?->close();
            Async::shield(function()use($file,$reply,$path):void{
                foreach([$file,$reply]as$f){if(!$f->isCompleted())$f->cancel();try{$f->await();}catch(Throwable){}}
                if(Files::exists($path)->await())Files::delete($path)->await();
            });
        }
    });
    $completed++;return Response::json($result);
});
$app->get('/pending/{mode}',function(Request $request)use($class,$options,$pg,&$ticks):Response{
    $mode=$request->param('mode');demand(in_array($mode,['release','cancel','timeout'],true),'Mode');
    $owner=$class::connect($options,timeout:5)->await();$db=$class::connect($options,timeout:$mode==='timeout'?.1:5)->await();
    $lock=$pg?'SELECT pg_advisory_lock(714209) AS n':"SELECT GET_LOCK('asyncara_server_ownership',5) AS n";
    $unlock=$pg?'SELECT pg_advisory_unlock(714209)':"SELECT RELEASE_LOCK('asyncara_server_ownership')";
    try{
        $owner->query($lock)->await();$pending=$db->query($lock);
        for($i=0;$i<2;$i++){Async::sleep(.002);demand(!$pending->isCompleted(),'Query ended before independent lock release');$ticks++;}
        $source=new CancellationSource();$waiter=Async::spawn(fn()=>$pending->await($source->token));Async::sleep(0);$source->cancel();
        try{$waiter->await();throw new RuntimeException('Waiter cancellation lost');}catch(CancelledException){}
        demand(!$pending->isCompleted(),'Shared waiter destroyed query');
        if($mode==='release'){$owner->query($unlock)->await();$pending->await();$db->query($unlock)->await();}
        else{
            if($mode==='cancel')$pending->cancel();
            $caught=null;try{$pending->await();}catch(Throwable $e){$caught=$e;}
            demand($mode==='cancel'?$caught instanceof CancelledException:$caught instanceof TimeoutException,'Owner cancellation/deadline');
            $owner->query($unlock)->await();
        }
    }finally{$db->close();$db->close();$owner->close();}
    return Response::json(['mode'=>$mode,'ticksWhilePending'=>2]);
});
$app->get('/failure',function()use($pool):Response{
    return $pool->withConnection(function($db):never{$db->query('SELECT 1')->await();throw new DomainException('controlled handler failure');});
});
$app->get('/stop',function()use($app,$pool,&$created,&$ticks,&$completed):Response{
    $stats=$pool->statistics();demand($stats['borrowers']===0&&$stats['availableSlots']===4,'Borrower retained');
    $pool->close();$pool->close();$app->stop();
    return Response::json(['created'=>$created,'ticks'=>$ticks,'completed'=>$completed,'pool'=>$pool->statistics()]);
});
$app->listen('127.0.0.1:0',['connections'=>64,'timeoutMs'=>10000],static function($address):void{echo "READY $address\n";flush();});
gc_collect_cycles();$n=asyncara_info();$d=Runtime::diagnostics();
demand(!$n['pendingOperations']&&!$n['nativeMemoryBytes']&&!$n['openFileHandles']&&!Future::pendingCount(),'Handler native ownership');
foreach(['activeTasks','pendingFutures','activeTimers','ioWatchers']as$key)demand($d[$key]===0,'Handler scheduler owner '.$key);
echo json_encode(['status'=>'PASS','native'=>$n,'runtime'=>$d]),"\n";
