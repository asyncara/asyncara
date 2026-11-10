<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Future,Runtime};use Asyncara\HTTP\HttpClient;use Asyncara\Exception\OperationException;
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
[$script,$certs,$out]=$argv;
Runtime::configure(['nativeMemoryLimit'=>1048576,'httpMaxConnections'=>32,'httpMaxHostConnections'=>32]);
$peer=proc_open(['python3',dirname(__DIR__).'/fixtures/http_stream_server.py',$certs.'/server.pem',$certs.'/server.key'],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',$out.'/peer.log','x']],$pipes);
try{
 $ports=json_decode(fgets($pipes[1]),true,flags:JSON_THROW_ON_ERROR);$errors=0;$accepted=0;
 Async::run(function()use($ports,&$errors,&$accepted){
  $client=new HttpClient(concurrency:32);$streams=[];
  try{
   for($i=0;$i<32;$i++)$streams[]=$client->getStream('http://127.0.0.1:'.$ports['http'].'/large');
   for($i=0;$i<3;$i++)Async::sleep(.01);
   foreach($streams as$stream){try{check($stream->headers()->await()->status===200,'Status');$accepted++;}catch(OperationException){$errors++;}}
   $state=asyncara_info();check($state['nativeMemoryBytes']<=1048576,'Ledger exceeded hard limit');
  }finally{foreach($streams as$stream)try{$stream->close();}catch(OperationException){$errors++;}}
 });
 gc_collect_cycles();$state=asyncara_info();check($state['nativeMemoryRejections']>0&&$errors>0&&$accepted>0,'Capacity control not exercised');
 check($state['nativeMemoryPeak']<=1048576&&$state['nativeMemoryBytes']===0&&$state['pendingOperations']===0&&Future::pendingCount()===0,'Failed stream ownership');
 echo json_encode(['status'=>'PASS','errors'=>$errors,'acceptedHeaders'=>$accepted,'native'=>$state]),"\n";
}finally{fclose($pipes[0]);stream_get_contents($pipes[1]);fclose($pipes[1]);check(proc_close($peer)===0,'Peer shutdown');}
