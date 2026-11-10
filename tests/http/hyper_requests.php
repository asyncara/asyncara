<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async, Runtime, Scheduler};
use Asyncara\HTTP\HttpClient;
use Asyncara\Exception\{CancelledException, OperationException};
function check(bool $v,string $why):void { if(!$v) throw new RuntimeException($why); }
function step(string $label):void { if(getenv('ASYNCARA_REQUEST_TEST_TRACE')) echo json_encode(['step'=>$label,'time'=>Scheduler::now(),'native'=>asyncara_info()]),"\n"; }
function event($pipe):array {
    $line='';while(!str_ends_with($line,"\n")) {Scheduler::current()->readable($pipe);$part=fgets($pipe);if($part!==false)$line.=$part;check(!feof($pipe),'Peer ended');}
    return json_decode($line,true,512,JSON_THROW_ON_ERROR);
}
[$script,$certs,$out]=$argv;Runtime::configure(['httpMaxConnections'=>8,'httpMaxHostConnections'=>4]);
check(asyncara_info()['httpBackend']==='hyper-prototype','Expected Hyper');
$peer=proc_open(['python3',__DIR__.'/hyper_requests_peer.py',$certs],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',$out.'/peer.log','w']],$pipes);
try {
    $ports=json_decode(fgets($pipes[1]),true,512,JSON_THROW_ON_ERROR);stream_set_blocking($pipes[1],false);
    Async::run(function()use($ports,$pipes,$certs):void {
        foreach(['http','https'] as $scheme) {
            $client=new HttpClient(concurrency:4,caFile:$scheme==='https'?$certs.'/ca.pem':null);
            foreach(['127.0.0.1','localhost'] as $host) {
                $url=$scheme.'://'.$host.':'.$ports[$scheme];
                step($scheme.'-'.$host.'-json');
                $json=$client->post($url.'/echo',json:['hello'=>'world'])->await()->json();
                check($json['method']==='POST' && json_decode($json['body'],true)===['hello'=>'world'],'JSON POST');
                $form=$client->post($url.'/echo',form:['a'=>'x y','b'=>'&'])->await()->json();
                check($form['body']==='a=x%20y&b=%26','Form encoding');
                $id=$json['connection'];
                foreach(['GET','POST','PUT','PATCH','DELETE','OPTIONS','SEARCH'] as $method) {
                    $body=$method==='GET'?null:"a\0b\xff";
                    $headers=['X-Repeat: one','X-Repeat: two','Content-Type: application/octet-stream','Authorization: fixture-only'];
                    $f=$client->request($method,$url.'/echo?x=1',$body,$headers);$headers[0]='X-Repeat: changed';
                    $v=$f->await()->json();check($v['method']===$method && $v['path']==='/echo?x=1','Method/path');
                    check($v['headers']['x-repeat']===['one','two'] && $v['headers']['authorization']===['fixture-only'],'Header identity');
                    check($v['bytes']===strlen($body??'') && $v['sha256']===hash('sha256',$body??''),'Binary body identity');
                    check($v['connection']===$id,'Method changed pool ownership');
                }
                $head=$client->request('HEAD',$url.'/echo')->await();check($head->body==='' && $head->headers['content-length']===['123'],'HEAD framing');
                check($client->post($url.'/echo',body:'')->await()->json()['bytes']===0,'Empty POST');
            }
            $url=$scheme.'://127.0.0.1:'.$ports[$scheme];$body=str_repeat('abcd',2097152);
            $holdUrl=$scheme.'://127.0.0.1:'.$ports[$scheme.'Hold'];
            step($scheme.'-large-upload');
            $owner=$client->request('PUT',$holdUrl.'/hold/'.$scheme,$body);$barrier=event($pipes[1]);
            check($barrier===['uploadPending'=>$scheme,'bytes'=>strlen($body)],'Upload barrier');
            for($i=0;$i<2;$i++){Async::sleep(.002);check(!$owner->isCompleted(),'Upload completed before release');}
            $waiter=Async::spawn(fn()=>$owner->await());Async::sleep(0);$waiter->cancel();
            try{$waiter->await();throw new RuntimeException('Waiter cancel lost');}catch(CancelledException){}
            check(!$owner->isCompleted(),'Waiter cancelled upload owner');fwrite($pipes[0],$scheme."\n");
            step($scheme.'-large-upload-released');
            $v=$owner->await()->json();check($v['bytes']===strlen($body)&&$v['sha256']===hash('sha256',$body),'Large upload identity');
            step($scheme.'-cancel');
            $cancel=$client->post($scheme.'://localhost:'.$ports[$scheme.'Hold'].'/hold/cancel-'.$scheme,body:$body);check(event($pipes[1])['uploadPending']==='cancel-'.$scheme,'Cancel barrier');
            $cancel->cancel();try{$cancel->await();throw new RuntimeException('Owner cancel lost');}catch(CancelledException){}
            fwrite($pipes[0],'cancel-'.$scheme."\n");
            step($scheme.'-held-rejection');
            $earlyUrl=$scheme.'://127.0.0.1:'.$ports[$scheme.'Early'];
            $early=$client->post($earlyUrl.'/early/early-'.$scheme,body:$body);
            check(event($pipes[1])===['early'=>'early-'.$scheme,'bytes'=>strlen($body)],'Early keep-alive barrier');
            for($i=0;$i<2;$i++){Async::sleep(.002);check(!$early->isCompleted(),'Upload ownership ended early');check(asyncara_info()['nativeMemoryBytes']>=strlen($body),'Upload lost accounting');}
            fwrite($pipes[0],'early-'.$scheme."\n");$v=$early->await()->json();
            check(event($pipes[1])===['finished'=>'early-'.$scheme],'Early upload body identity');
            check($client->post($earlyUrl.'/echo',body:'early-recovery')->await()->json()['connection']===$v['connection'],'Early keep-alive reuse');
            step($scheme.'-late-cancel');
            // A different origin forces a fresh, initially fixed-window socket.
            $late=$client->post($scheme.'://localhost:'.$ports[$scheme.'Early'].'/early/late-'.$scheme,body:$body);
            check(event($pipes[1])===['early'=>'late-'.$scheme,'bytes'=>strlen($body)],'Late cancellation barrier');
            for($i=0;$i<2;$i++){Async::sleep(.002);check(!$late->isCompleted(),'Late cancellation lost pending owner');}
            $late->cancel();try{$late->await();throw new RuntimeException('Late cancellation lost');}catch(CancelledException){}
            fwrite($pipes[0],'late-'.$scheme."\n");
            step($scheme.'-late-cancel-completed');
            check($client->post($url.'/reject-held/'.$scheme,body:$body)->await()->status===413,'Held early response');
            step($scheme.'-held-reject-response');
            check($client->post($url.'/echo',body:'held-recovery')->await()->json()['body']==='held-recovery','Held recovery');
            fwrite($pipes[0],"reject-".$scheme."\n");
            step($scheme.'-early-reject');
            try{$r=$client->post($url.'/reject',body:$body)->await();check($r->status===413,'Early response');step($scheme.'-reject-response');}catch(OperationException $e){step($scheme.'-reject-error-'.$e->getMessage());check(!str_contains($e->getMessage(),'timed out'),'Early response lost completion');}
            step($scheme.'-recovery');
            check($client->post($url.'/echo',body:'recovered')->await()->json()['body']==='recovered','Recovery');
            step($scheme.'-limits');
            unset($body,$owner,$cancel);
            foreach(['Host: attacker.invalid','Content-Length: 1','Transfer-Encoding: chunked','Connection: upgrade','Upgrade: websocket','Expect: 100-continue','Trailer: x','TE: trailers','Accept-Encoding: gzip','Proxy-Authorization: x','X-Empty:','NoColon'] as $header) {
                $before=asyncara_info()['hyperSubmitted'];
                try{$client->get($url.'/echo',[$header])->await();throw new RuntimeException('Unsafe header accepted');}catch(OperationException|ValueError){}
                check(asyncara_info()['hyperSubmitted']===$before,'Invalid header reached connection');
            }
            $before=asyncara_info()['hyperSubmitted'];
            try{$client->request('PUT',$url.'/echo',str_repeat('x',16777217))->await();throw new RuntimeException('Oversized body accepted');}catch(OperationException|ValueError){}
            check(asyncara_info()['hyperSubmitted']===$before,'Oversized body admitted');
            foreach(['CONNECT','TRACE'] as $method) {
                $before=asyncara_info()['hyperSubmitted'];
                try{$client->request($method,$url.'/echo')->await();throw new RuntimeException('Unsupported method');}catch(OperationException|ValueError){}
                check(asyncara_info()['hyperSubmitted']===$before,'Invalid method admitted');
            }
        }
    });
    $n=asyncara_info();$d=Runtime::diagnostics();check($n['hyperSubmitted']===$n['hyperCompleted'],'Completion identity');
    check(!$n['pendingOperations']&&!$n['nativeMemoryBytes']&&!$n['openFileHandles'],'Native ownership');
    foreach(['activeTasks','pendingFutures','ioWatchers','activeTimers']as$k)check($d[$k]===0,$k);
    echo json_encode(['status'=>'PASS','ticksWhileUploadPending'=>2,'native'=>$n,'runtime'=>$d]),"\n";
} finally {
    fclose($pipes[0]);stream_set_blocking($pipes[1],true);stream_get_contents($pipes[1]);fclose($pipes[1]);check(proc_close($peer)===0,'Peer exit');
}
