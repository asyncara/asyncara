<?php
declare(strict_types=1);
// Scoped ownership/leak control, separate from the normal timing/large-body gate.
require dirname(__DIR__, 2).'/autoload.php';
use Asyncara\{Async,Future};use Asyncara\HTTP\HttpClient;
function check(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
[$script,$certs,$out]=$argv;
$peer=proc_open(['python3',dirname(__DIR__).'/fixtures/http_stream_server.py',$certs.'/server.pem',$certs.'/server.key'],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',$out.'/peer.log','x']],$pipes);
try {
 $ports=json_decode(fgets($pipes[1]),true,flags:JSON_THROW_ON_ERROR);
 Async::run(function()use($ports,$certs){
  foreach(['http://127.0.0.1:'.$ports['http'],'https://localhost:'.$ports['https']]as$url){
   $c=new HttpClient(concurrency:2,caFile:str_starts_with($url,'https:')?$certs.'/ca.pem':null);
   for($i=0;$i<8;$i++){
    $s=$c->getStream($url.'/headers-first');check($s->headers()->await()->status===200,'Head');
    $body=Async::spawn(fn()=>implode('',iterator_to_array($s)));
    for($tick=0;$tick<2;$tick++){Async::sleep(.002);check(!$body->isCompleted(),'Progress before peer release');}
    $c->get($url.'/release-body')->await();check($body->await()==='x','Body');$s->close();
    $bytes=0;$c->getStream($url.'/small')->consume(function($chunk)use(&$bytes){$bytes+=strlen($chunk);});check($bytes===65536,'Body bytes');
    foreach([null,65536]as$length){$r=$c->requestStream('POST',$url.'/upload',[str_repeat('x',65536)],$length)->await()->json();check($r['bytes']===65536&&$r['sha256']===hash('sha256',str_repeat('x',65536)),'Upload identity');}
    $s=$c->getStream($url.'/large');try{foreach($s as$_)break;}finally{$s->close();}
    $s=$c->getStream($url.'/stall');$s->close();
    $producer=(static function(){yield 'x';throw new DomainException('producer');})();
    try{$c->requestStream('PUT',$url.'/upload',$producer)->await();throw new RuntimeException('Producer failure absent');}catch(DomainException){}
    check(asyncara_info()['pendingOperations']===0,'Outstanding native operation');
   }
  }
 });
 gc_collect_cycles();$info=asyncara_info();check($info['pendingOperations']===0&&$info['nativeMemoryBytes']===0&&$info['openFileHandles']===0&&Future::pendingCount()===0,'Resources retained');
 check($info['hyperSubmitted']===$info['hyperCompleted'],'Completion count');echo json_encode(['status'=>'PASS','native'=>$info]),"\n";
}finally{fclose($pipes[0]);stream_get_contents($pipes[1]);fclose($pipes[1]);check(proc_close($peer)===0,'Peer exit');}
