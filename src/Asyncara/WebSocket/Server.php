<?php
declare(strict_types=1);
namespace Asyncara\WebSocket;
use Asyncara\{Async,Future};
use Asyncara\IO\TcpStream;
final class Server
{
    /** Browser origins require an explicit allowlist; non-browser clients may omit Origin. */
    public static function accept(TcpStream $stream,array $origins=[],array $protocols=[],?callable $authorize=null,float $timeout=5,
        int $messageLimit=1048576,int $queueLimit=16,int $queueBytes=4194304,float $idleTimeout=30): Future
    {
        Handshake::protocols($protocols);
        return Async::spawn(static fn()=>Async::timeout($timeout,static function()use($stream,$origins,$protocols,$authorize,$messageLimit,$queueLimit,$queueBytes,$idleTimeout):Connection{
            try{
                [$request,$headers,$initial]=Handshake::read($stream);
                if(!preg_match('/^GET (\/[^\x00-\x20\x7f]*) HTTP\/1\.1$/D',$request,$m))throw new ProtocolException('Invalid WebSocket GET request');
                Handshake::upgrade($headers);
                if(Handshake::one($headers,'host')==='')throw new ProtocolException('Empty WebSocket Host');
                $key=Handshake::one($headers,'sec-websocket-key');$decoded=base64_decode($key,true);
                if($decoded===false||strlen($decoded)!==16||base64_encode($decoded)!==$key||Handshake::one($headers,'sec-websocket-version')!=='13')throw new ProtocolException('Invalid WebSocket key/version');
                if(isset($headers['origin'])&&!in_array(Handshake::one($headers,'origin'),$origins,true))throw new ProtocolException('WebSocket Origin is not allowed',1008);
                if($authorize!==null&&$authorize($m[1],$headers)!==true)throw new ProtocolException('WebSocket authorization rejected',1008);
                $requested=Handshake::tokens($headers,'sec-websocket-protocol');$protocol=null;
                if(count($requested)>64||count(array_unique($requested))!==count($requested))throw new ProtocolException('Invalid WebSocket subprotocol list');
                foreach($protocols as$p)if(in_array($p,$requested,true)){$protocol=$p;break;}
                $stream->write("HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: ".Handshake::accept($key)."\r\n".($protocol!==null?'Sec-WebSocket-Protocol: '.$protocol."\r\n":'')."\r\n");
                return new Connection($stream,false,$protocol,$messageLimit,$queueLimit,$queueBytes,$idleTimeout,initial:$initial);
            }catch(\Throwable $e){$stream->close();throw $e;}
        }));
    }
}
