<?php
declare(strict_types=1);
namespace Asyncara\WebSocket;
use Asyncara\{Async,Future};
use Asyncara\IO\TcpStream;
final class Client
{
    public static function connect(string $url,array $protocols=[],array $headers=[],?string $caFile=null,float $timeout=10,
        int $messageLimit=1048576,int $queueLimit=16,int $queueBytes=4194304,float $idleTimeout=30): Future
    {
        $parts=parse_url($url);Handshake::protocols($protocols);
        if(!$parts||!in_array($parts['scheme']??'',['ws','wss'],true)||!isset($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment']))throw new \ValueError('Expected an absolute ws/wss URL without credentials/fragment');
        $host=trim($parts['host'],'[]');$tls=$parts['scheme']==='wss';$port=$parts['port']??($tls?443:80);
        if(preg_match('/[\x00-\x20\x7f]/',$host))throw new \ValueError('Invalid WebSocket hostname');
        $path=($parts['path']??'/').(isset($parts['query'])?'?'.$parts['query']:'');if($path==='')$path='/';
        if(strlen($path)>8192||preg_match('/[^\x21-\x7e]/',$path))throw new \ValueError('WebSocket path must be a bounded encoded URI');
        $extra='';
        foreach($headers as$name=>$value){
            if(!is_string($name)||!is_string($value)||!preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D',$name)||preg_match('/[\x00-\x1f\x7f]/',$value)||
               in_array(strtolower($name),['host','upgrade','connection','content-length','transfer-encoding'],true)||str_starts_with(strtolower($name),'sec-websocket-'))throw new \ValueError('Invalid/reserved WebSocket request header');
            $extra.=$name.': '.$value."\r\n";
        }
        if(strlen($extra)>8192)throw new \ValueError('WebSocket request headers too large');
        return Async::spawn(static fn()=>Async::timeout($timeout,static function()use($host,$tls,$port,$path,$extra,$protocols,$caFile,$messageLimit,$queueLimit,$queueBytes,$idleTimeout):Connection{
            $stream=Async::await(TcpStream::connect($host,$port,tls:$tls,caFile:$caFile));
            try{
                $key=base64_encode(random_bytes(16));$authority=(str_contains($host,':')?'['.$host.']':$host).':'.$port;
                $stream->write('GET '.$path." HTTP/1.1\r\nHost: ".$authority."\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: ".$key."\r\nSec-WebSocket-Version: 13\r\n".($protocols?'Sec-WebSocket-Protocol: '.implode(', ',$protocols)."\r\n":'').$extra."\r\n");
                [$status,$response,$initial]=Handshake::read($stream);
                if(!preg_match('/^HTTP\/1\.1 101(?: .*)?$/D',$status))throw new ProtocolException('WebSocket upgrade status is not 101');
                Handshake::upgrade($response);
                if(!hash_equals(Handshake::accept($key),Handshake::one($response,'sec-websocket-accept'))||isset($response['sec-websocket-extensions']))throw new ProtocolException('Invalid WebSocket accept or unrequested extension');
                $protocol=isset($response['sec-websocket-protocol'])?Handshake::one($response,'sec-websocket-protocol'):null;
                if($protocol!==null&&!in_array($protocol,$protocols,true))throw new ProtocolException('Unrequested WebSocket subprotocol');
                return new Connection($stream,true,$protocol,$messageLimit,$queueLimit,$queueBytes,$idleTimeout,initial:$initial);
            }catch(\Throwable $e){$stream->close();throw $e;}
        }));
    }
}
