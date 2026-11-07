<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Asyncara\{Async,Scheduler};
use Asyncara\MySQL\Connection as MySQL;
use Asyncara\PostgreSQL\Connection as Pg;
use Asyncara\Redis\Connection as Redis;
use Asyncara\Pool\ConnectionPool;
use Asyncara\Exception\{CancelledException,TimeoutException};
function verify(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
function expect(string $class,callable $body):void{try{$body();}catch(Throwable $e){if($e instanceof $class)return;throw $e;}throw new RuntimeException('Expected '.$class);}
$pg=['host'=>getenv('ASYNCARA_PG_SOCKET'),'port'=>'55499','dbname'=>'postgres','sslmode'=>'disable'];
$my=['socket'=>getenv('ASYNCARA_MY_SOCKET'),'user'=>'root','database'=>'mysql'];
$redisPort=(int)getenv('ASYNCARA_REDIS_PORT');
Async::run(function()use($pg,$my,$redisPort):void{
 foreach(['PostgreSQL'=>fn()=>Pg::connect($pg),'MariaDB'=>fn()=>MySQL::connect($my)]as$name=>$factory){
  $db=$factory()->await();
  expect(Error::class,fn()=>clone $db);expect(LogicException::class,fn()=>serialize($db));
  $copy=clone $db->prepare('select 42 as n');expect(LogicException::class,fn()=>serialize($copy));
  verify($copy->execute()->await()[0]['n']==='42',"$name statement clone retained canonical connection");
  if($name==='MariaDB'){ $tls=$db->query("show session status like 'Ssl_cipher'")->await();verify(($tls[0]['Value']??null)==='', 'Plain Unix-socket fixture unexpectedly negotiated TLS'); }
  $placeholder=$name==='PostgreSQL'?'$1':'?';
  verify($db->prepare("select $placeholder as value")->execute(['bound'])->await()[0]['value']==='bound',"$name parameter binding");
  verify($db->query("select $placeholder as value",[null])->await()[0]['value']===null,"$name null binding");
  if($name==='PostgreSQL')verify($db->query('select $1::boolean as value',[false])->await()[0]['value']==='f','PostgreSQL boolean binding');
  else verify($db->query('select cast(? as unsigned) as value',[false])->await()[0]['value']==='0','MariaDB boolean binding');
  $blob=str_repeat('long-data-',100000);
  verify($db->query("select $placeholder as value",[$blob])->await()[0]['value']===$blob,"$name large parameters and results");
  $db->query('create temporary table asyncara_test(n integer)')->await();
  $db->transaction(function($db)use($placeholder){$db->query("insert into asyncara_test values ($placeholder)",[11])->await();})->await();
  expect(DomainException::class,fn()=>$db->transaction(function($db)use($placeholder){$db->query("insert into asyncara_test values ($placeholder)",[22])->await();throw new DomainException('rollback');})->await());
  verify($db->query('select sum(n) as n from asyncara_test')->await()[0]['n']==='11',"$name rollback");
  $sleep=$name==='PostgreSQL'?'select pg_sleep(.05)':'select sleep(.05)';
  $ticks=0;$ticker=Async::spawn(function()use(&$ticks){for($i=0;$i<5;$i++){Async::sleep(.005);$ticks++;}});
  $db->query($sleep)->await();$ticker->await();verify($ticks===5,"$name blocked timers");
  $pool=new ConnectionPool($factory,static fn($c)=>$c->close(),3);
  $barrier=new \Asyncara\Concurrency\Event();$connected=0;$warm=[];
  for($i=0;$i<3;$i++)$warm[]=Async::spawn(function()use($pool,$barrier,&$connected){$pool->withConnection(function()use($barrier,&$connected){if(++$connected===3)$barrier->set();$barrier->wait();});});
  Async::gather($warm);
  $timed=$name==='PostgreSQL'?"select extract(epoch from clock_timestamp()) as started, pg_sleep(.1), extract(epoch from clock_timestamp()) as ended":"select unix_timestamp(sysdate(6)) as started, sleep(.1), unix_timestamp(sysdate(6)) as ended";

  try{$tasks=[];for($i=0;$i<3;$i++)$tasks[]=Async::spawn(fn()=>$pool->withConnection(fn($c)=>$c->query($timed)->await()[0]));$intervals=Async::gather($tasks);verify(max(array_column($intervals,'started'))<min(array_column($intervals,'ended')),"$name concurrent independent connections");}finally{$pool->close();}
  $long=$db->query($name==='PostgreSQL'?'select pg_sleep(10)':'select sleep(10)');Async::sleep(.01);$long->cancel();expect(CancelledException::class,$long->await(...));$db->close();
  $db=$factory()->await();expect(Throwable::class,fn()=>$db->query('select definitely_invalid_column')->await());$db->close();
  for($i=0;$i<10;$i++){$db=$factory()->await();$db->query('select 1')->await();$db->close();}
  echo "PASS $name bindings, large data, transactions, concurrency, cancellation, errors, lifecycle\n";
 }
 $listener=Pg::connect($pg)->await();$sender=Pg::connect($pg)->await();
 try{$listener->query('LISTEN asyncara_test')->await();$wait=Async::spawn($listener->nextNotification(...));$sender->query('select pg_notify($1,$2)',['asyncara_test','payload'])->await();$note=$wait->await();verify($note['payload']==='payload','PostgreSQL notification');}finally{$listener->close();$sender->close();}
 echo "PASS PostgreSQL asynchronous notifications\n";
 foreach([false,true]as$resp3){
  $r=Redis::connect('127.0.0.1',$redisPort,resp3:$resp3)->await();
  expect(Error::class,fn()=>clone $r);expect(LogicException::class,fn()=>serialize($r));
  verify($r->command(['PING'])->await()==='PONG','Redis ping');
  $values=$r->pipeline([['SET','asyncara:binary',"a\0b"],['GET','asyncara:binary'],['ASYNCARA_INVALID'],['GET','asyncara:absent']])->await();
  verify($values[0]==='OK'&&$values[1]==="a\0b"&&$values[2]instanceof Asyncara\Redis\ServerException&&$values[3]===null,'Redis pipeline framing');
  verify($r->transaction([['SET','asyncara:tx','value'],['GET','asyncara:tx']])->await()===['OK','value'],'Redis transaction');
  $subscriber=Redis::connect('127.0.0.1',$redisPort,resp3:$resp3)->await();$subscriber->subscribe(['asyncara:channel'])->await();
  $wait=Async::spawn($subscriber->nextMessage(...));$r->command(['PUBLISH','asyncara:channel','message'])->await();$message=$wait->await();verify($message===['message','asyncara:channel','message'],'Redis Pub/Sub');$subscriber->close();
  $blocked=$r->command(['BLPOP','asyncara:never',0]);Async::sleep(.01);$blocked->cancel();expect(CancelledException::class,$blocked->await(...));$r->close();
  echo 'PASS Redis RESP'.($resp3?'3':'2')." binary, pipeline, errors, transaction, Pub/Sub, cancellation\n";
 }
});
gc_collect_cycles();
echo "Database integration suite passed\n";
