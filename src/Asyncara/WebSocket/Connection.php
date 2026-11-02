<?php
declare(strict_types=1);
namespace Asyncara\WebSocket;
use Asyncara\{Async,Future,Task};
use Asyncara\Concurrency\{Lock,Queue};
use Asyncara\Exception\{CancelledException,OperationException,QueueClosedException};
use Asyncara\IO\TcpStream;
final class Connection
{
    private FrameCodec $codec;
    private Queue $messages;
    private Lock $writer;
    private Lock $messageWriter;
    private Task $reader;
    private Future $closed;
    private bool $stopped=false,$closeSent=false,$closeReceived=false,$receiving=false;
    private ?\Throwable $failure=null;
    private ?int $fragmentType=null;
    private string $fragment='';
    private int $queuedBytes=0;
    private array $pings=[];
    /** @internal Owns the transport after the upgrade. */
    public function __construct(private TcpStream $stream,private bool $client,public readonly ?string $protocol=null,
        private int $messageLimit=1048576,private int $queueLimit=16,private int $queueBytes=4194304,
        private float $idleTimeout=30,private float $writeTimeout=30,string $initial='')
    {
        if($messageLimit<1||$messageLimit>16777216||$queueLimit<1||$queueLimit>1024||$queueBytes<1||$queueBytes>67108864||
           !is_finite($idleTimeout)||$idleTimeout<=0||$idleTimeout>3600||!is_finite($writeTimeout)||$writeTimeout<=0||$writeTimeout>3600)throw new \ValueError('Invalid WebSocket limits');
        $this->codec=new FrameCodec(!$client,$messageLimit);$this->codec->push($initial);
        $this->messages=new Queue($queueLimit);$this->writer=new Lock();$this->messageWriter=new Lock();$this->closed=new Future();
        $this->reader=Async::spawn($this->readLoop(...));
    }
    private function readLoop(): void
    {
        try{
            while(!$this->stopped){
                $frames=0;
                while(($frame=$this->codec->next())!==null){
                    $this->frame($frame);if($this->stopped)return;
                    if(++$frames%32===0)Async::sleep(0);
                }
                $data=Async::timeout($this->idleTimeout,fn()=>$this->stream->read());
                if($data==='')throw new ProtocolException('Abnormal EOF without WebSocket close',1006);
                $this->codec->push($data);Async::sleep(0);
            }
        }catch(\Throwable $error){
            $this->failure=$error;
            if($error instanceof ProtocolException && $error->closeCode!==1006 && !$this->closeSent){
                try{$this->closeSent=true;$this->control(8,pack('n',$error->closeCode));}catch(\Throwable){}
            }
        }finally{$this->finish();}
    }
    private function frame(array $frame): void
    {
        $op=$frame['opcode'];$data=$frame['data'];
        if($op===8){
            FrameCodec::closePayload($data);$this->closeReceived=true;
            if(!$this->closeSent){$this->closeSent=true;$this->control(8,$data);}
            $this->finish();return;
        }
        if($op===9){$this->control(10,$data);return;}
        if($op===10){$key=bin2hex($data);if(isset($this->pings[$key])){$future=$this->pings[$key];unset($this->pings[$key]);$future->resolve();}return;}
        if($this->closeSent)return; // RFC: no further application data after close.
        if($op===0){if($this->fragmentType===null)throw new ProtocolException('Unexpected continuation');}
        else{if($this->fragmentType!==null)throw new ProtocolException('New message during fragmentation');$this->fragmentType=$op;}
        if(strlen($data)>$this->messageLimit-strlen($this->fragment))throw new ProtocolException('WebSocket message exceeds capacity',1009);
        $this->fragment.=$data;
        if(!$frame['fin'])return;
        $message=new Message($this->fragment,$this->fragmentType===2);$this->fragment='';$this->fragmentType=null;
        if(!$message->binary&&!FrameCodec::utf8($message->data))throw new ProtocolException('Invalid WebSocket text UTF-8',1007);
        $size=strlen($message->data);
        if($size>$this->queueBytes-$this->queuedBytes||!$this->messages->tryPut($message))throw new ProtocolException('WebSocket slow consumer exceeded queue capacity',1008);
        $this->queuedBytes+=$size;
    }
    private function control(int $opcode,string $payload): void
    {
        Async::timeout($this->writeTimeout,fn()=>$this->writer->synchronized(fn()=>$this->stream->write(FrameCodec::encode($opcode,$payload,$this->client))));
    }
    public function receive(): ?Message
    {
        if($this->receiving)throw new \LogicException('WebSocket already has a receiver');$this->receiving=true;
        try{
            try{$message=$this->messages->get();}catch(QueueClosedException){if($this->failure)throw $this->failure;return null;}
            $this->messages->taskDone();$this->queuedBytes-=strlen($message->data);return $message;
        }finally{$this->receiving=false;}
    }
    public function send(string $data,bool $binary=false): void
    {
        if($this->stopped||$this->closeSent)throw new OperationException('WebSocket is closing');
        if(strlen($data)>$this->messageLimit||(!$binary&&!FrameCodec::utf8($data)))throw new \ValueError('Invalid or oversized WebSocket message');
        try{
            Async::timeout($this->writeTimeout,fn()=>$this->messageWriter->synchronized(function()use($data,$binary):void{
                if($this->stopped||$this->closeSent)throw new OperationException('WebSocket is closing');
                $length=strlen($data);$offset=0;
                do{$chunk=substr($data,$offset,65536);$final=$offset+strlen($chunk)===$length;
                    $this->writer->synchronized(function()use($offset,$binary,$chunk,$final):void{
                        if($this->stopped||$this->closeSent)throw new OperationException('WebSocket closed during message send');
                        $this->stream->write(FrameCodec::encode($offset?0:($binary?2:1),$chunk,$this->client,$final));
                    });$offset+=strlen($chunk);
                    if($offset<$length)Async::sleep(0); // Control frames may pass between data fragments.
                }while($offset<$length);
            }));
        }catch(\Throwable $error){$this->failure??=$error;$this->abort();throw $error;}
    }
    public function ping(?string $payload=null,float $timeout=5): Future
    {
        $payload??=random_bytes(8);
        if(strlen($payload)>125||!is_finite($timeout)||$timeout<=0||$timeout>3600)throw new \ValueError('Invalid WebSocket ping');
        return Async::spawn(function()use($payload,$timeout):void{
            if($this->stopped||$this->closeSent)throw new OperationException('WebSocket is closing');
            $key=bin2hex($payload);
            if(isset($this->pings[$key])||count($this->pings)>=16)throw new \OverflowException('WebSocket pending ping limit or duplicate payload');
            $future=new Future();$this->pings[$key]=$future;
            try{Async::timeout($timeout,function()use($payload,$future):void{$this->control(9,$payload);$future->await();});}
            finally{unset($this->pings[$key]);}
        });
    }
    public function close(int $code=1000,string $reason='',float $timeout=5): void
    {
        if($code<1000||$code>4999)throw new \ValueError('Invalid WebSocket close code');
        $payload=pack('n',$code).$reason;FrameCodec::closePayload($payload);
        if(strlen($payload)>125||!is_finite($timeout)||$timeout<=0||$timeout>3600)throw new \ValueError('Invalid WebSocket close');
        try{
            if(!$this->stopped)Async::timeout($timeout,function()use($payload):void{
                if(!$this->closeSent){$this->closeSent=true;$this->control(8,$payload);}$this->closed->await();
            });
        }finally{
            $this->abort();
            Async::shield(function():void{try{$this->reader->await();}catch(CancelledException){}});
        }
    }
    private function finish(): void
    {
        if($this->stopped)return;$this->stopped=true;$this->stream->close();$this->codec->clear();$this->fragment='';
        $this->messages->close();
        foreach($this->pings as$ping)$ping->reject(new OperationException('WebSocket closed before pong'));$this->pings=[];
        if($this->failure)$this->closed->reject($this->failure);else $this->closed->resolve();
    }
    public function abort(): void { $this->finish();$this->reader->cancel(); }
    public function exception(): ?\Throwable { return $this->failure; }
    public function statistics(): array { return ['closed'=>$this->stopped,'closeAcknowledged'=>$this->closeReceived,'queuedMessages'=>$this->messages->count(),'queuedBytes'=>$this->queuedBytes,'pendingPings'=>count($this->pings)]; }
}
