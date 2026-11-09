<?php
declare(strict_types=1);
// Requires the separately linked --wrap=getaddrinfo module, never the normal binary.
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Runtime,Scheduler};
use Asyncara\HTTP\HttpClient;
use Asyncara\Exception\{CancelledException,TimeoutException};
function check(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);}
function event($pipe):array {
    $line='';while(!str_ends_with($line,"\n")) {
        Scheduler::current()->readable($pipe);$part=fgets($pipe);if($part!==false)$line.=$part;
        check(!feof($pipe),'Resolver peer ended');
    }
    return json_decode($line,true,512,JSON_THROW_ON_ERROR);
}
$peer=proc_open(['python3',__DIR__.'/hyper_dns_server.py'],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',dirname(__DIR__,2).'/build/hyper-dns-gate.log','a']],$pipes);
try {
    $ports=json_decode(fgets($pipes[1]),true,512,JSON_THROW_ON_ERROR);stream_set_blocking($pipes[1],false);
    putenv('ASYNCARA_DNS_TEST_PORT='.$ports['dnsPort']);
    Async::run(function()use($ports,$pipes):void{
        Async::timeout(8,function()use($ports,$pipes):void{
            $url='http://dns-gated.invalid:'.$ports['port'].'/';
            foreach(['success','cancel','deadline'] as $mode) {
                $client=new HttpClient(concurrency:1,timeout:3,connectTimeout:$mode==='deadline'?.03:2);
                $before=asyncara_info()['hyperSubmitted'];$request=$client->get($url);
                check(event($pipes[1])===['dns'=>'waiting'],'Worker did not enter resolver barrier');
                for($tick=0;$tick<2;$tick++){Async::sleep(.002);check(!$request->isCompleted(),'Resolver completed before release');}
                check(asyncara_info()['pendingOperations']===1,'DNS job owner missing');
                if($mode==='cancel')$request->cancel();
                if($mode==='deadline')Async::sleep(.05); // Expire the declared deadline while the independent peer holds DNS.
                if($mode!=='success') {
                    Async::sleep(0);
                    check(!$request->isCompleted(),'Cancellation abandoned running worker');
                    check(asyncara_info()['hyperSubmitted']===$before,'HTTP began after DNS cancellation/deadline');
                }
                fwrite($pipes[0],"dns-release\n");
                try {
                    $response=$request->await();check($mode==='success','Cancelled/deadline request succeeded');
                    check($response->json()['host']==='dns-gated.invalid:'.$ports['port'],'Resolved Host changed');
                } catch(CancelledException $e) {
                    check(($mode==='cancel'&&!$e instanceof TimeoutException)||($mode==='deadline'&&$e instanceof TimeoutException),'Wrong cancellation/deadline error');
                }
                check(asyncara_info()['pendingOperations']===0,'DNS job not collected');
                check(asyncara_info()['hyperSubmitted']===$before+($mode==='success'?1:0),'Late DNS result submitted HTTP');
                check($client->get('http://localhost:'.$ports['port'].'/')->await()->status===200,'Admission permit not released');
            }
        });
    });
    check(asyncara_info()['nativeMemoryBytes']===0&&Runtime::diagnostics()['pendingFutures']===0,'Lifecycle ownership leaked');
}finally{
    fclose($pipes[0]);stream_set_blocking($pipes[1],true);stream_get_contents($pipes[1]);fclose($pipes[1]);check(proc_close($peer)===0,'Peer cleanup');
}
echo "PASS gated DNS progress, cancellation, deadline, late result rejection and permit release\n";
