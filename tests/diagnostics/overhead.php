<?php
declare(strict_types=1);
require $argv[3]??dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Trace,Runtime};
use Asyncara\IO\TcpStream;
$mask=(int)($argv[1]??0);$iterations=(int)($argv[2]??10000);
if($mask)Trace::enable(4096,$mask);
$before=getrusage();$memory=memory_get_usage(true);$start=hrtime(true);
Async::run(static function()use($iterations):void{
    $p=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP);
    $a=new TcpStream($p[0]);$b=new TcpStream($p[1]);
    try{for($i=0;$i<$iterations;$i++){
        $read=Async::spawn(static fn()=>$a->read(1));Async::sleep(0);
        if($read->isCompleted())throw new RuntimeException('Read must remain pending');
        $b->write('x');if($read->await()!=='x')throw new RuntimeException('Read result');
    }}finally{$a->close();$b->close();}
});
$seconds=(hrtime(true)-$start)/1e9;$after=getrusage();$trace=function_exists('asyncara_trace_snapshot')?Trace::snapshot():[];unset($trace['events']);
echo json_encode(['mask'=>$mask,'iterations'=>$iterations,'seconds'=>$seconds,'cpuSeconds'=>(($after['ru_utime.tv_sec']-$before['ru_utime.tv_sec'])+($after['ru_stime.tv_sec']-$before['ru_stime.tv_sec']))+(($after['ru_utime.tv_usec']-$before['ru_utime.tv_usec'])+($after['ru_stime.tv_usec']-$before['ru_stime.tv_usec']))/1e6,'phpBytesDelta'=>memory_get_usage(true)-$memory,'nativeDiagnosticBytes'=>Runtime::information()->nativeDiagnosticBytes??0,'nativePending'=>Runtime::information()->pendingOperations,'trace'=>$trace],JSON_THROW_ON_ERROR)."\n";
