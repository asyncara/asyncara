<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/autoload.php';
use Asyncara\{Async, Future, Runtime};
use Asyncara\Concurrency\Event;
use Asyncara\Exception\CancelledException;
function check(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
function fails(callable $body, string $type): void {
    try { $body(); } catch (Throwable $e) { check($e instanceof $type, 'Unexpected exception '.$e::class.': '.$e->getMessage()); return; }
    throw new RuntimeException('Expected '.$type);
}
$pg = getenv('ASYNCARA_PG_MTLS_FIXTURE'); $my = getenv('ASYNCARA_MY_MTLS_FIXTURE');
$class = $pg ? Asyncara\PostgreSQL\Connection::class : ($my ? Asyncara\MySQL\Connection::class : Asyncara\SQLite\Connection::class);
if (($argv[1] ?? '') === '--api') {
    foreach ([Asyncara\PostgreSQL\Connection::class, Asyncara\MySQL\Connection::class, Asyncara\SQLite\Connection::class] as $api) {
        foreach (['beginTransaction','commit','rollback','inTransaction','savepoint','rollbackTo','releaseSavepoint'] as $method) {
            check(method_exists($api,$method), "$api::$method absent");
        }
    }
    echo "API PASS\n"; exit;
}
$fixture = $pg ?: $my;
$options = $fixture ? json_decode(file_get_contents($fixture), true, flags:JSON_THROW_ON_ERROR)['options'] : [];
$socket = (bool)$fixture; $ticks=0; $checks=[];
Async::run(function () use ($class,$options,$socket,&$ticks,&$checks): void {
    $connect=fn() => $socket ? $class::connect($options,timeout:2)->await() : $class::open(':memory:')->await();
    $close=static function ($db): void { $f=$db->close(); if($f instanceof Future)$f->await(); };
    $db=$connect();
    try {
        $db->query('CREATE TEMPORARY TABLE tx_manual(n int)')->await();
        check(!$db->inTransaction(),'Unexpected initial transaction');
        $db->beginTransaction()->await(); check($db->inTransaction(),'Transaction not active');
        fails(fn()=>$db->beginTransaction(),LogicException::class);
        $db->query('INSERT INTO tx_manual VALUES(1)')->await();
        $db->savepoint('select')->await(); // quoted reserved identifier
        $long = str_repeat('x', 63); $db->savepoint($long)->await(); $db->releaseSavepoint($long)->await();
        $db->query('INSERT INTO tx_manual VALUES(2)')->await();
        $db->rollbackTo('select')->await(); $db->releaseSavepoint('select')->await();
        foreach (['', 'x;COMMIT', 'a b', str_repeat('x',64), "x\0"] as $name) fails(fn()=>$db->savepoint($name),ValueError::class);
        $foreign=Async::spawn(function()use($db):array {
            fails(fn()=>$db->commit(),LogicException::class);
            fails(fn()=>$db->savepoint('foreign'),LogicException::class);
            return $db->query('SELECT n FROM tx_manual')->await();
        });
        for($i=0;$i<2;$i++){Async::sleep(.002);check(!$foreign->isCompleted(),'Foreign query entered transaction');$ticks++;}
        $waiter=Async::spawn(fn()=>$foreign->await());Async::sleep(0);$waiter->cancel();
        fails(fn()=>$waiter->await(),CancelledException::class);check(!$foreign->isCompleted(),'Waiter cancelled foreign operation');
        $db->commit()->await();check(!$db->inTransaction(),'Commit did not clear state');
        check(array_map('intval',array_column($foreign->await(),'n'))===[1],'Savepoint/commit/foreign query state');
        $db->beginTransaction()->await();$db->query('DELETE FROM tx_manual')->await();$db->rollback()->await();
        check(count($db->query('SELECT n FROM tx_manual')->await())===1,'Rollback lost row');
        $checks[]='manual-savepoints-affinity-two-ticks-shared-waiter-rollback';
        $db->transaction(function($tx):void {
            check($tx->inTransaction(),'Callback state');
            fails(fn()=>$tx->transaction(fn()=>null),LogicException::class);
            $tx->savepoint('callback')->await();$tx->query('INSERT INTO tx_manual VALUES(3)')->await();$tx->rollbackTo('callback')->await();
        })->await();
        check(!$db->inTransaction()&&count($db->query('SELECT n FROM tx_manual')->await())===1,'Callback savepoint state');
        $checks[]='callback-savepoints-nested-rejection';
        $db->beginTransaction()->await();$a=$db->commit();$b=$db->commit();$a->await();fails(fn()=>$b->await(),LogicException::class);
        check(!$db->inTransaction()&&count($db->query('SELECT n FROM tx_manual')->await())===1,'Duplicate finish damaged connection');
        $checks[]='duplicate-finish-rejected-before-native-submit';
        if($socket){
            $begin=$db->beginTransaction();fails(fn()=>$db->beginTransaction(),LogicException::class);$begin->cancel();fails(fn()=>$begin->await(),CancelledException::class);
            $db->beginTransaction()->await();$commit=$db->commit();$commit->cancel();fails(fn()=>$commit->await(),CancelledException::class);
            check($db->inTransaction(),'Unstarted cancelled commit changed transaction');$db->rollback()->await();
            $checks[]='prestart-cancellation-duplicate-finish';
        }
    }finally{$close($db);}
    if($socket){
        foreach(['return','cancel','error','close']as$mode){
            $db=$connect();$ready=new Event();$hold=new Event();
            $owner=Async::spawn(function()use($db,$ready,$hold,$mode):void {
                $db->beginTransaction()->await();$ready->set();
                if($mode==='return')return;
                if($mode==='error'){try{$db->query('SELECT * FROM nonexistent_asyncara_table')->await();}catch(Throwable){}return;}
                $hold->wait();
            });
            $ready->wait();
            $queued = null;
            if(in_array($mode,['cancel','close'],true)) {
                $queued=$db->query('SELECT 1');Async::sleep(.002);check(!$queued->isCompleted(),'Foreign query bypassed owner');
            }
            if($mode==='cancel')$owner->cancel();
            if($mode==='close'){$close($db);$hold->set();}
            try{$owner->await();}catch(CancelledException){}
            if($queued)fails(fn()=>$queued->await(),LogicException::class);
            check(!$db->inTransaction(),'Abandoned transaction survived '.$mode);
            fails(fn()=>$db->query('SELECT 1')->await(),LogicException::class);$close($db);
        }
        $db=$connect();$begin=$db->beginTransaction();$close($db);fails(fn()=>$begin->await(),LogicException::class);
        fails(fn()=>$db->beginTransaction()->await(),LogicException::class);fails(fn()=>$db->query('SELECT 1')->await(),LogicException::class);
        $checks[]='owner-return-cancel-query-error-explicit-close-and-close-before-start';
    }
});
gc_collect_cycles();$metrics=Runtime::diagnostics();
foreach(['activeTasks','activeTimers','ioWatchers','pendingNative']as$key)check($metrics[$key]===0,'Leaked '.$key);
echo json_encode(['status'=>'PASS','driver'=>$class,'checks'=>$checks,'ticks'=>$ticks,'runtime'=>$metrics],JSON_THROW_ON_ERROR),"\n";
