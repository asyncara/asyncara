<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Trace,Runtime};
use Asyncara\IO\TcpStream;
function check(bool $v,string $message):void { if (!$v) throw new RuntimeException($message); }
function fails(callable $body):void { try { $body(); } catch (Throwable) { return; } throw new RuntimeException('Expected failure'); }
$mode=$argv[1]??'normal'; $start=hrtime(true);
check(Trace::snapshot()['events']===[] && Runtime::information()->nativeDiagnosticBytes===0,'Default diagnostics allocate/emit');
fails(static fn()=>Trace::enable(4097)); fails(static fn()=>Trace::enable(15)); fails(static fn()=>Trace::enable(16,128));
if ($mode==='disabled') {
    Async::run(static function():void{for($i=0;$i<100;$i++)Async::sleep(0);});
    check(Trace::snapshot()['events']===[] && Runtime::information()->nativeDiagnosticBytes===0,'Disabled capture');
} elseif ($mode==='wrap' || $mode==='maximum') {
    $capacity=$mode==='wrap'?16:4096;Trace::enable($capacity,Trace::MARK);
    for($i=0;$i<10000;$i++)Trace::mark($i);
    $snapshot=Trace::snapshot();check(count($snapshot['events'])===$capacity && $snapshot['overwritten']===10000-$capacity,'Ring bound/overwrite');
    check(!$snapshot['complete'] && $snapshot['events'][0][5]===10000-$capacity && $snapshot['events'][$capacity-1][5]===9999,'Ring order');
    Trace::disable();$frozen=Trace::snapshot();Trace::mark(10001);check(Trace::snapshot()===$frozen,'Disabled ring mutated');
    fails(static fn()=>Trace::enable($capacity===16?32:16));
} else {
    Trace::enable(4096);
    Async::run(static function():void{
        fails(static fn()=>Trace::snapshot());
        $pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP);
        $reader=new TcpStream($pair[0]);$writer=new TcpStream($pair[1]);
        try {
            $job=Async::spawn(static fn()=>$reader->read(1));Async::sleep(0);
            for($i=1;$i<=2;$i++){Async::sleep(.005);check(!$job->isCompleted(),'Timer did not progress while I/O pending');Trace::mark($i);}
            $writer->write('x');check($job->await()==='x','Read completion');Trace::mark(3);
            $pending=Async::spawn(static fn()=>$reader->read(1));Async::sleep(0);$pending->cancel();
            fails($pending->await(...));
        } finally {$reader->close();$writer->close();}
    });
    $snapshot=Trace::snapshot();$events=$snapshot['events'];$codes=array_column($events,4);
    foreach([10,11,12,13,30,31,32,33,34,35,36,37,38,40,41,42,43,44,50,51,52,53,58,60,61,80]as$code)
        check(in_array($code,$codes,true),'Missing event '.$code);
    check($snapshot['complete'],'Unexpected trace loss in single-thread kernel control');
    $marks=array_values(array_filter($events,static fn($e)=>$e[4]===80));
    check(array_column($marks,5)===[1,2,3],'Timer-before-I/O markers');
    $previous=0;$ns=0;
    foreach($events as$event){check($event[0]>$previous && $event[1]>=$ns,'Sequence/monotonic order');$previous=$event[0];$ns=$event[1];}
    $calls=[];
    foreach($events as$event){if($event[4]===11)$calls[$event[5]]=$event;if($event[4]===12){check(isset($calls[$event[5]])&&$event[8]>=0,'Unpaired kernel wait');unset($calls[$event[5]]);}}
    check(!$calls,'Missing kernel return');
    Async::run(static fn()=>Asyncara\IO\Files::read(__FILE__)->await());
    $jobs=array_column(Trace::snapshot()['events'],4);
    foreach([20,21,22,23,24,26]as$code)check(in_array($code,$jobs,true),'Missing worker event '.$code);
    $full=fopen('/dev/full','wb');try{fails(static fn()=>Trace::writeTo($full));}finally{fclose($full);}
    $path=tempnam(sys_get_temp_dir(),'asyncara-trace-');
    try {fails(static fn()=>Trace::dump($path));unlink($path);Trace::dump($path);$decoded=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);check($decoded['schema']===1 && count($decoded['events'])>0,'Deferred output');} finally {if(file_exists($path))unlink($path);}
    check(in_array(4,array_column(Trace::snapshot()['events'],4),true),'Deferred flush completion missing');
    Trace::mark(99,true);$snapshot=Trace::snapshot();
    check($snapshot['mask']===0 && end($snapshot['events'])[5]===99,'Anomaly freeze');
    check(Runtime::information()->pendingOperations===0 && Runtime::information()->nativeMemoryBytes===0,'Diagnostics altered operation ownership');
    if(isset($argv[2]))Trace::dump($argv[2]);
}
echo json_encode(['status'=>'PASS','mode'=>$mode,'backend'=>Runtime::information()->eventLoopBackend,'seconds'=>(hrtime(true)-$start)/1e9,'snapshot'=>Trace::snapshot()],JSON_THROW_ON_ERROR)."\n";
