<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Asyncara\{Async,Runtime};
use Asyncara\IO\Files;

Runtime::configure(['nativeMemoryLimit'=>14*1024*1024]);
$path=tempnam(sys_get_temp_dir(),'asyncara-owned-read-');
try {
    $handle=fopen($path,'wb');
    fwrite($handle,'head');fseek($handle,12*1024*1024-4);fwrite($handle,'tail');fclose($handle);
    $expected=hash_file('sha256',$path);
    Async::run(function()use($path,$expected):void {
        for($i=0;$i<3;$i++) {
            $data=Files::read($path)->await();
            if(strlen($data)!==12*1024*1024||hash('sha256',$data)!==$expected)
                throw new RuntimeException('Native read ownership changed bytes');
        }
    });
    file_put_contents($path,'');
    Async::run(function()use($path):void {
        if(Files::read($path)->await()!=='')throw new RuntimeException('Empty owned buffer');
    });
    $info=Runtime::information();
    if($info->nativeMemoryBytes!==0||$info->nativeMemoryPeak>14*1024*1024||$info->nativeMemoryRejections!==0)
        throw new RuntimeException('Owned file result duplicated or leaked native memory');
    echo "Large file result ownership, content, empty reads and aggregate budget: PASS\n";
}finally{unlink($path);}
