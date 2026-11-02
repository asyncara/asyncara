<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Asyncara\Async;
Async::run(function():void {
    $tasks=[];
    for($i=0;$i<1000;$i++)$tasks[]=Async::spawn(function()use($i):int{Async::sleep(.01);return$i;});
    echo 'Completed '.count(Async::gather($tasks))." tasks\n";
});
