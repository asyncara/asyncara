<?php
declare(strict_types=1);
require (getenv('ASYNCARA_AUDIT_SOURCE') ?: dirname(__DIR__,2)).'/autoload.php';
use Asyncara\{Async,Future};
use Asyncara\IO\{TcpStream,TcpServer,UdpSocket,Dns};
use Asyncara\Exception\CancelledException;
function progressing(Future $future,string $name):mixed{
 $ticks=0;$ticker=Async::spawn(function()use($future,&$ticks){while(!$future->isCompleted()){Async::sleep(.005);if(!$future->isCompleted())$ticks++;}});
 $result=$future->await();$ticker->await();if($ticks<2)throw new RuntimeException("$name blocked the loop");
 echo json_encode(['operation'=>$name,'ticksWhilePending'=>$ticks]),"\n";return$result;
}
$root=dirname(__DIR__,2);$cert=$root.'/build/test-certs/cert.pem';
$process=proc_open(['python3',__DIR__.'/io_server.py',$cert,$root.'/build/test-certs/key.pem'],[0=>['pipe','r'],1=>['pipe','w'],2=>STDERR],$pipes);
try{
 $ports=json_decode(fgets($pipes[1]),true,512,JSON_THROW_ON_ERROR);
 Async::run(function()use($ports,$cert){
  $s=TcpStream::connect('127.0.0.1',$ports['tcp'])->await();
  try{$s->write('R');if(progressing(Async::spawn(fn()=>$s->readExactly(1)),'TCP read')!=='x')throw new RuntimeException('Bad TCP read');}finally{$s->close();}
  $s=TcpStream::connect('127.0.0.1',$ports['tcp'])->await();
  try{progressing(Async::spawn(fn()=>$s->write('W'.str_repeat('w',8*1024*1024))),'TCP partial writes');if($s->readExactly(1)!=='x')throw new RuntimeException('Bad TCP write');}finally{$s->close();}
  $s=progressing(TcpStream::connect('localhost',$ports['tls'],tls:true,caFile:$cert),'TLS delayed handshake');
  try{if($s->readExactly(1)!=='x')throw new RuntimeException('Bad TLS read');}finally{$s->close();}
  $u=new UdpSocket();try{$u->send('payload','127.0.0.1:'.$ports['udp']);if(progressing(Async::spawn($u->receive(...)),'UDP receive')['data']!=='payload')throw new RuntimeException('Bad UDP payload');
   $waiting=Async::spawn($u->receive(...));Async::sleep(.01);$waiting->cancel();try{$waiting->await();throw new LogicException('Missing UDP cancellation');}catch(CancelledException){}
  }finally{$u->close();}
  $server=new TcpServer();try{
   $port=(int)substr(strrchr($server->address(),':'),1);$client=Async::spawn(function()use($port){Async::sleep(.2);return TcpStream::connect('127.0.0.1',$port)->await();});
   $accepted=progressing(Async::spawn($server->accept(...)),'TCP accept');$accepted->close();$client->await()->close();
  }finally{$server->close();}
  if(getenv('ASYNCARA_AUDIT_DNS'))progressing(Dns::resolve('audit-delay.invalid'),'DNS worker');
 });
}finally{fclose($pipes[0]);fclose($pipes[1]);$status=proc_close($process);if($status!==0)throw new RuntimeException('I/O fixture failed');}
echo "Slow I/O audit: PASS\n";
