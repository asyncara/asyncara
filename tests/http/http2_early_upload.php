<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Runtime,Future};use Asyncara\HTTP\HttpClient;
function verify(bool$ok,string$why):void{if(!$ok)throw new RuntimeException($why);}
$c=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);
Runtime::configure(['httpMaxHostConnections'=>1,'httpMaxConnections'=>1]);
Async::run(function()use($c){
 $client=new HttpClient(timeout:3,caFile:$c['ca']);$url=$c['url'];$client->get($url.'/zero-window')->await();
 for($n=1;$n<=16;$n++){
  $data=(static function(){yield str_repeat('x',65536);yield str_repeat('y',65536);})();
  $response=$client->requestStream('PUT',$url.'/early',$data)->await();verify($response->status===413,'Early response status');unset($data);
  Async::timeout(1,function()use($client,$url,$n){do{$state=$client->get($url.'/reset-count')->await()->json();if($state['resets']>=$n&&$state['openUploads']===0)break;Async::sleep(0);}while(true);});
  verify(asyncara_info()['pendingOperations']===0,'Native job retained');
 }
});
gc_collect_cycles();verify(asyncara_info()['nativeMemoryBytes']===0&&Future::pendingCount()===0,'Uncollected upload');echo "PASS: 16 early responses reset their zero-window uploads without replacing the shared connection\n";
