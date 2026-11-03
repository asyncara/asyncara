<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
require __DIR__.'/resp_harness.php';
use Asyncara\{Async,Scheduler};
use Asyncara\Internal\IpcFrame;
use Asyncara\Exception\{CapacityException,WorkerException,OperationException};
use Asyncara\HTTP\Response;
$seconds=(float)($argv[1]??10);$maximum=(int)($argv[2]??1000000);$seed=(int)($argv[3]??20261102);
if($seconds<=0 || $maximum<1)throw new ValueError('Positive duration and input count required');
$corpus=array_map(file_get_contents(...),glob(__DIR__.'/corpus/resp/*.resp'));
array_push($corpus,"HTTP/1.1 200 OK\r\nX-Test: a\r\nX-Test: b\r\n\r\n",'{"id":1,"value":[null,true,17,"text"]}',"\0\0\0\x10");
mt_srand($seed);$start=Scheduler::now();$count=0;$accepted=['resp'=>0,'ipc'=>0,'headers'=>0];$rejected=['resp'=>0,'ipc'=>0];
set_error_handler(static function(int $severity,string $message,string $file,int $line):never{throw new ErrorException($message,0,$severity,$file,$line);});
try{Async::run(function()use($seconds,$maximum,$corpus,$start,&$count,&$accepted,&$rejected):void{
    while($count<$maximum && Scheduler::now()-$start<$seconds){
        $wire=$corpus[mt_rand(0,count($corpus)-1)];
        for($mutation=0,$n=mt_rand(1,4);$mutation<$n;$mutation++){
            $at=mt_rand(0,strlen($wire));
            $wire=match(mt_rand(0,3)){
                0=>substr($wire,0,$at),
                1=>substr($wire,0,$at).chr(mt_rand(0,255)).substr($wire,$at+1),
                2=>substr($wire,0,$at).str_repeat(chr(mt_rand(0,255)),mt_rand(1,32)).substr($wire,$at),
                3=>str_replace(['0','1'],['999999999999999999999','-1'],$wire),
            };
            if(strlen($wire)>131072)$wire=substr($wire,0,131072);
        }
        try{parseResp($wire,mt_rand(0,strlen($wire)));$accepted['resp']++;}catch(OperationException){$rejected['resp']++;}
        try{IpcFrame::length(substr($wire,0,4));IpcFrame::decode(substr($wire,4));$accepted['ipc']++;}catch(WorkerException|CapacityException|JsonException|ValueError){$rejected['ipc']++;}
        new Response(200,'',substr($wire,0,65536));$accepted['headers']++;
        // Also exercise JSON's actual entrypoint; random binary prefixes seldom
        // pass the frame length check and would otherwise hide decoder coverage.
        try{IpcFrame::decode($wire);$accepted['ipc']++;}catch(WorkerException|CapacityException|JsonException|ValueError){$rejected['ipc']++;}
        $count++;
    }
});}finally{restore_error_handler();}
echo json_encode(['seed'=>$seed,'inputs'=>$count,'seconds'=>Scheduler::now()-$start,'accepted'=>$accepted,'rejected'=>$rejected,'php'=>PHP_VERSION,'peakPhpBytes'=>memory_get_peak_usage(true)],JSON_PRETTY_PRINT),"\n";
