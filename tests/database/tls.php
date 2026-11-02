<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Future};
use Asyncara\PostgreSQL\Connection as Pg;
use Asyncara\MySQL\Connection as Maria;
use Asyncara\Redis\Connection as Redis;
use Asyncara\Exception\CancelledException;
use Asyncara\Pool\ConnectionPool;
$config=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function fails(callable $body,string $why,?string $pattern=null):void{try{$body();}catch(Throwable $error){if($pattern!==null&&!preg_match($pattern,$error->getMessage()))throw new RuntimeException('Wrong failure category: '.$why,0,$error);return;}throw new RuntimeException('Expected rejection: '.$why);}
function responsive(Future $operation,string $driver):mixed{
    $ticks=0;$ticker=Async::spawn(function()use($operation,&$ticks):void{while(!$operation->isCompleted()){Async::sleep(.005);if(!$operation->isCompleted())$ticks++;}});
    try{$value=$operation->await();}finally{$ticker->cancel();try{$ticker->await();}catch(CancelledException){}}
    check($ticks>=2,$driver.' blocked the event loop');echo json_encode(['driver'=>$driver,'ticksWhileTlsQueryPending'=>$ticks]),"\n";return$value;
}
Async::run(function()use($config):void{
    $pg=['host'=>'localhost','hostaddr'=>'127.0.0.1','port'=>(string)$config['ports']['postgres'],'dbname'=>'postgres','user'=>'asyncara','password'=>$config['password'],'sslrootcert'=>$config['ca']];
    $my=['host'=>'127.0.0.1','port'=>$config['ports']['maria'],'database'=>'asyncara','user'=>'asyncara','password'=>$config['password'],'caFile'=>$config['ca']];
    $connectRedis=fn($ca,$password)=>Redis::connect('127.0.0.1',$config['ports']['redis'],password:$password,user:'asyncara',resp3:true,tls:true,caFile:$ca,timeout:2)->await();
    if($config['phase']==='mismatch'){
        fails(fn()=>Pg::connect($pg)->await(),'PostgreSQL hostname mismatch','/certificate.*match|match.*host/i');
        fails(fn()=>Maria::connect($my)->await(),'MariaDB IP SAN mismatch','/identity mismatch/i');
        fails(fn()=>$connectRedis($config['ca'],$config['password']),'Redis IP SAN mismatch','/certificate verification/i');
        echo "All three drivers rejected trusted certificates with wrong names\n";return;
    }
    $trace=null;
    if(getenv('ASYNCARA_TLS_TRACE'))$trace=Async::repeat(.5,static function():void{
        $scheduler=Asyncara\Scheduler::current();$tasks=(new ReflectionProperty($scheduler,'tasks'))->getValue($scheduler);$stacks=[];
        foreach($tasks as$task)if($task->fiber->isSuspended())$stacks[$task->id]=(new ReflectionFiber($task->fiber))->getTrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        echo json_encode(['seconds'=>hrtime(true)/1e9,'stacks'=>$stacks]),"\n";
    });
    fails(fn()=>Pg::connect(array_replace($pg,['sslrootcert'=>$config['untrustedCa']]))->await(),'PostgreSQL untrusted CA','/certificate verify failed/i');
    fails(fn()=>Pg::connect(array_replace($pg,['password'=>'invalid-test-password']))->await(),'PostgreSQL SCRAM bad password','/password authentication failed/i');
    fails(fn()=>Maria::connect(array_replace($my,['caFile'=>$config['untrustedCa']]))->await(),'MariaDB untrusted CA','/certificate chain verification failed/i');
    fails(fn()=>Maria::connect(array_replace($my,['password'=>'invalid-test-password']))->await(),'MariaDB bad password','/Access denied/i');
    fails(fn()=>$connectRedis($config['untrustedCa'],$config['password']),'Redis untrusted CA','/certificate verification/i');
    fails(fn()=>$connectRedis($config['ca'],'invalid-test-password'),'Redis ACL bad password','/WRONGPASS/i');
    foreach(['PostgreSQL'=>fn()=>Pg::connect($pg)->await(),'MariaDB'=>fn()=>Maria::connect($my)->await()]as$name=>$connect){
        $db=$connect();echo json_encode(['driver'=>$name==='MariaDB'?($config['mysqlLabel']??$name):$name,'serverVersion'=>$db->query('SELECT version() AS version')->await()[0]['version']]),"\n";
        try{
            if($name==='PostgreSQL')check($db->query('SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()')->await()[0]['ssl']==='t','PostgreSQL connection is not TLS');
            else check($db->query("SHOW STATUS LIKE 'Ssl_cipher'")->await()[0]['Value']!=='','MariaDB connection is not TLS');
            responsive($db->query($name==='PostgreSQL'?'SELECT pg_sleep(.15)':'SELECT sleep(.15)'),$name==='MariaDB'?($config['mysqlLabel']??$name):$name);
            $parameter=$name==='PostgreSQL'?'$1':'?';
            $db->query('CREATE TEMPORARY TABLE asyncara_tls_test (n INTEGER)')->await();
            $db->transaction(fn($c)=>$c->query('INSERT INTO asyncara_tls_test VALUES ('.$parameter.')',[11])->await())->await();
            fails(fn()=>$db->transaction(function($c):never{$c->query('DELETE FROM asyncara_tls_test')->await();throw new DomainException('rollback');})->await(),$name.' rollback write');
            check($db->query('SELECT n FROM asyncara_tls_test')->await()[0]['n']==='11',$name.' transaction write/rollback');
            check($db->transaction(fn($c)=>$c->query('SELECT '.$parameter.' AS n',[42])->await())->await()[0]['n']==='42',$name.' bound transaction');
            fails(fn()=>$db->transaction(function($c):never{$c->query('SELECT 1')->await();throw new DomainException('rollback');})->await(),$name.' rollback');
            check($db->query('SELECT 7 AS n')->await()[0]['n']==='7',$name.' rollback connection usability');
            $pending=$db->query($name==='PostgreSQL'?'SELECT pg_sleep(2)':'SELECT sleep(2)');Async::sleep(.02);$pending->cancel();
            try{$pending->await();throw new RuntimeException('Cancellation ignored');}catch(CancelledException){}
            fails(fn()=>$db->query('SELECT 1')->await(),$name.' cancelled connection must be closed');
        }finally{$db->close();}
        $fresh=$connect();try{check($fresh->query('SELECT 9 AS n')->await()[0]['n']==='9',$name.' explicit reconnect');}finally{$fresh->close();}
        $pool=new ConnectionPool($connect,static fn($c)=>$c->close(),size:2);
        try{
            $tasks=[];for($i=0;$i<4;$i++)$tasks[]=Async::spawn(fn()=>$pool->withConnection(fn($c)=>$c->query($name==='PostgreSQL'?'SELECT pg_sleep(.03)':'SELECT sleep(.03)')->await()));
            responsive(Async::spawn(fn()=>Async::gather($tasks)),$name.' pool');
            check($pool->statistics()['borrowers']===0,$name.' leaked pool lease');
        }finally{$pool->close();}
        $deadline=$connect();
        try{
            fails(fn()=>Async::timeout(.02,fn()=>Async::await($deadline->query($name==='PostgreSQL'?'SELECT pg_sleep(2)':'SELECT sleep(2)'))),$name.' deadline','/deadline exceeded/');
            fails(fn()=>$deadline->query('SELECT 1')->await(),$name.' deadline must discard connection','/closed/');
        }finally{$deadline->close();}
        echo $name," pool and deadline cleanup: PASS\n";
    }
    $redis=$connectRedis($config['ca'],$config['password']);$subscriber=$connectRedis($config['ca'],$config['password']);
    try{
        check($redis->pipeline([['PING'],['ECHO','tls']])->await()===['PONG','tls'],'Redis TLS pipeline');
        check($redis->transaction([['SET','asyncara:tls','ok'],['GET','asyncara:tls']])->await()===['OK','ok'],'Redis TLS transaction');
        $subscriber->subscribe(['asyncara:tls-events'])->await();$redis->command(['PUBLISH','asyncara:tls-events','message'])->await();
        check($subscriber->nextMessage()[2]==='message','Redis ACL Pub/Sub');
        responsive($redis->command(['BLPOP','asyncara:tls-empty',.15]),'Redis');
        $pending=$redis->command(['BLPOP','asyncara:tls-empty',2]);Async::sleep(.02);$pending->cancel();
        try{$pending->await();throw new RuntimeException('Redis cancellation ignored');}catch(CancelledException){}
        fails(fn()=>$redis->command(['PING'])->await(),'Redis cancelled connection closed');
    }finally{$redis->close();$subscriber->close();}
    for($i=0;$i<(int)(getenv('ASYNCARA_TLS_ITERATIONS')?:10);$i++){
        echo 'Redis connect iteration ',$i,"\n";
        $fresh=$connectRedis($config['ca'],$config['password']);
        echo 'Redis ping iteration ',$i,"\n";
        try{
            check($fresh->command(['PING'])->await()==='PONG','Redis explicit reconnect');
            echo 'Redis cancellation iteration ',$i,"\n";
            $pending=$fresh->command(['BLPOP','asyncara:tls-empty',60]);Async::sleep(.02);$pending->cancel();
            try{$pending->await();throw new RuntimeException('Redis cancellation ignored');}catch(CancelledException){}
            echo 'Redis cancelled iteration ',$i,"\n";
        }finally{$fresh->close();}
    }
    if(($config['mysqlLabel']??'')==='Oracle MySQL'){
        $legacy=Maria::connect(array_replace($my,['user'=>'asyncara_sha256']))->await();
        try{check($legacy->query('SELECT 42 AS n')->await()[0]['n']==='42','Oracle sha256_password');}finally{$legacy->close();}
        echo "Oracle caching_sha2_password and sha256_password over verified TLS: PASS\n";
    }
    $trace?->cancel();
});
echo "TLS, authentication, transactions, cancellation and explicit reconnect: PASS\n";
