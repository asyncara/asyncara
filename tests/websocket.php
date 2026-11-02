<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Asyncara\{Async,Scheduler};
use Asyncara\IO\{TcpServer,TcpStream};
use Asyncara\WebSocket\{Client,Server,FrameCodec,ProtocolException};
use Asyncara\Exception\{CancelledException,OperationException,TimeoutException};
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function raises(string $type,callable $body):Throwable{try{$body();}catch(Throwable $e){if($e instanceof$type)return$e;throw$e;}throw new RuntimeException('Expected '.$type);}
$root=dirname(__DIR__);$env=array_merge(getenv(),['PYTHONPATH'=>$root.'/.local/ws-peer-17.4']);
$peer=proc_open(['python3',__DIR__.'/fixtures/websocket_peer.py',"$root/build/test-certs/cert.pem","$root/build/test-certs/key.pem"],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',"$root/build/websocket-peer.log",'a']],$pipes,null,$env);
$ports=json_decode(fgets($pipes[1]),true,512,JSON_THROW_ON_ERROR);$url='ws://127.0.0.1:'.$ports['ws'];$raw='ws://127.0.0.1:'.$ports['raw'];$tls='wss://localhost:'.$ports['wss'];
try{
Async::run(function()use($url,$raw,$tls,$root,$env):void{
    $ws=Async::await(Client::connect($url,protocols:['asyncara']));
    try{
        check($ws->protocol==='asyncara','subprotocol');$ws->send('hello ☃');check($ws->receive()?->data==='hello ☃','text');
        $binary=str_repeat("\0\xffabcdef",65536);$ws->send($binary,true);$reply=$ws->receive();check($reply?->binary&&$reply->data===$binary,'fragmented binary');
        Async::await($ws->ping('probe'));check($ws->statistics()['pendingPings']===0,'ping retention');
        raises(ValueError::class,fn()=>$ws->send("\xff"));
    }finally{$ws->close();}
    check($ws->statistics()['closeAcknowledged'],'close handshake');
    $ws=Async::await(Client::connect($url.'/fragments'));try{check($ws->receive()?->data==='snowman ☃ text','fragment/control interleaving');check($ws->receive()===null,'peer close');}finally{$ws->close();}
    $ws=Async::await(Client::connect($tls,caFile:"$root/build/test-certs/cert.pem"));try{$ws->send('secure');check($ws->receive()?->data==='secure','TLS message');}finally{$ws->close();}
    raises(OperationException::class,fn()=>Async::await(Client::connect($tls)));
    raises(OperationException::class,fn()=>Async::await(Client::connect(str_replace('localhost','127.0.0.1',$tls),caFile:"$root/build/test-certs/cert.pem")));
    foreach(['/bad-accept','/duplicate-accept','/extension']as$path)raises(ProtocolException::class,fn()=>Async::await(Client::connect($raw.$path)));
    $bad=["\xc1\x00"=>1002,"\x80\x00"=>1002,"\x81\x01\xff"=>1007,"\x88\x01x"=>1002,"\x01\x01a\x81\x01b"=>1002,"\x82\x7f".pack('NN',0,1048577)=>1009];
    foreach($bad as$packet=>$code){$ws=Async::await(Client::connect($raw.'/frame?hex='.bin2hex($packet)));try{$error=raises(ProtocolException::class,$ws->receive(...));check($error->closeCode===$code,'protocol close code');}finally{$ws->close();}}
    $split="\x01\x01\xc3\x80\x01\xa9";$ws=Async::await(Client::connect($raw.'/frame?hex='.bin2hex($split)));
    try{check($ws->receive()?->data==='é','UTF-8 spanning continuation frames');}finally{$ws->close();}
    $ws=Async::await(Client::connect($raw.'/ping-during-upload',messageLimit:8388608));
    try{$ws->send(str_repeat('a',8388608),true);check($ws->receive()?->data==='control-interleaved','outgoing control interleaving');}finally{$ws->close();}
    $ws=Async::await(Client::connect($raw.'/truncated'));try{check(raises(ProtocolException::class,$ws->receive(...))->closeCode===1006,'abnormal EOF');}finally{$ws->close();}
    $ws=Async::await(Client::connect($url.'/flood',queueLimit:2));Async::sleep(.05);
    try{check($ws->receive()?->data==='message-0','queued first');check($ws->receive()?->data==='message-1','queued second');check(raises(ProtocolException::class,$ws->receive(...))->closeCode===1008,'slow consumer disconnect');}finally{$ws->close();}
    $ws=Async::await(Client::connect($url.'/idle',idleTimeout:.03));try{raises(TimeoutException::class,$ws->receive(...));}finally{$ws->close();}
    $ws=Async::await(Client::connect($raw.'/no-close'));raises(TimeoutException::class,fn()=>$ws->close(timeout:.02));check($ws->statistics()['closed'],'close timeout cleanup');
    $ws=Async::await(Client::connect($url));$read=Async::spawn($ws->receive(...));Async::sleep(0);$read->cancel();raises(CancelledException::class,$read->await(...));$ws->send('after cancel');check($ws->receive()?->data==='after cancel','receive cancellation lost message');$ws->close();
    $server=new TcpServer();$port=(int)substr(strrchr($server->address(),':'),1);
    $task=Async::spawn(function()use($server):void{
        $conn=Async::await(Server::accept($server->accept(),origins:['https://allowed.test'],protocols:['asyncara'],authorize:static fn($path,$headers)=>$path==='/echo'));
        try{for($i=0;$i<2;$i++){$message=$conn->receive();check($message!==null,'server message');$conn->send($message->data,$message->binary);}check($conn->receive()===null,'server close');}finally{$conn->close();$server->close();}
    });
    $process=proc_open(['python3',__DIR__.'/fixtures/websocket_peer.py','client','ws://127.0.0.1:'.$port.'/echo'],[0=>['pipe','r'],1=>['pipe','w'],2=>['redirect',1]],$child,null,$env);
    try{fclose($child[0]);stream_set_blocking($child[1],false);$out='';while(!feof($child[1])){$out.=stream_get_contents($child[1]);if(!feof($child[1]))Scheduler::current()->readable($child[1]);}
        fclose($child[1]);check(proc_close($process)===0,'independent client failed: '.$out);$process=null;$task->await();
    }finally{if(is_resource($process))proc_terminate($process);$server->close();}
    foreach(['origin','authorization','duplicate-key','unmasked-client']as$case){
        $server=new TcpServer();$port=(int)substr(strrchr($server->address(),':'),1);
        $task=Async::spawn(function()use($server,$case):void{
            try{
                if($case==='unmasked-client'){
                    $conn=Async::await(Server::accept($server->accept()));
                    try{raises(ProtocolException::class,$conn->receive(...));}finally{$conn->close();}
                }else raises(ProtocolException::class,fn()=>Async::await(Server::accept($server->accept(),authorize:$case==='authorization'?static fn()=>false:null)));
            }finally{$server->close();}
        });
        if(in_array($case,['origin','authorization'],true))raises(ProtocolException::class,fn()=>Async::await(Client::connect('ws://127.0.0.1:'.$port,headers:$case==='origin'?['Origin'=>'https://denied.test']:[])));
        else{
            $rawClient=Async::await(TcpStream::connect('127.0.0.1',$port));
            try{
                $key=base64_encode(random_bytes(16));
                $request="GET / HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n";
                if($case==='duplicate-key')$request.="Sec-WebSocket-Key: $key\r\n";
                $rawClient->write($request."\r\n");
                if($case==='unmasked-client'){\Asyncara\WebSocket\Handshake::read($rawClient);$rawClient->write(FrameCodec::encode(1,'illegal',false));$rawClient->read();}
                else check($rawClient->read()==='','rejected handshake not closed');
            }finally{$rawClient->close();}
        }
        $task->await();
    }
});
gc_collect_cycles();check(asyncara_info()['pendingOperations']===0&&asyncara_info()['nativeMemoryBytes']===0,'retained native work');
echo 'WebSocket bidirectional interoperability, TLS, fragmentation, controls, errors and cleanup: PASS peer '.$ports['peerVersion']."\n";
}finally{fclose($pipes[0]);fclose($pipes[1]);proc_close($peer);}
