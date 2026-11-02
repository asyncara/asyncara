<?php
declare(strict_types=1);
namespace Asyncara\WebSocket;
use Asyncara\IO\TcpStream;
/** @internal Bounded HTTP/1.1 upgrade handshake, separate from curl HTTP requests. */
final class Handshake
{
    public static function read(TcpStream $stream): array
    {
        $buffer='';
        while(($end=strpos($buffer,"\r\n\r\n"))===false){
            if(strlen($buffer)>16384)throw new ProtocolException('WebSocket handshake headers too large');
            $chunk=$stream->read(4096);if($chunk==='')throw new ProtocolException('EOF during WebSocket handshake');$buffer.=$chunk;
        }
        if($end>16384)throw new ProtocolException('WebSocket handshake headers too large');
        $lines=explode("\r\n",substr($buffer,0,$end));$first=array_shift($lines);$headers=[];
        if(count($lines)>64)throw new ProtocolException('Too many WebSocket handshake headers');
        foreach($lines as$line){
            if(!preg_match('/^([!#$%&\'*+.^_`|~0-9A-Za-z-]+):[ \t]*([^\x00-\x08\x0a-\x1f\x7f]*)$/D',$line,$m))throw new ProtocolException('Malformed WebSocket handshake header');
            $headers[strtolower($m[1])][] = trim($m[2]);
        }
        return [$first,$headers,substr($buffer,$end+4)];
    }
    public static function one(array $headers,string $name): string
    {
        if(!isset($headers[$name])||count($headers[$name])!==1)throw new ProtocolException('Missing or duplicate '.$name);
        return $headers[$name][0];
    }
    public static function tokens(array $headers,string $name): array
    {
        $tokens=[];
        foreach($headers[$name]??[] as$value)foreach(explode(',',$value)as$token){
            $token=trim($token);
            if(!preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D',$token))throw new ProtocolException('Invalid token in '.$name);
            $tokens[]=$token;
        }
        return $tokens;
    }
    public static function upgrade(array $headers): void
    {
        if(!in_array('websocket',array_map(strtolower(...),self::tokens($headers,'upgrade')),true)||
           !in_array('upgrade',array_map(strtolower(...),self::tokens($headers,'connection')),true))throw new ProtocolException('Invalid WebSocket Upgrade/Connection');
        if(isset($headers['transfer-encoding']) || (isset($headers['content-length']) && self::one($headers,'content-length')!=='0'))throw new ProtocolException('WebSocket handshake cannot have a body');
    }
    public static function accept(string $key): string { return base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11',true)); }
    public static function protocols(array $protocols): void
    {
        if(count($protocols)>16||count(array_unique($protocols,SORT_STRING))!==count($protocols))throw new \ValueError('Invalid subprotocol list');
        foreach($protocols as$p)if(!is_string($p)||strlen($p)>128||!preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D',$p))throw new \ValueError('Invalid subprotocol');
    }
}
