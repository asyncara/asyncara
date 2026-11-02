<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Asyncara\{Async,Runtime};
use Asyncara\IO\Files;
use Asyncara\Exception\OperationException;
function expectFailure(string $class,callable $body):void{try{$body();}catch(Throwable $e){if($e instanceof $class)return;throw $e;}throw new RuntimeException('Expected '.$class);}
$path=tempnam(sys_get_temp_dir(),'asyncara-limits-');
try{
 $handle=fopen($path,'wb');ftruncate($handle,16777217);fclose($handle);
 Async::run(function()use($path):void{
  expectFailure(OperationException::class,fn()=>Files::read($path)->await());
  expectFailure(ValueError::class,fn()=>Files::write($path,str_repeat('x',16777217)));
  $futures=[];for($i=0;$i<Runtime::information()->nativeQueueLimit;$i++)$futures[]=Files::read(__FILE__);
  expectFailure(Exception::class,fn()=>Files::read(__FILE__));Async::gather($futures);
  expectFailure(ValueError::class,fn()=>asyncara_poll([-1],[],0));
  expectFailure(ValueError::class,fn()=>asyncara_submit('sqlite_open',["bad\0path"]));
  if(function_exists('asyncara_mysql_open'))expectFailure(ValueError::class,fn()=>asyncara_mysql_open(['host'=>'127.0.0.1','port'=>1,'tls'=>false,'caFile'=>__FILE__]));
 });
 chmod($path,0000);clearstatcache(true,$path);
 if(!is_readable($path))Async::run(fn()=>expectFailure(OperationException::class,fn()=>Files::read($path)->await()));
 else throw new RuntimeException('Permission fixture requires a non-root user');
 echo "Native queue, size, parameter and permission limits: PASS\n";
}finally{chmod($path,0600);unlink($path);}
