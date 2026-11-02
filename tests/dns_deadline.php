<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Asyncara\{Async,Future,Runtime,Scheduler};
use Asyncara\IO\Dns;
use Asyncara\Exception\{TimeoutException,CancelledException};
function ensure(bool $condition,string $why):void{if(!$condition)throw new RuntimeException($why);}
$ticks=0;$began=Scheduler::now();
Async::run(function()use(&$ticks):void{
    $timer=Async::spawn(function()use(&$ticks):void{for($i=0;$i<15;$i++){Async::sleep(.005);$ticks++;}});
    try{Async::timeout(.02,fn()=>Dns::resolve('asyncara-delay.invalid')->await());throw new RuntimeException('DNS deadline was ignored');}catch(TimeoutException){}
    $lookup=Dns::resolve('asyncara-delay.invalid');$lookup->cancel();
    try{$lookup->await();throw new RuntimeException('DNS cancellation was ignored');}catch(CancelledException){}
    $timer->await();ensure($ticks===15,'DNS blocked event loop timers');
});
ensure(Future::pendingCount()===0&&Runtime::information()->pendingOperations===0,'DNS pending operation retained');
echo 'Worker DNS deadline/cancellation and delayed native cleanup: PASS ',json_encode(['timerTicks'=>$ticks,'elapsed'=>Scheduler::now()-$began]),"\n";
