<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Asyncara\Async;
use Asyncara\Pool\ProcessPool;
Async::run(function():void {
    $pool=new ProcessPool(4,__DIR__.'/jobs.php');
    try{$jobs=[];for($i=0;$i<4;$i++)$jobs[]=$pool->submit('digest',[500000]);print_r(Async::gather($jobs));}
    finally{$pool->close();}
});
