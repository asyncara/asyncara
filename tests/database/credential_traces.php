<?php
declare(strict_types=1);
// Synthetic credentials only. Never print arguments or captured trace contents.
require dirname(__DIR__, 2).'/autoload.php';
use Asyncara\{Async,Future};
use Asyncara\Database\Database;
use Asyncara\PostgreSQL\Connection as Pg;
use Asyncara\MySQL\Connection as My;
use Asyncara\Redis\Connection as Redis;
ini_set('zend.exception_ignore_args', '0');
function options(): array { return ['host'=>'not-a-numeric-address', 'password'=>'asyncara-synthetic-trace-secret']; }
function check(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
function unprotectedControl(string $value): never { throw new DomainException('control'); }
try { unprotectedControl('asyncara-synthetic-trace-secret'); }
catch(DomainException $e) { check(str_contains(print_r($e->getTrace(),true),'asyncara-synthetic-trace-secret'),'Trace argument control is disabled'); }
$cases = [
    'postgres-public-validation'=>[ValueError::class,static fn()=>Pg::connect(options(), notificationLimit:0)],
    'postgres-public-no-runtime'=>[LogicException::class,static fn()=>Pg::connect(options())],
    'mysql-public-no-runtime'=>[LogicException::class,static fn()=>My::connect(options())],
    'factory-unknown-driver'=>[LogicException::class,static fn()=>Database::connectAsync(['driver'=>'unknown']+options())],
    'redis-public-no-runtime'=>[LogicException::class,static fn()=>Redis::connect(password:'asyncara-synthetic-trace-secret')],
];
foreach(['postgres'=>'asyncara_pg_open','mysql'=>'asyncara_mysql_open']as$name=>$native)if(function_exists($native)){
    $cases[$name.'-native-validation']=[ValueError::class,static fn()=>$native(options())];
    $cases[$name.'-native-type']=[TypeError::class,static fn()=>$native('asyncara-synthetic-trace-secret')];
}
if(function_exists('asyncara_pg_open'))$cases['postgres-key-password']=[ValueError::class,static fn()=>asyncara_pg_open(['host'=>'127.0.0.1','sslpassword'=>'asyncara-synthetic-trace-secret'])];
$rows=[];
foreach ($cases as $name=>[$expected,$call]) {
    $caught=null;
    try { $call(); }
    catch (Throwable $e) { $caught=$e;
        $text=(string)$e."\n".print_r($e->getTrace(),true);
        $rows[]=['case'=>$name,'exception'=>$e::class,'exposed'=>str_contains($text,'asyncara-synthetic-trace-secret')];
    }
    check($caught instanceof $expected,'Expected exception absent/wrong: '.$name);
}
// The native entry point is also reached from an asynchronous public connection.
Async::run(function()use(&$rows){
    foreach(['postgres'=>static fn()=>Pg::connect(['hostaddr'=>'invalid']+options())->await(),
             'mysql'=>static fn()=>My::connect(['host'=>'127.0.0.1','port'=>0]+options())->await()]as$name=>$call) {
        if(!function_exists($name==='postgres'?'asyncara_pg_open':'asyncara_mysql_open'))continue;
        $caught=null;try { $call(); }
        catch(Throwable $e) { $caught=$e;$rows[]=['case'=>$name.'-async','exception'=>$e::class,'exposed'=>str_contains((string)$e."\n".print_r($e->getTrace(),true),'asyncara-synthetic-trace-secret')]; }
        check($caught instanceof ValueError,'Expected async validation failure absent/wrong: '.$name);
    }
});
gc_collect_cycles();
echo json_encode(['cases'=>$rows,'pending'=>asyncara_info()['pendingOperations'],'futures'=>Future::pendingCount()],JSON_THROW_ON_ERROR),"\n";
foreach($rows as$row)check(!$row['exposed'],'Credential exposed: '.$row['case']);
check(asyncara_info()['pendingOperations']===0&&Future::pendingCount()===0,'Pending resource retained');
echo "PASS credential trace boundaries\n";
