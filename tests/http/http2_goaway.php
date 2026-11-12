<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Runtime,Future};use Asyncara\HTTP\HttpClient;
function verify(bool$ok,string$why):void{if(!$ok)throw new RuntimeException($why);}
$c=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);
Runtime::configure(['httpMaxHostConnections'=>2,'httpMaxConnections'=>2]);
Async::run(function()use($c):void{
 $client=new HttpClient(timeout:5,caFile:$c['ca']);$url=$c['url'];$client->get($url.'/ok')->await();
 for($n=0;$n<16;$n++){
  $held=$client->get($url.'/hold/retire');
  Async::timeout(1,function()use($client,$url){while($client->get($url.'/state/retire')->await()->json()['held']!==1)Async::sleep(0);});
  $old=$client->get($url.'/goaway-drain')->await();verify($old->body==='ok','Admitted response lost');
  verify(!$held->isCompleted(),'GOAWAY cancelled admitted sibling');
  // Only a new connection can release the old admitted stream. No arbitrary
  // sleep or immediate teardown can conceal reuse of the draining sender.
  $release=$client->get($url.'/release/retire')->await();
  verify($release->headers['x-test-connection']!==$old->headers['x-test-connection'],'Draining connection reused');
  verify($held->await()->body==='released','GOAWAY discarded valid response');
  echo "PASS draining connection $n\n";
 }
});
gc_collect_cycles();verify(asyncara_info()['pendingOperations']===0&&asyncara_info()['nativeMemoryBytes']===0&&Future::pendingCount()===0,'GOAWAY resource retention');
