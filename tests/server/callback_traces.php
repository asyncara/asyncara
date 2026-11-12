<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
ini_set('zend.exception_ignore_args','0');
$secret='asyncara-synthetic-captured-secret';
function control(callable $body):void{$body();}
$body=static function()use($secret):never{throw new DomainException('controlled failure');};
try{control($body);}catch(DomainException $e){
    if(!str_contains(print_r($e->getTrace(),true),$secret))throw new RuntimeException('Unprotected control did not capture arguments');
}
$exposed=[];
foreach(['public'=>static fn()=>Asyncara\Async::run($body),
    'scheduler'=>static fn()=>(new Asyncara\Scheduler(10))->run($body),
    'semaphore'=>static fn()=>Asyncara\Async::run(static fn()=>(new Asyncara\Concurrency\Semaphore(1))->withPermit($body))]as$name=>$call){
    try{$call();throw new RuntimeException('Missing controlled failure');}
    catch(DomainException $e){$exposed[$name]=str_contains(print_r($e->getTrace(),true),$secret);}
}
echo json_encode(['exposed'=>$exposed]),"\n";
if(in_array(true,$exposed,true))throw new RuntimeException('Root callback exposed captured data in exception trace');
echo "PASS root callback trace redaction\n";
