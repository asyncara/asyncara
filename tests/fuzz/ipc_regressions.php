<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async};
use Asyncara\Pool\ProcessPool;
use Asyncara\Internal\IpcFrame;
use Asyncara\Exception\{CapacityException,WorkerException};
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$bad=["\0\0",pack('N',0),pack('N',16777217),pack('N',4294967295),pack('N',8).'{}',pack('N',1).'{'];
$wrongId=json_encode(['id'=>999,'value'=>7],JSON_THROW_ON_ERROR);$bad[]=pack('N',strlen($wrongId)).$wrongId;
foreach([str_repeat('[',34).'0'.str_repeat(']',34),'['.str_repeat('null,',100001).'null]']as$json){
    try{IpcFrame::decode($json);throw new RuntimeException('Unbounded IPC data accepted');}catch(CapacityException|ValueError){}
}
check(IpcFrame::decode(IpcFrame::encode(['x'=>'\\"[,','n'=>[1,null]]))===['x'=>'\\"[,','n'=>[1,null]],'JSON scan mishandled quoted delimiters');
Async::run(function()use($bad):void{
    $pool=new ProcessPool(1,__DIR__.'/ipc_jobs.php');
    try{foreach($bad as$wire){
        try{$pool->submit('raw',[base64_encode($wire)])->await();throw new RuntimeException('Malformed frame accepted');}catch(WorkerException|JsonException){}
        check($pool->submit('echo',['recovered'])->await()==='recovered','Worker was not replaced');
    }}finally{$pool->close();}
});
echo "IPC malformed/truncated frames, partial reads and worker replacement: PASS\n";
