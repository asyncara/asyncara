<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Asyncara\{Async,Future,Runtime};
use Asyncara\IO\{File,Files};
use Asyncara\SQLite\Connection;
use Asyncara\Stream\ReadableStream;
$config=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);$mode=$argv[2];$warnings=0;$checks=0;
function demand(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
set_error_handler(static function(int $level,string $message)use(&$warnings,$mode):bool{
    if($level!==E_WARNING||!str_contains($message,'open_basedir'))return false;
    $warnings++;if($mode==='native-warning')throw new DomainException('Path warning');return true;
});
try{
    Async::run(function()use($config,$mode,&$checks):void{
        chdir($config['allowed']);$path=match($mode){
            'symlink'=>$config['allowed'].'/link',
            'dot'=>$config['allowed'].'/../b/file',
            default=>$config['forbidden'],
        };
        $submit=static fn(string $kind)=>match($kind){
            'open'=>File::open($path),'stage'=>File::stage($path),'exists'=>Files::exists($path),
            'read-stream'=>Files::readStream($path),'write-stream'=>Files::writeStream($path,['data']),
            'sqlite'=>Connection::open($path),
        };
        $kinds=['open','stage','exists','read-stream','write-stream','sqlite'];$pending=[];
        if($mode==='late')foreach($kinds as$kind)$pending[$kind]=$submit($kind);
        ini_set('open_basedir',dirname(__DIR__).PATH_SEPARATOR.$config['allowed']);
        foreach($mode==='native-warning'?['native']:$kinds as$kind){
            for($i=0;$i<($mode==='late'?1:20);$i++){
                try{
                    if($kind==='native')asyncara_file_path($path);
                    else{
                        $operation=$pending[$kind]??$submit($kind);
                        if($operation instanceof ReadableStream){try{$operation->consume(static fn($part)=>null);}finally{$operation->close();}}
                        else $operation->await();
                    }
                    throw new RuntimeException('Path policy escaped');
                }catch(ValueError $e){demand(str_contains($e->getMessage(),'open_basedir'),'Unexpected rejection');}
                catch(DomainException $e){demand($mode==='native-warning'&&$e->getMessage()==='Path warning','Warning exception overwritten');}
                $checks++;
            }
        }
    });
}finally{restore_error_handler();}
gc_collect_cycles();$native=asyncara_info();$d=Runtime::diagnostics();
demand($warnings>0&&!$native['pendingOperations']&&!$native['nativeMemoryBytes']&&!$native['openFileHandles']&&!Future::pendingCount()&&!$d['activeTasks']&&!$d['activeTimers']&&!$d['ioWatchers'],'Path policy cleanup failed');
echo json_encode(['status'=>'PASS','mode'=>$mode,'checks'=>$checks,'warnings'=>$warnings,'php'=>PHP_VERSION,'backend'=>$native['eventLoopBackend']]),"\n";
