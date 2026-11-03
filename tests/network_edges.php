<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Asyncara\{Async,Future,Runtime,Scheduler};
use Asyncara\IO\{Dns,TcpServer,TcpStream,UdpSocket};
use Asyncara\Exception\{OperationException,CancelledException,TimeoutException};
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function raises(string $type,callable $fn):void{try{$fn();}catch(Throwable $e){if($e instanceof$type)return;throw$e;}throw new RuntimeException('Expected '.$type);}
function line(TcpStream $s):string{$out='';while(!str_contains($out,"\n")){$data=$s->read();if($data==='')throw new RuntimeException('short line');$out.=$data;}return trim($out);}
$root=dirname(__DIR__);$ca="$root/build/test-certs/cert.pem";
$process=proc_open(['python3',__DIR__.'/fixtures/network_edges.py',$ca,"$root/build/test-certs/key.pem"],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',"$root/build/network-edges-peer.log",'w']],$pipes);
try{
    $ports=json_decode(fgets($pipes[1]),true,512,JSON_THROW_ON_ERROR);$observations=[];
    Async::run(function()use($ports,$ca,&$observations):void{
        $addresses=Dns::resolve('localhost')->await();check(in_array('::1',$addresses,true)&&in_array('127.0.0.1',$addresses,true),'fixture needs localhost IPv4 + IPv6');$observations['localhost']=$addresses;
        foreach(['127.0.0.1'=>'v4','::1'=>'v6','localhost'=>'v4']as$host=>$kind){
            $s=TcpStream::connect($host,$ports[$kind])->await();try{$s->write("download\n");$data='';while(($chunk=$s->read(137))!=='')$data.=$chunk;check($data===str_repeat('z',4093*256),'partial TCP/IPv6');}finally{$s->close();}
        }
        foreach(['tls12'=>'TLSv1.2','tls13'=>'TLSv1.3']as$kind=>$version){
            $s=TcpStream::connect('localhost',$ports[$kind],tls:true,caFile:$ca)->await();try{$s->write("info\n");$info=json_decode(line($s),true,512,JSON_THROW_ON_ERROR);check($info['version']===$version&&$info['alpn']==='http/1.1'&&$info['sni']==='localhost','TLS/SNI/ALPN');$observations[$kind]=$info;}finally{$s->close();}
            $ticks=0;$done=false;$timer=Async::spawn(function()use(&$ticks,&$done):void{while(!$done){Async::sleep(.002);$ticks++;}});
            try{
                $s=TcpStream::connect('localhost',$ports[$kind],tls:true,caFile:$ca)->await();
                try{$data=str_repeat('u',2097152);$s->write('upload '.strlen($data)."\n");$s->write($data);check(line($s)===hash('sha256',$data),'slow TLS partial upload');}finally{$s->close();}
                $s=TcpStream::connect('localhost',$ports[$kind],tls:true,caFile:$ca)->await();
                try{$s->write("download\n");$bytes=0;while(($chunk=$s->read(137))!==''){check($chunk===str_repeat('z',strlen($chunk)),'TLS partial body');$bytes+=strlen($chunk);}check($bytes===4093*256,'TLS partial length');}finally{$s->close();}
            }finally{$done=true;$timer->await();}check($ticks>10,'TLS blocked scheduler');$observations[$kind]['timerTicks']=$ticks;
            raises(OperationException::class,fn()=>TcpStream::connect('localhost',$ports[$kind],tls:true)->await());
            raises(OperationException::class,fn()=>TcpStream::connect($kind==='tls12'?'127.0.0.1':'::1',$ports[$kind],tls:true,caFile:$ca)->await());
        }
        $ticks=0;$timer=Async::spawn(function()use(&$ticks):void{for($i=0;$i<10;$i++){Async::sleep(.003);$ticks++;}});
        raises(TimeoutException::class,fn()=>TcpStream::connect('127.0.0.1',$ports['stall'],timeout:.05,tls:true,caFile:$ca)->await());$timer->await();check($ticks===10,'stalled handshake blocked timers');
        $connect=TcpStream::connect('127.0.0.1',$ports['stall'],tls:true,caFile:$ca);Async::sleep(.01);$connect->cancel();raises(CancelledException::class,$connect->await(...));
        $s=TcpStream::connect('localhost',$ports['tls12'],tls:true,caFile:$ca)->await();try{$s->write("disconnect\n");check($s->readExactly(6)==='prefix','TLS disconnect prefix');raises(OperationException::class,fn()=>$s->readExactly(1));}finally{$s->close();}
        $server=new TcpServer('::1');$port=(int)substr(strrchr($server->address(),':'),1);$accept=Async::spawn(function()use($server):void{$s=$server->accept();try{check($s->readExactly(4)==='ipv6','IPv6 server read');$s->write('pong');}finally{$s->close();$server->close();}});
        $s=TcpStream::connect('::1',$port)->await();try{$s->write('ipv6');check($s->readExactly(4)==='pong','IPv6 server echo');}finally{$s->close();}$accept->await();
        raises(OperationException::class,fn()=>TcpStream::connect('::1',$port,timeout:.1)->await());
        $a=new UdpSocket('::1');$b=new UdpSocket('::1');try{foreach(['ipv6','']as$data){$a->send($data,$b->address());check($b->receive()['data']===$data,'IPv6 UDP');}$wait=Async::spawn($b->receive(...));Async::sleep(0);$wait->cancel();raises(CancelledException::class,$wait->await(...));}finally{$a->close();$b->close();}
    });
    gc_collect_cycles();check(Future::pendingCount()===0&&Runtime::information()->pendingOperations===0,'pending network work');
    echo 'IPv4/IPv6 TCP/UDP, TLS1.2/1.3, SNI/ALPN, partial I/O, cancellation and cleanup: PASS ',json_encode($observations,JSON_THROW_ON_ERROR),"\n";
}finally{fclose($pipes[0]);fclose($pipes[1]);proc_close($process);}
