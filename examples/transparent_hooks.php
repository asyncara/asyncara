<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Asyncara\{Async,Hook,Runtime};
$report=Runtime::enableHooks(Hook::TIME|Hook::FILESYSTEM);
echo json_encode(['enabled'=>$report->enabled(),'unsupported'=>$report->reasons()],JSON_PRETTY_PRINT),"\n";
Async::run(function():void {
    Async::taskGroup(function($group):void {
        for($i=0;$i<5;$i++)$group->spawn(function()use($i):void{usleep(20000);echo "Timer task $i\n";});
    });
});
