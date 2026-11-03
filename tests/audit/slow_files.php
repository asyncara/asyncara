<?php
declare(strict_types=1);
require (getenv('ASYNCARA_AUDIT_SOURCE') ?: dirname(__DIR__,2)).'/autoload.php';
use Asyncara\{Async,Runtime};
use Asyncara\IO\Files;
$root=sys_get_temp_dir().'/asyncara-path-'.bin2hex(random_bytes(6));mkdir($root);mkdir($root.'/a');mkdir($root.'/b');$cwd=getcwd();$ticks=0;
try {
 Async::run(function()use($root,&$ticks){chdir($root.'/a');$future=Files::write('audit-delay.txt','owned-by-a');
  while(!is_file(getenv('ASYNCARA_AUDIT_MARKER'))){Async::sleep(.001);clearstatcache();}
  chdir($root.'/b');
  while(!$future->isCompleted()){$ticks++;Async::sleep(.005);}
  $future->await();
 });
 $correct=is_file($root.'/a/audit-delay.txt')&&!is_file($root.'/b/audit-delay.txt');
 echo json_encode(['ticksDuringSlowNativeOpen'=>$ticks,'submissionPathPreserved'=>$correct,'pending'=>Runtime::information()->pendingOperations]),"\n";
 if(!$correct||$ticks<5)throw new RuntimeException('Async filesystem path or responsiveness failure');
}finally{chdir($cwd);foreach(['a','b']as$d){foreach(glob($root.'/'.$d.'/*')as$p)unlink($p);rmdir($root.'/'.$d);}rmdir($root);}
