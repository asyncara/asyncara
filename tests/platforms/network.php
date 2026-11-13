<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Runtime};
use Asyncara\IO\{TcpServer,UdpSocket};
use Asyncara\HTTP\HttpClient;
function demand(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);}
Async::run(static function():void {
    $server=new TcpServer();$ticks=0;$pending=true;
    $peer=Async::spawn(static function()use($server,&$ticks):void {
        $stream=$server->accept();
        try {
            $head='';
            while(!str_contains($head,"\r\n\r\n"))$head.=$stream->read();
            // Peer cannot release its actual socket response until two timer
            // dispatches occur. No eventual-after-I/O tick can satisfy this.
            while($ticks<2)Async::sleep(.001);
            $stream->write("HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nOK");
        }finally{$stream->close();$server->close();}
    });
    $timer=Async::spawn(static function()use(&$ticks,&$pending):void {
        for($n=0;$n<2;$n++){Async::sleep(.005);demand($pending,'I/O completed before tick');$ticks++;}
    });
    $client=new HttpClient();
    $response=Async::timeout(3,fn()=>$client->get('http://'.$server->address().'/')->await());
    $pending=false;
    demand($response->body==='OK','curl response mismatch');
    demand($ticks>=2,'two pending-I/O timer ticks required');
    $peer->await();$timer->await();
    $a=new UdpSocket('127.0.0.1');$b=new UdpSocket('127.0.0.1');
    try{demand($a->send('packet',$b->address())===6,'UDP write');demand($b->receive()['data']==='packet','UDP read');}
    finally{$a->close();$b->close();}
});
demand(Runtime::information()->pendingOperations===0,'native operation leak');
echo "PASS: TCP readiness, curl multi, two timer ticks during pending HTTP, UDP and cleanup\n";
