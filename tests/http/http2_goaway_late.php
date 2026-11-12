<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Runtime,Future};use Asyncara\HTTP\HttpClient;use Asyncara\Exception\OperationException;
function verify(bool$ok,string$why):void{if(!$ok)throw new RuntimeException($why);}
$c=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);
Runtime::configure(['httpMaxHostConnections'=>1,'httpMaxConnections'=>2]);
Async::run(function()use($c):void{
 $client=new HttpClient(timeout:5,caFile:$c['ca'],proxy:$c['proxy']??null,proxyCaFile:$c['proxyCA']??null,proxyCredentials:$c['proxyCredentials']??null);$url=$c['url'];
 for($n=0;$n<16;$n++){
  $old=$client->get($url.'/goaway-late')->await();verify($old->body==='ok','Completed response lost');
  // Peer withholds GOAWAY until the next request. Its last-stream ID excludes
  // that request. No implicit replay is promised, including for a GET.
  $failed=false;try{$client->get($url.'/late-probe')->await();}catch(OperationException){$failed=true;}
  verify($failed,'Unaccepted stream silently replayed');
  $next=$client->get($url.'/ok')->await();verify($next->body==='ok'&&$next->headers['x-test-connection']!==$old->headers['x-test-connection'],'Failed to recover on fresh connection');
 }
});
gc_collect_cycles();verify(!asyncara_info()['pendingOperations']&&!asyncara_info()['nativeMemoryBytes']&&!Future::pendingCount(),'Late GOAWAY resource retention');
echo "PASS: 16 late GOAWAY rejections, no request replay, fresh connection recovery\n";
