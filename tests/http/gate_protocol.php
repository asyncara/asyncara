<?php
declare(strict_types=1);
// A release request may be handled before a delayed body's request. The test
// fixture must consume exactly one release, without losing or leaking it.
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Runtime};
use Asyncara\HTTP\HttpClient;
function check(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$root=dirname(__DIR__,2);
$server=proc_open(['python3',dirname(__DIR__).'/fixtures/http_stream_server.py',
    "$root/build/test-certs/cert.pem","$root/build/test-certs/key.pem"],
    [0=>['pipe','r'],1=>['pipe','w'],2=>['file',"$root/build/gate-protocol-server.log",'a']],$pipes);
try {
 $ports=json_decode(fgets($pipes[1]),true,512,JSON_THROW_ON_ERROR);$url='http://127.0.0.1:'.$ports['http'];
 Async::run(function()use($url):void {
  $c=new HttpClient();
  check($c->get($url.'/release-body')->await()->status===200,'Early release failed');
  check($c->get($url.'/headers-first')->await()->body==='x','Early release was lost');
  for($cycle=0;$cycle<3;$cycle++) {
   $pending=$c->get($url.'/headers-first');$ticks=0;
   for($i=0;$i<2;$i++){Async::sleep(.002);check(!$pending->isCompleted(),'Release leaked into next body');$ticks++;}
   $c->get($url.'/release-body')->await();
   check($pending->await()->body==='x' && $ticks===2,'Pending body progress or completion');
  }
 });
 check(asyncara_info()['pendingOperations']===0 && asyncara_info()['nativeMemoryBytes']===0,'Native cleanup');
 echo "PASS early-release and three independent two-tick body barriers\n";
}finally{foreach($pipes as$p)fclose($p);check(proc_close($server)===0,'Fixture exit');}
