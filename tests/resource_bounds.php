<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Asyncara\{Async,Future,Runtime,Scheduler};
use Asyncara\Pool\{ConnectionPool,ProcessPool};
use Asyncara\Exception\{CapacityException,CancelledException};
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejects(string $type, callable $body): void {
    try { $body(); } catch (Throwable $e) { if ($e instanceof $type) return; throw $e; }
    throw new RuntimeException('Expected '.$type);
}
$defaults = Runtime::configuration();
try {
    Runtime::configure(['futureLimit'=>3]);
    $a = new Future(); $b = new Future(); $c = new Future();
    rejects(CapacityException::class, fn()=>new Future());
    rejects(CapacityException::class, fn()=>Async::run(fn()=>null));
    check(Scheduler::tryCurrent()===null, 'Failed startup retained active scheduler');
    $a->resolve(); unset($b, $c);
    check(Future::pendingCount()===0, 'Settled or abandoned Future retained');
    check(Async::run(fn()=>42)===42, 'Runtime did not recover after capacity rejection');
    Async::run(function (): void {
        $a=new Future();$b=new Future();
        $before=Runtime::information()->pendingOperations;
        rejects(CapacityException::class,fn()=>\Asyncara\IO\Files::read(__FILE__));
        check(Runtime::information()->pendingOperations===$before,'Native work submitted without Future capacity');
        $a->resolve();$b->resolve();
    });
    rejects(ValueError::class, fn()=>Runtime::configure(['taskLimit'=>9, 'futureLimit'=>0]));
    check(Runtime::configuration()['taskLimit']===$defaults['taskLimit'], 'Invalid configuration partially applied');
    Runtime::configure($defaults + ['futureLimit'=>40000]);
    Runtime::configure(['taskLimit'=>4]);
    Async::run(function (): void {
        $failures=[];
        for ($i=0;$i<3;$i++) $failures[]=Async::spawn(fn()=>throw new DomainException('unobserved'));
        Async::sleep(0);
        rejects(CapacityException::class, fn()=>Async::spawn(fn()=>null));
        $failures[0]->exception();
        Async::spawn(fn()=>7)->await();
        foreach ($failures as $failure) $failure->exception();
    });
    Runtime::configure($defaults + ['futureLimit'=>40000]);
    Async::run(function (): void {
        $pool=new ConnectionPool(fn()=>(object)[],fn($c)=>null,size:1,pendingLimit:1);
        $hold=new Future();
        $first=Async::spawn(fn()=>$pool->withConnection(fn()=>$hold->await()));
        Async::sleep(0);
        $second=Async::spawn(fn()=>$pool->withConnection(fn()=>2));
        Async::sleep(0);
        rejects(CapacityException::class, fn()=>$pool->withConnection(fn()=>3));
        check($pool->statistics()['borrowers']===2, 'Borrower accounting');
        $hold->resolve(); Async::gather([$first,$second]); $pool->close();
        $process=new ProcessPool(1,__DIR__.'/fixtures/jobs.php',queueBytes:2048);
        try {
            $pending=$process->submit('echo',[str_repeat('a',1800)]);
            rejects(CapacityException::class,fn()=>$process->submit('echo',[str_repeat('b',1800)]));
            check(strlen($pending->await())===1800, 'Accepted frame corrupted');
            check($process->statistics()['pendingBytes']===0, 'Completed frame reservation leaked');
            $cancelled=$process->submit('echo',[str_repeat('c',1800)]); $cancelled->cancel();
            rejects(CancelledException::class,$cancelled->await(...));
            check($process->statistics()['pendingBytes']===0, 'Cancelled frame reservation leaked');
            rejects(CapacityException::class,fn()=>$process->submit('echo',[array_fill(0,100001,null)]));
            check($process->submit('sum',[10])->await()===55,'Pool failed after rejection');
        } finally { $process->close(); }
    });
    check(Future::pendingCount()===0, 'Pending Future accounting after cleanup');
    echo "Resource admission, cancellation, retention and recovery: PASS\n";
} finally { Runtime::configure($defaults); }
