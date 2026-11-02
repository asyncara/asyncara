<?php
declare(strict_types=1);
namespace Asyncara\WebSocket;
/** @internal Incremental RFC6455 framing; no extensions are negotiated. */
final class FrameCodec
{
    private string $buffer = '';
    public function __construct(private bool $expectMasked, private int $limit = 1048576)
    {
        if ($limit < 1 || $limit > 16777216) throw new \ValueError('Invalid WebSocket frame limit');
    }
    public function push(string $bytes): void
    {
        if (strlen($bytes) > 65536 || strlen($this->buffer) + strlen($bytes) > $this->limit + 65550)
            throw new ProtocolException('WebSocket parser buffer capacity exceeded', 1009);
        $this->buffer .= $bytes;
    }
    public function clear(): void { $this->buffer = ''; }
    /** @return array{opcode:int,fin:bool,data:string}|null */
    public function next(): ?array
    {
        if (strlen($this->buffer) < 2) return null;
        $first=ord($this->buffer[0]);$second=ord($this->buffer[1]);$opcode=$first&15;$fin=(bool)($first&128);
        if (($first&112) || !in_array($opcode,[0,1,2,8,9,10],true)) throw new ProtocolException('Reserved WebSocket bits/opcode');
        if ((bool)($second&128) !== $this->expectMasked) throw new ProtocolException('Incorrect WebSocket masking direction');
        $length=$second&127;$offset=2;
        if ($opcode>=8 && (!$fin || $length>125)) throw new ProtocolException('Invalid WebSocket control frame');
        if ($length===126) {
            if(strlen($this->buffer)<4)return null;
            $length=unpack('n',substr($this->buffer,2,2))[1];$offset=4;
            if($length<126)throw new ProtocolException('Nonminimal WebSocket length');
        } elseif ($length===127) {
            if(strlen($this->buffer)<10)return null;
            $words=unpack('Nhigh/Nlow',substr($this->buffer,2,8));$offset=10;
            if($words['high']&0x80000000)throw new ProtocolException('Invalid WebSocket signed length');
            if($words['high'])throw new ProtocolException('WebSocket frame exceeds capacity',1009);
            $length=$words['low'];
            if($length<65536)throw new ProtocolException('Nonminimal WebSocket length');
        }
        if($length>$this->limit)throw new ProtocolException('WebSocket frame exceeds capacity',1009);
        $mask='';
        if($this->expectMasked){if(strlen($this->buffer)<$offset+4)return null;$mask=substr($this->buffer,$offset,4);$offset+=4;}
        if(strlen($this->buffer)-$offset<$length)return null;
        $payload=substr($this->buffer,$offset,$length);$this->buffer=substr($this->buffer,$offset+$length);
        if($mask!=='')$payload^=substr(str_repeat($mask,intdiv($length+3,4)),0,$length);
        return ['opcode'=>$opcode,'fin'=>$fin,'data'=>$payload];
    }
    public static function encode(int $opcode,string $data,bool $masked,bool $fin=true): string
    {
        $length=strlen($data);
        if(!in_array($opcode,[0,1,2,8,9,10],true)||$length>16777216||($opcode>=8&&(!$fin||$length>125)))throw new \ValueError('Invalid outbound WebSocket frame');
        $head=chr(($fin?128:0)|$opcode);$bit=$masked?128:0;
        if($length<126)$head.=chr($bit|$length);
        elseif($length<65536)$head.=chr($bit|126).pack('n',$length);
        else $head.=chr($bit|127).pack('NN',0,$length);
        if(!$masked)return $head.$data;
        $key=random_bytes(4);
        return $head.$key.($data^substr(str_repeat($key,intdiv($length+3,4)),0,$length));
    }
    public static function utf8(string $value): bool { return preg_match('//u',$value)===1; }
    public static function closePayload(string $data): array
    {
        if($data==='')return [null,''];
        if(strlen($data)===1)throw new ProtocolException('Truncated WebSocket close code');
        $code=unpack('n',substr($data,0,2))[1];$reason=substr($data,2);
        if(!in_array($code,[1000,1001,1002,1003,1007,1008,1009,1010,1011,1012,1013,1014],true)&&!($code>=3000&&$code<=4999))
            throw new ProtocolException('Invalid WebSocket close code');
        if(!self::utf8($reason))throw new ProtocolException('Invalid UTF-8 close reason',1007);
        return [$code,$reason];
    }
}
