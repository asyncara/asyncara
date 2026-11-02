<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Asyncara\WebSocket\{FrameCodec,ProtocolException,Handshake};
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$cases=0;
foreach([false,true]as$mask)foreach([0,1,125,126,65535,65536,1048576]as$size){
    $data=substr(str_repeat("a\0\xffb",intdiv($size+3,4)),0,$size);$encoded=FrameCodec::encode(2,$data,$mask);
    $parser=new FrameCodec($mask);
    for($i=0;$i<strlen($encoded);$i+=997){$parser->push(substr($encoded,$i,997));$frame=$parser->next();if($i+997<strlen($encoded))check($frame===null,'premature frame');}
    check($frame===['opcode'=>2,'fin'=>true,'data'=>$data],'frame roundtrip');check($parser->next()===null,'duplicate completion');$cases++;
}
$bad=["\xc1\x00","\x83\x00","\x09\x00","\x89\x7e","\x81\x80abcd","\x82\x7e\x00\x7d","\x82\x7f".pack('NN',0,65535),"\x82\x7f".pack('NN',0x80000000,0),"\x82\x7f".pack('NN',1,0),"\x82\x7f".pack('NN',0,1048577)];
foreach($bad as$packet){$parser=new FrameCodec(false);try{$parser->push($packet);$parser->next();throw new RuntimeException('malformed frame accepted');}catch(ProtocolException){$cases++;}}
$packet=FrameCodec::encode(1,'test',true);
for($i=0;$i<strlen($packet);$i++){$parser=new FrameCodec(true);$parser->push(substr($packet,0,$i));check($parser->next()===null,'truncated packet completed');$cases++;}
foreach(['x',pack('n',1005),pack('n',1006),pack('n',1015),pack('n',2000),pack('n',5000),pack('n',1000)."\xff"]as$close){try{FrameCodec::closePayload($close);throw new RuntimeException('bad close accepted');}catch(ProtocolException){$cases++;}}
check(Handshake::accept('dGhlIHNhbXBsZSBub25jZQ==')==='s3pPLMBiTxaQ9kYGzzhZRbK+xOo=','RFC handshake vector');
echo "WebSocket framing, lengths, masking, truncation and close validation: PASS $cases cases\n";
