<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Asyncara\{Async,Runtime};
use Asyncara\HTTP\HttpClient;
use Asyncara\IO\{TcpServer,TcpStream};
use Asyncara\Exception\{CancelledException,OperationException};
$root=dirname(__DIR__);
$fixture=proc_open(['python3',__DIR__.'/fixtures/http_server.py',"$root/build/test-certs/cert.pem","$root/build/test-certs/key.pem"],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',"$root/build/network-fixture.log",'a']],$pipes);
$ports=json_decode(fgets($pipes[1]),true,512,JSON_THROW_ON_ERROR);
try{
for($round=0;$round<3;$round++){
 Async::run(function()use($ports,$root):void{
  $url='http://127.0.0.1:'.$ports['http'];$client=new HttpClient();$tasks=[];
  for($i=0;$i<20;$i++)$tasks[]=$client->get($url.'/ok');
  foreach(Async::gather($tasks)as$response)if($response->body!=='hello asyncara')throw new RuntimeException('HTTP response');
  $tls=new HttpClient(caFile:"$root/build/test-certs/cert.pem");$tls->get('https://localhost:'.$ports['https'].'/ok')->await();
  for($i=0;$i<5;$i++){$task=$client->get($url.'/slow?delay=.1');Async::sleep(.001);$task->cancel();try{$task->await();}catch(CancelledException){}}
  try{$client->get($url.'/large?bytes=16777217')->await();throw new RuntimeException('HTTP body limit not enforced');}catch(OperationException){}
  $server=new TcpServer();$port=(int)substr(strrchr($server->address(),':'),1);
  $accept=Async::spawn(function()use($server):void{for($i=0;$i<10;$i++){$socket=$server->accept();$socket->write('ok');$socket->close();}});
  for($i=0;$i<10;$i++){$socket=TcpStream::connect('127.0.0.1',$port)->await();if($socket->readExactly(2)!=='ok')throw new RuntimeException('TCP');$socket->close();}$accept->await();$server->close();
 });gc_collect_cycles();
 if(Runtime::information()->pendingOperations!==0)throw new RuntimeException('Pending native operations retained');
}
echo "Network lifecycle and limits: PASS\n";
}finally{fclose($pipes[0]);fclose($pipes[1]);proc_close($fixture);}
