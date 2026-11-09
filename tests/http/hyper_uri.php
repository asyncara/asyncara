<?php
declare(strict_types=1);
// Inspect admission before driving the engine, then cancel/collect. No socket is
// opened even on the vulnerable implementation: never probe a host's port 80.
function check(bool $v,string $why):void{if(!$v)throw new RuntimeException($why);}
check(asyncara_info()['httpBackend']==='hyper-prototype','Expected Hyper');
foreach(['http://127.0.0.1:65536/','http://127.0.0.1:999999999999/','http://[::1]:65536/',
         'http://127.0.0.1:abc/','http://127.0.0.1:/','http://user:password@127.0.0.1/']as$url){
    $before=asyncara_info()['hyperSubmitted'];
    $id=asyncara_submit('http',[$url,null,['method'=>'GET','headers'=>[]],1000]);
    $after=asyncara_info();asyncara_cancel($id);$result=asyncara_poll([],[],0);
    check(count($result['results'])===1&&$result['results'][0]['error']!==null,'Invalid URI did not complete with error');
    check($after['hyperConnections']===0&&asyncara_info()['pendingOperations']===0,'URI validation opened or retained resources');
    check($after['hyperSubmitted']===$before,'Invalid authority admitted; would silently fall back to port 80');
}
foreach(['http://127.0.0.1/','http://127.0.0.1:80/','http://127.0.0.1:65535/','http://[::1]/','http://[::1]:80/']as$url){
    $before=asyncara_info()['hyperSubmitted'];
    $id=asyncara_submit('http',[$url,null,['method'=>'GET','headers'=>[]],1000]);
    $after=asyncara_info();asyncara_cancel($id);$result=asyncara_poll([],[],0);
    check($after['hyperSubmitted']===$before+1,'Valid numeric authority rejected');
    check(count($result['results'])===1&&$result['results'][0]['error']==='Operation cancelled','Cancellation collection');
}
check(asyncara_info()['hyperConnections']===0&&asyncara_info()['nativeMemoryBytes']===0,'Validation control leaked');
echo "PASS Hyper authority bounds and admission without network activity\n";
