<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Asyncara\{Async,Future,Runtime};
use Asyncara\IO\{File,Files};
use Asyncara\Stream\AsyncStream;
use Asyncara\SQLite\Connection;

function demand(bool $ok,string $why):void { if (!$ok) throw new RuntimeException($why); }
$root=sys_get_temp_dir().'/asyncara-paths-'.bin2hex(random_bytes(8));
$cwd=getcwd();mkdir($root,0700);mkdir($root.'/a');mkdir($root.'/b');mkdir($root.'/b/sub');
symlink($root.'/b/sub',$root.'/a/link');$cases=0;
try {
    Async::run(function()use($root,&$cases):void {
        foreach (['','bad' . "\0" . 'name',str_repeat('x',4096)] as $bad) {
            try { asyncara_file_path($bad); throw new RuntimeException('Invalid path accepted'); } catch (ValueError) {}
        }
        for ($round=0;$round<20;$round++) {
            foreach (['native-read','open','stage','exists','read-stream','write-stream','sqlite'] as $mode) {
                file_put_contents($root.'/a/value','first');file_put_contents($root.'/b/value','second');
                file_put_contents($root.'/a/only','present');chdir($root.'/a');
                $operation=match($mode) {
                    'native-read'=>Files::read('value'), 'open'=>File::open('value'),
                    'stage'=>File::stage('target'), 'exists'=>Files::exists('only'),
                    'read-stream'=>Files::readStream('value',2),
                    'write-stream'=>Files::writeStream('value',['new']),
                    'sqlite'=>Connection::open('db.sqlite'),
                };
                chdir($root.'/b');
                if ($mode==='open') {
                    $file=$operation->await();
                    try { demand($file->read(20)->await()==='first','Open changed directory'); }
                    finally { $file->close()->await(); }
                } elseif ($mode==='stage') {
                    $file=$operation->await();
                    try { $file->write('new')->await();$file->publish()->await(); }
                    finally { $file->close()->await(); }
                    demand(file_get_contents($root.'/a/target')==='new'&&!file_exists($root.'/b/target'),'Stage changed directory');
                } elseif ($mode==='read-stream') {
                    $result='';try { foreach($operation as $part)$result.=$part; } finally { $operation->close(); }
                    demand($result==='first','Read stream changed directory');
                } elseif ($mode==='write-stream') {
                    demand($operation->await()===3,'Write length');
                    demand(file_get_contents($root.'/a/value')==='new'&&file_get_contents($root.'/b/value')==='second','Write changed another file');
                } elseif ($mode==='sqlite') {
                    $db=$operation->await();
                    try { $db->execute('CREATE TABLE IF NOT EXISTS sample (id INTEGER)')->await(); }
                    finally { $db->close()->await(); }
                    demand(file_exists($root.'/a/db.sqlite')&&!file_exists($root.'/b/db.sqlite'),'SQLite changed directory');
                } elseif ($mode==='exists') demand($operation->await(),'Exists changed directory');
                else demand($operation->await()==='first','Immediate native path changed');
                demand(asyncara_info()['openFileHandles']===0,'Retained file handle');$cases++;
            }
        }
        chdir($root.'/a');
        // Do not normalize link/.. lexically: POSIX traverses the link first.
        $file=File::open('link/../value')->await();
        try { demand($file->read(20)->await()==='second','Symlink/dot semantics changed'); }
        finally { $file->close()->await(); }
        // Ordinary file names retain legal newline/colon bytes; TLS names are stricter.
        file_put_contents("line\n:name",'local');
        $file=File::open("line\n:name")->await();
        try { demand($file->read(20)->await()==='local','Local filename bytes changed'); }
        finally { $file->close()->await(); }
        $db=Connection::open(':memory:')->await();
        try { demand($db->query('SELECT 7 AS value')->await()[0]['value']===7,'SQLite memory name captured as file'); }
        finally { $db->close()->await(); }
        $producer=AsyncStream::fromProducer(static fn($out)=>$out->emit('data'));
        try { Files::writeStream('', $producer); throw new RuntimeException('Empty stream path accepted'); }
        catch(ValueError) {}
        demand($producer->completion()->isCompleted(),'Rejected path abandoned a producer');
    });
    gc_collect_cycles();$native=asyncara_info();$d=Runtime::diagnostics();
    demand(!$native['openFileHandles']&&!$native['pendingOperations']&&!$native['nativeMemoryBytes']&&!Future::pendingCount()&&!$d['activeTasks']&&!$d['activeTimers']&&!$d['ioWatchers'],'Path capture retained owners');
    echo json_encode(['status'=>'PASS','cases'=>$cases,'backend'=>$native['eventLoopBackend'],'php'=>PHP_VERSION,'nativeBytes'=>$native['nativeMemoryBytes']]),"\n";
} finally {
    chdir($cwd);unlink($root.'/a/link');
    foreach(['a','b']as$dir){foreach(glob($root.'/'.$dir.'/*')as$file)if(is_file($file))unlink($file);}
    rmdir($root.'/b/sub');rmdir($root.'/a');rmdir($root.'/b');rmdir($root);
}
