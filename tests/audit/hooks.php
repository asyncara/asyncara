<?php
declare(strict_types=1);
require (getenv('ASYNCARA_AUDIT_SOURCE') ?: dirname(__DIR__,2)).'/autoload.php';
use Asyncara\{Async,Hook,Runtime};
use Asyncara\Exception\CancelledException;
Runtime::enableHooks(Hook::TIME);
foreach(['sleep','usleep']as$name){
 Async::run(function()use($name){
  $ticks=0;$task=Async::spawn(fn()=>$name($name==='sleep'?1:200000));
  $ticker=Async::spawn(function()use($task,&$ticks){while(!$task->isCompleted()){Async::sleep(.005);if(!$task->isCompleted())$ticks++;}});
  $value=$task->await();$ticker->await();
  if($ticks<2||$value!==($name==='sleep'?0:null))throw new RuntimeException('Hook blocked or changed return value');
  foreach([-1,[],1.5]as$invalid){try{$name($invalid);throw new LogicException('Missing parameter error');}catch(ValueError|TypeError){}}
  if(sleep(seconds:0)!==0||usleep(microseconds:0)!==null)throw new RuntimeException('Named argument compatibility');
  $cancel=Async::spawn(fn()=>$name($name==='sleep'?60:60000000));Async::sleep(.01);$cancel->cancel();
  try{$cancel->await();throw new LogicException('Missing cancellation');}catch(CancelledException){}
  echo json_encode(['function'=>$name,'ticksWhilePending'=>$ticks,'php'=>PHP_VERSION]),"\n";
 });
}
Runtime::enableHooks(0);
if(sleep(0)!==0||usleep(0)!==null)throw new RuntimeException('Outside runtime return behavior');
echo "Timer hook audit: PASS\n";
