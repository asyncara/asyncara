<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Future};
use Asyncara\MySQL\Connection;
use Asyncara\Pool\ConnectionPool;
use Asyncara\Exception\{CancelledException,TimeoutException};
function demand(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);}
function rejected(callable $body,string $name,?string $message=null):string {
    try{$value=$body();}catch(Throwable $e){
        demand(in_array($e::class,[ValueError::class,Exception::class],true),'Unexpected rejection type: '.$name.': '.$e::class);
        if($message!==null)demand(str_contains($e->getMessage(),$message),'Wrong failure reason: '.$name);
        return $e::class;
    }
    if($value instanceof Connection)$value->close();
    throw new RuntimeException('Invalid mTLS accepted: '.$name);
}
$fixture=json_decode(file_get_contents(getenv('ASYNCARA_MY_MTLS_FIXTURE')),true,flags:JSON_THROW_ON_ERROR);
$cases=[];$ticks=0;$server=[];
Async::run(function()use($fixture,&$cases,&$ticks,&$server):void{
    $base=$fixture['options'];
    if(!$fixture['serverIdentityValid']){
        $cases['wrong-server-ip']=rejected(fn()=>Connection::connect($base,timeout:5)->await(),'wrong-server-ip','identity mismatch');return;
    }
    $db=Connection::connect($base,timeout:5)->await();
    try{
        $server=$db->query('SELECT CURRENT_USER() AS user, VERSION() AS version')->await()[0];
        demand($server['user']==='asyncara@127.0.0.1','Unexpected authenticated identity');
        demand($db->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->await()[0]['Value']!=='','TLS missing');
        if(($GLOBALS['argv'][1]??'')==='probe')return;
        $statement=$db->prepare('SELECT ? AS n');
        demand($statement->execute([37])->await()[0]['n']==='37','Prepared parameter over mTLS');
        demand($statement->execute([38])->await()[0]['n']==='38','Repeated statement over mTLS');
        $owner=Connection::connect($base,timeout:5)->await();
        try{
            demand($owner->query("SELECT GET_LOCK('asyncara_mtls',10) AS ok")->await()[0]['ok']==='1','Independent lock not held');
            $pending=$db->query("SELECT GET_LOCK('asyncara_mtls',10) AS ok");
            for($i=0;$i<2;$i++){Async::sleep(.002);demand(!$pending->isCompleted(),'Query completed before lock release');$ticks++;}
            $waiter=Async::spawn(fn()=>$pending->await());
            Async::sleep(0);$waiter->cancel();
            try{$waiter->await();throw new RuntimeException('Waiter cancellation ignored');}catch(CancelledException){}
            demand(!$pending->isCompleted(),'Cancelling one waiter destroyed the shared query');
            $owner->query("SELECT RELEASE_LOCK('asyncara_mtls')")->await();
            demand($pending->await()[0]['ok']==='1','Pending query lost');
            $db->query("SELECT RELEASE_LOCK('asyncara_mtls')")->await();
        }finally{$owner->close();}
        $db->query('CREATE TEMPORARY TABLE mtls_tx(n int)')->await();
        $db->transaction(fn($tx)=>$tx->query('INSERT INTO mtls_tx VALUES(?)',[42])->await())->await();
        try{$db->transaction(function($tx):never{$tx->query('DELETE FROM mtls_tx')->await();throw new DomainException('rollback');})->await();}
        catch(DomainException){}
        demand($db->query('SELECT n FROM mtls_tx')->await()[0]['n']==='42','Transaction rollback/affinity');
    }finally{$db->close();$db->close();}
    $encrypted=array_replace($base,['keyFile'=>$fixture['encryptedKey'],'keyPassword'=>file_get_contents($fixture['passphraseFile'])]);
    $db=Connection::connect($encrypted,timeout:5)->await();
    try{demand($db->query('SELECT 1 AS n')->await()[0]['n']==='1','Encrypted client key');}finally{$db->close();}
    $invalid=[
        'missing-certificate'=>array_diff_key($base,['certFile'=>1,'keyFile'=>1]),
        'untrusted-client'=>array_replace($base,['certFile'=>$fixture['untrustedCert'],'keyFile'=>$fixture['untrustedKey']]),
        'wrong-client-name'=>array_replace($base,['certFile'=>$fixture['otherCert'],'keyFile'=>$fixture['otherKey']]),
        'mismatched-key'=>array_replace($base,['keyFile'=>$fixture['otherKey']]),
        'untrusted-server'=>array_replace($base,['caFile'=>$fixture['untrustedCa']]),
        'missing-cert-file'=>array_replace($base,['certFile'=>$fixture['missingPath']]),
        'missing-key-file'=>array_replace($base,['keyFile'=>$fixture['missingPath']]),
        'wrong-key-password'=>array_replace($encrypted,['keyPassword'=>'deliberately-wrong-test-value']),
        'missing-key-password'=>array_diff_key($encrypted,['keyPassword'=>1]),
        'empty-key-password'=>array_replace($encrypted,['keyPassword'=>'']),
        'incomplete-pair'=>array_diff_key($base,['keyFile'=>1]),
        'empty-certificate'=>array_replace($base,['certFile'=>'']),
        'empty-key'=>array_replace($base,['keyFile'=>'']),
        'password-without-pair'=>array_diff_key($encrypted,['certFile'=>1,'keyFile'=>1]),
        'plaintext'=>array_replace($base,['tls'=>false]),
        'unix-socket'=>array_replace($base,['socket'=>'/tmp/absent-asyncara-mtls.sock']),
        'wrong-login-password'=>array_replace($base,['password'=>'deliberately-wrong-test-value']),
    ];
    foreach($invalid as$name=>$options)$cases[$name]=rejected(fn()=>Connection::connect($options,timeout:5)->await(),$name);
    $pool=new ConnectionPool(fn()=>Connection::connect($base,timeout:5)->await(),static fn($c)=>$c->close(),size:2);
    try{
        $jobs=[];for($i=0;$i<4;$i++)$jobs[]=Async::spawn(fn()=>$pool->withConnection(fn($c)=>$c->query('SELECT ? AS n',[42])->await()));
        foreach(Async::gather($jobs)as$rows)demand($rows[0]['n']==='42','Pool result ownership');
        demand($pool->statistics()['borrowers']===0,'Pool lease leaked');
    }finally{$pool->close();}
    foreach(['cancel','timeout']as$mode){
        $owner=Connection::connect($base,timeout:5)->await();$db=Connection::connect($base,timeout:5)->await();
        try{
            $owner->query("SELECT GET_LOCK('asyncara_mtls_cancel',10)")->await();
            $query=$db->query("SELECT GET_LOCK('asyncara_mtls_cancel',10)");
            Async::sleep(.002);demand(!$query->isCompleted(),'Requires pending query');
            if($mode==='cancel')$query->cancel();
            $caught=false;
            try{
                if($mode==='timeout')Async::timeout(.02,fn()=>Async::await($query));
                else $query->await();
            }catch(CancelledException $e){$caught=$mode==='cancel'?$e::class===CancelledException::class:$e instanceof TimeoutException;}
            demand($caught,'Missing cancellation/deadline');
            $closed=false;try{$db->query('SELECT 1')->await();}catch(LogicException){$closed=true;}
            demand($closed,'Cancelled/deadline connection reused');
            $owner->query("SELECT RELEASE_LOCK('asyncara_mtls_cancel')")->await();
        }finally{$owner->close();$db->close();}
    }
    $db=Connection::connect($base,timeout:5)->await();try{demand($db->query('SELECT 7 AS n')->await()[0]['n']==='7','Fresh connection after rejected handshakes');}finally{$db->close();}
});
if(($GLOBALS['argv'][1]??'')!=='probe'&&$fixture['serverIdentityValid']){
    ini_set('open_basedir',dirname(__DIR__,2).PATH_SEPARATOR.dirname(getenv('ASYNCARA_MY_MTLS_FIXTURE')));
    $warnings=0;set_error_handler(static function(int $level,string $message)use(&$warnings):bool{
        if($level!==E_WARNING||!str_contains($message,'open_basedir'))return false;$warnings++;return true;
    });
    try{rejected(fn()=>asyncara_mysql_open(array_replace($fixture['options'],['certFile'=>'/outside-asyncara-mtls/client.pem'])),'open_basedir','open_basedir');demand($warnings>0,'Missing path warning');}
    finally{restore_error_handler();}
}
gc_collect_cycles();
demand(asyncara_info()['pendingOperations']===0&&asyncara_info()['nativeMemoryBytes']===0&&Future::pendingCount()===0,'Native/Future owners retained');
echo json_encode(['status'=>'PASS','family'=>$fixture['family'],'server'=>$server,'invalidCases'=>$cases,'ticksWhilePending'=>$ticks,'backend'=>asyncara_info()['eventLoopBackend'],'native'=>asyncara_info()]),"\n";
