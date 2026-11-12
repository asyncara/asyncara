<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Runtime,Scheduler};
use Asyncara\HTTP\HttpClient;
use Asyncara\Exception\{OperationException,CancelledException};
function step(string $name):void { if(getenv('ASYNCARA_MTLS_DIAGNOSTIC')==='1') echo json_encode(['step'=>$name,'monotonicNs'=>hrtime(true),'cpu'=>getrusage(),'native'=>asyncara_info()]),"\n"; }
function verify(bool $v,string $why):void { if (!$v) throw new RuntimeException($why); }
function rejected(callable $f):void { try { $f(); } catch (OperationException|ValueError $e) { return; } throw new RuntimeException('Invalid TLS identity accepted'); }
function peerEvent($p):array {
    $line='';while(!str_ends_with($line,"\n")){Scheduler::current()->readable($p);$s=fgets($p);if($s!==false)$line.=$s;verify(!feof($p),'Peer ended');}
    return json_decode($line,true,512,JSON_THROW_ON_ERROR);
}
$c=$argv[1];$out=$argv[2];$transport=$argv[3];
if(getenv('ASYNCARA_MTLS_DIAGNOSTIC')==='1') Asyncara\Trace::enable(4096);
verify(asyncara_info()['httpBackend']===$transport,'Transport identity');
$ids=json_decode(file_get_contents($c.'/identities.json'),true,512,JSON_THROW_ON_ERROR);
$peer=proc_open(['python3',__DIR__.'/client_mtls_peer.py',$c],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',$out.'/peer.log','w']],$pipes);
try {
    $ports=json_decode(fgets($pipes[1]),true,512,JSON_THROW_ON_ERROR);stream_set_blocking($pipes[1],false);
    Async::run(function()use($c,$out,$ids,$ports,$pipes):void {
        Async::timeout(20,function()use($c,$out,$ids,$ports,$pipes):void {
            $make=static fn(string $cert='client',?string $key=null) => new HttpClient(timeout:3,caFile:$c.'/ca.pem',clientCertFile:$c.'/'.$cert.'.pem',clientKeyFile:$c.'/'.($key??$cert).'.key');
            $first=$make();$second=$make('client2');
            if(getenv('ASYNCARA_MTLS_MEMORY_WARMUP')==='1') {
                // Separate memory-diagnostic mode. Preserve the cold result;
                // neither change deadlines nor call this an acceptance pass.
                $start=hrtime(true);$cold='PASS';
                try{$first->get('https://localhost:'.$ports['TLSv1_2'])->await();}
                catch(OperationException $e){if($e->getMessage()!=='HTTP operation timed out')throw $e;$cold='TIMED_OUT';}
                verify(!asyncara_info()['pendingOperations']&&!asyncara_info()['nativeMemoryBytes'],'Cold completion cleanup');
                echo json_encode(['memoryWarmup'=>$cold,'seconds'=>(hrtime(true)-$start)/1e9]),"\n";
            }
            foreach($ports as$version=>$port) {
                $url='https://localhost:'.$port;
                step($version.'-first-start');$a=$first->get($url)->await()->json();step($version.'-first-done');$b=$first->get($url)->await()->json();$other=$second->get($url)->await()->json();step($version.'-second-done');
                verify($a['certificate']===$ids['client']&&$other['certificate']===$ids['client2'],'Wrong authenticated client');
                verify($a['connection']===$b['connection']&&$other['connection']!==$a['connection'],'Identity pool separation');
                rejected(fn()=>(new HttpClient(timeout:2,caFile:$c.'/ca.pem'))->get($url)->await());
                rejected(fn()=>$make('badclient')->get($url)->await());
                rejected(fn()=>$make('client','client2')->get($url)->await());
                rejected(fn()=>$make('server')->get($url)->await());
                rejected(fn()=>$make('expired','client')->get($url)->await());
                verify($first->get($url)->await()->json()['certificate']===$ids['client'],'Invalid policy corrupted existing pool');
            }
            step('tls-identities-done');$url='https://127.0.0.1:'.$ports['TLSv1_3'];
            verify($first->get($url.'/redirect',followRedirects:true)->await()->json()['certificate']===$ids['client'],'Same-origin authenticated redirect');
            rejected(fn()=>$first->get($url.'/cross',followRedirects:true)->await());
            step('redirects-done');$body=str_repeat("a\0b",8192);$result=$first->request('POST',$url,$body)->await()->json();
            verify($result['sha256']===hash('sha256',$body)&&$result['method']==='POST','Buffered upload');
            $chunks=(static function()use($body){for($i=0;$i<20;$i++)yield $body;})();
            $result=$first->requestStream('PUT',$url,$chunks)->await()->json();
            verify($result['sha256']===hash('sha256',str_repeat($body,20)),'Streaming upload');
            step('uploads-done');$stream=$first->getStream($url.'/large');$hash=hash_init('sha256');$size=0;
            foreach($stream as$chunk){hash_update($hash,$chunk);$size+=strlen($chunk);Async::sleep(.0001);}
            verify($size===1048576&&hash_final($hash)==='fbbab289f7f94b25736c58be46a994c441fd02552cc6022352e3d86d2fab7c83','Streaming response');
            step('streams-done');$pending=[$first->get($url.'/hold'),$second->get($url.'/hold')];
            for($i=0;$i<2;$i++)verify(isset(peerEvent($pipes[1])['pending']),'Missing barrier');
            for($i=0;$i<2;$i++){Async::sleep(.002);foreach($pending as$f)verify(!$f->isCompleted(),'I/O completed before two ticks');}
            $waiter=Async::spawn(fn()=>$pending[0]->await());Async::sleep(0);$waiter->cancel();
            try{$waiter->await();throw new RuntimeException('Cancelled waiter succeeded');}catch(CancelledException){}
            verify(!$pending[0]->isCompleted(),'Waiter cancelled owner');
            $pending[1]->cancel();try{$pending[1]->await();throw new RuntimeException('Cancelled owner succeeded');}catch(CancelledException){}
            fwrite($pipes[0],"release\n");verify($pending[0]->await()->json()['certificate']===$ids['client'],'Held completion');
            verify($second->get($url)->await()->json()['certificate']===$ids['client2'],'Cancellation recovery');
            // Filename ownership at configuration time; bytes snapshot on first
            // use. A newly-created client must observe rotated file contents.
            step('cancellation-done');$cert=$out.'/identity.pem';$key=$out.'/identity.key';
            Asyncara\IO\Files::write($cert,file_get_contents($c.'/client.pem'))->await();
            Asyncara\IO\Files::write($key,file_get_contents($c.'/client.key'))->await();
            $old=getcwd();chdir($out);$rotation=new HttpClient(caFile:$c.'/ca.pem',clientCertFile:'identity.pem',clientKeyFile:'identity.key');chdir($old);
            verify($rotation->get($url)->await()->json()['certificate']===$ids['client'],'Captured filenames');
            Asyncara\IO\Files::write($cert,file_get_contents($c.'/client2.pem'))->await();
            Asyncara\IO\Files::write($key,file_get_contents($c.'/client2.key'))->await();
            $new=new HttpClient(caFile:$c.'/ca.pem',clientCertFile:$cert,clientKeyFile:$key);
            verify($new->get($url)->await()->json()['certificate']===$ids['client2'],'New identity after rotation');
            verify($rotation->get($url)->await()->json()['certificate']===$ids['client'],'Existing snapshot changed');
            step('rotation-done');rejected(fn()=>new HttpClient(clientCertFile:$cert));
            rejected(fn()=>$first->get('http://127.0.0.1:9')->await());
            foreach(['garbage',str_repeat('x',65537),file_get_contents($c.'/client.key').file_get_contents($c.'/client2.key')]as$i=>$bad) {
                // Multiple private keys are specifically rejected by Hyper.
                if($i===2&&asyncara_info()['httpBackend']!=='hyper-prototype')continue;
                $path=$out.'/invalid-'.$i.'.key';Asyncara\IO\Files::write($path,$bad)->await();
                rejected(fn()=>(new HttpClient(caFile:$c.'/ca.pem',clientCertFile:$c.'/client.pem',clientKeyFile:$path))->get($url)->await());
            }
        });
    });
    $n=asyncara_info();$d=Runtime::diagnostics();verify(!$n['pendingOperations']&&!$n['nativeMemoryBytes']&&!$n['openFileHandles'],'Native ownership');
    foreach(['activeTasks','pendingFutures','ioWatchers','activeTimers']as$k)verify($d[$k]===0,$k);
    verify($n['hyperSubmitted']===$n['hyperCompleted'],'Completion identity');
    echo json_encode(['status'=>'PASS','mode'=>getenv('ASYNCARA_MTLS_MEMORY_WARMUP')==='1'?'WARMED_MEMORY_DIAGNOSTIC':'DEFAULT_FIXTURE','twoTicksWhilePending'=>true,'native'=>$n,'runtime'=>$d]),"\n";
}finally {
    if(getenv('ASYNCARA_MTLS_DIAGNOSTIC')==='1'){step('finally');Asyncara\Trace::dump($out.'/trace.json');}
    fclose($pipes[0]);stream_set_blocking($pipes[1],true);stream_get_contents($pipes[1]);fclose($pipes[1]);verify(proc_close($peer)===0,'Peer shutdown');
}
