<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\WebSocket\{FrameCodec,ProtocolException};
$seconds=(float)($argv[1]??10);$maximum=(int)($argv[2]??1000000);$output=$argv[3]??null;
if($seconds<=0||$maximum<1||$output===null||file_exists($output))throw new ValueError('Use positive limits and a new evidence directory');
mkdir($output,0700,true);$corpus=[];foreach(glob(__DIR__.'/corpus/websocket/*')as$file)$corpus[]=file_get_contents($file);
mt_srand(20261102);$start=hrtime(true);$runs=0;$accepted=0;$rejected=0;
while($runs<$maximum&&(hrtime(true)-$start)/1e9<$seconds){
    $packet=$corpus[mt_rand(0,count($corpus)-1)];
    for($i=0,$changes=mt_rand(1,5);$i<$changes;$i++){
        switch(mt_rand(0,3)){
            case 0:if($packet!=='')$packet[mt_rand(0,strlen($packet)-1)]=chr(mt_rand(0,255));break;
            case 1:$packet=substr($packet,0,mt_rand(0,strlen($packet)));break;
            case 2:if(strlen($packet)<2048)$packet.=chr(mt_rand(0,255));break;
            case 3:if(strlen($packet)<1024)$packet.=$packet;break;
        }
    }
    $masked=(bool)mt_rand(0,1);$parser=new FrameCodec($masked,131072);
    // Retain the exact most recent input if the instrumented interpreter dies.
    file_put_contents($output.'/current-input',chr((int)$masked).$packet);
    try{
        $frames=0;
        for($i=0;$i<strlen($packet);){$n=mt_rand(1,31);$parser->push(substr($packet,$i,$n));$i+=$n;
            while(($frame=$parser->next())!==null){
                if(++$frames>1024)throw new RuntimeException('Unbounded parser output');
                $encoded=FrameCodec::encode($frame['opcode'],$frame['data'],$masked,$frame['fin']);
                $again=new FrameCodec($masked,131072);$again->push($encoded);
                if($again->next()!==$frame||$again->next()!==null)throw new RuntimeException('Parser roundtrip mismatch');
                if($frame['opcode']===8)FrameCodec::closePayload($frame['data']);
            }
        }$accepted++;
    }catch(ProtocolException){$rejected++;}
    catch(Throwable $error){file_put_contents($output.'/crash-'.hash('sha256',$packet),chr((int)$masked).$packet);throw$error;}
    $runs++;if($runs%128===0)gc_collect_cycles();
}
preg_match('/VmHWM:\s+(\d+)/',file_get_contents('/proc/self/status'),$rss);
$result=['seed'=>20261102,'executions'=>$runs,'seconds'=>(hrtime(true)-$start)/1e9,'acceptedOrIncomplete'=>$accepted,'protocolRejections'=>$rejected,'peakPhpBytes'=>getenv('USE_ZEND_ALLOC')==='0'?null:memory_get_peak_usage(true),'rssPeakKiB'=>(int)$rss[1],'findings'=>[],'coverage'=>'deterministic mutations; not coverage-guided; framing and close payloads only'];
file_put_contents($output.'/report.json',json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");echo json_encode($result,JSON_THROW_ON_ERROR),"\n";
