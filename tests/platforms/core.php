<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Runtime};
use Asyncara\IO\Files;
function verify(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$dir=sys_get_temp_dir().'/asyncara-platform-'.bin2hex(random_bytes(8));
Async::run(static function()use($dir):void {
    Files::mkdir($dir)->await();
    try {
        $data=str_repeat("\0platform-data\n",100000);
        $jobs=[];
        for($i=0;$i<12;$i++)$jobs[]=Async::spawn(static function()use($dir,$i,$data):void{
            $path="$dir/worker-$i";
            Files::write($path,$data)->await();
            verify(Files::read($path)->await()===$data,'large file roundtrip');
            Files::replaceAtomic($path,'replacement')->await();
            verify(Files::read($path)->await()==='replacement','atomic replacement');
            Files::delete($path)->await();
        });
        Async::gather($jobs);
        verify(Files::entries($dir)->await()===[],'staging or worker files leaked');
    }finally{Files::rmdir($dir)->await();}
});
$i=Runtime::information();
foreach(['pendingOperations','openFileHandles','nativeMemoryBytes']as$k)verify($i->$k===0,"leaked $k");
echo json_encode(['status'=>'PASS','php'=>PHP_VERSION,'os'=>PHP_OS_FAMILY,'backend'=>$i->eventLoopBackend,'fileJobs'=>12],JSON_THROW_ON_ERROR),"\n";
