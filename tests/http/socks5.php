<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,CancellationSource,Future,Runtime,Scheduler};
use Asyncara\HTTP\HttpClient;
use Asyncara\Exception\{OperationException,CancelledException};
$c=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function auth():array{return ['username'=>'user:name','password'=>'synthetic-secret'];}
function reject(#[\SensitiveParameter] callable $f,string $type=OperationException::class):void{
    $error=null;try{$f();}catch(Throwable $e){$error=$e;}
    check($error instanceof $type,'Expected '.$type.', got '.($error?->getMessage()??'success'));
    check(!str_contains((string)$error.print_r($error->getTrace(),true),auth()['password']),'Credential in error trace');
}
function clean():void{gc_collect_cycles();check(!asyncara_info()['pendingOperations']&&!asyncara_info()['nativeMemoryBytes']&&!Future::pendingCount(),'Native/Future leak');}
ini_set('zend.exception_ignore_args','0');$ticks=0;$checks=[];
$hyper=asyncara_info()['httpBackend']==='hyper-prototype';
$curlControl=in_array('--curl-control',$argv,true);check(!$curlControl||!$hyper,'Control requires curl');
Async::run(function()use($c,&$checks,&$ticks,$curlControl):void{
    $control=new HttpClient();
    foreach($curlControl?['socks5h']:['socks5h','socks5']as$scheme){
        $proxy=str_replace('socks5h://127.0.0.1',$scheme.'://localhost',$c['auth']);
        $user=auth()['username'];$password=auth()['password'];
        $client=new HttpClient(concurrency:4,timeout:5,proxy:$proxy,caFile:$c['ca'],proxyCredentials:['username'=>&$user,'password'=>&$password]);
        $user='changed';$password='changed';
        foreach(['http://'.($scheme==='socks5h'?'proxy-only.invalid':'localhost').':'.$c['http'],'https://localhost:'.$c['https']]as$url){
            $response=$client->get($url.'/ok')->await()->json();check($response['host']===parse_url($url,PHP_URL_HOST).':'.parse_url($url,PHP_URL_PORT),'Origin host changed');
            check($client->get($url.'/redirect',followRedirects:true)->await()->status===200,'Redirect');
            $bytes=0;$stream=$client->getStream($url.'/stream');$stream->consume(function(string$chunk)use(&$bytes){$bytes+=strlen($chunk);});check($bytes===524288,'Stream');
            $body=str_repeat("data\0",8192);$r=$client->post($url.'/echo',body:$body)->await()->json();check($r['sha256']===hash('sha256',$body),'Buffered upload');
            foreach([null,strlen($body)*2]as$length){$r=$client->requestStream('PUT',$url.'/echo',[$body,$body],$length)->await()->json();check($r['sha256']===hash('sha256',$body.$body),'Streamed upload');}
        }
        $checks[]=$scheme.'_dns_http_https_redirect_stream_upload';
    }
    foreach(['plain','v6reply','domainreply']as$mode){
        $client=new HttpClient(proxy:$c[$mode]);check($client->get('http://127.0.0.1:'.$c['http'].'/ok')->await()->status===200,'Bound address '.$mode);
    }
    check((new HttpClient(proxy:str_replace('socks5h://','SOcKs5H://',$c['plain'])))->get('http://proxy-only.invalid:'.$c['http'].'/ok')->await()->status===200,'Case-insensitive proxy scheme');
    if($c['ipv6'])check((new HttpClient(proxy:$c['plain']))->get('http://[::1]:'.$c['ipv6'].'/ok')->await()->status===200,'IPv6 CONNECT');
    foreach([['username'=>str_repeat('u',255),'password'=>str_repeat('p',255)],['username'=>'مستخدم','password'=>'كلمة']]as$credentials)
        check((new HttpClient(proxy:$c['auth'],proxyCredentials:$credentials))->get('http://127.0.0.1:'.$c['http'].'/ok')->await()->status===200,'RFC1929 octets');
    foreach([null,['username'=>'wrong','password'=>'wrong']]as$credentials)
        reject(fn()=>(new HttpClient(proxy:$c['auth'],proxyCredentials:$credentials))->get('http://127.0.0.1:'.$c['http'].'/ok')->await());
    $checks[]='address_frames_auth_limits_pool_identity';
    $trusted=new HttpClient(proxy:$c['plain'],caFile:$c['ca']);
    reject(fn()=>$trusted->get('https://proxy-only.invalid:'.$c['https'].'/ok')->await());
    reject(fn()=>(new HttpClient(proxy:$c['plain'],caFile:$c['wrongCa']))->get('https://localhost:'.$c['https'].'/ok')->await());
    check($trusted->get('https://localhost:'.$c['https'].'/ok')->await()->status===200,'TLS recovery');
    $checks[]='tls_hostname_ca_rejection_recovery';
    if($curlControl){
        check((new HttpClient(proxy:str_replace('socks5h://','socks5://',$c['plain'])))->get('http://127.0.0.1:'.$c['http'].'/ok')->await()->status===200,'Numeric local route');
        $checks[]='curl_control_remote_dns_numeric_local_only';
    }
});clean();
if($hyper)Async::run(function()use($c,&$checks):void{
    $proxy=str_replace('socks5h://','socks5://',$c['plain']);$url='http://proxy-only.invalid:'.$c['http'].'/ok';
    $options=['proxy'=>$proxy,'proxyResolvedAddresses'=>['127.0.0.2','127.0.0.1']];
    $result=Scheduler::current()->native('http',[$url,null,$options,2000])->await();
    check(json_decode($result['body'],true)['host']==='proxy-only.invalid:'.$c['http'],'Local fallback authority');
    // A changed DNS set must not silently reuse the earlier successful IP.
    $options['proxyResolvedAddresses']=['127.0.0.2'];
    reject(fn()=>Scheduler::current()->native('http',[$url,null,$options,2000])->await());
    foreach([[],['invalid'],array_fill(0,17,'127.0.0.1')]as$targets){
        reject(fn()=>Scheduler::current()->native('http',[$url,null,['proxy'=>$proxy,'proxyResolvedAddresses'=>$targets],1000])->await(),Throwable::class);
    }
    foreach([['proxy'=>$c['plain']],[],['proxy'=>'http://127.0.0.1:1']]as$base)
        reject(fn()=>Scheduler::current()->native('http',[$url,null,$base+['proxyResolvedAddresses'=>['127.0.0.1']],1000])->await(),ValueError::class);
    reject(fn()=>Scheduler::current()->native('http',['http://127.0.0.1:'.$c['http'].'/ok',null,['proxy'=>$proxy,'proxyResolvedAddresses'=>['127.0.0.2']],1000])->await());
    $checks[]='native_local_fallback_current_dns_pool_identity_invalid_vectors';
});clean();
Async::run(function()use($c,&$checks,&$ticks):void{
    $control=new HttpClient();$control->get($c['control'].'/reset')->await();
    $client=new HttpClient(concurrency:4,timeout:5,proxy:$c['held']);$url='http://proxy-only.invalid:'.$c['http'].'/ok';
    $pending=[$client->get($url),$client->get($url)];
    Async::timeout(2,function()use($control,$c):void{while($control->get($c['control'].'/state')->await()->json()['held']!==2)Async::sleep(0);});
    for($n=0;$n<2;$n++){Async::sleep(.005);foreach($pending as$f)check(!$f->isCompleted(),'Timer after SOCKS completion');$ticks++;}
    $token=new CancellationSource();$waiter=Async::spawn(fn()=>$pending[0]->await($token->token));Async::sleep(0);$token->cancel();
    reject($waiter->await(...),CancelledException::class);check(!$pending[0]->isCompleted(),'Waiter cancelled owner');
    $pending[1]->cancel();reject($pending[1]->await(...),CancelledException::class);
    $control->get($c['control'].'/release')->await();check($pending[0]->await()->status===200,'Sibling lost');
    $checks[]='two_ticks_during_pending_handshake_shared_waiter_owner_cancel';
});clean();
foreach($curlControl?[]:['version','method','denied','authversion','authdenied','shortauth','replyversion','reserved','atyp','emptydomain','short','shortaddr',...($hyper?['downgrade']:[])]as$mode){
    Async::run(function()use($c,$mode):void{
        (new HttpClient())->get($c['control'].'/mode/'.$mode)->await();
        $credentials=in_array($mode,['authversion','authdenied','shortauth','downgrade'],true)?auth():null;
        reject(fn()=>(new HttpClient(timeout:3,proxy:$c['fault'],proxyCredentials:$credentials))->get('http://127.0.0.1:'.$c['http'].'/ok')->await());
    });clean();$checks[]='reject_'.$mode;
}
Async::run(function()use($c,&$checks):void{
    (new HttpClient())->get($c['control'].'/mode/stall')->await();
    reject(fn()=>(new HttpClient(timeout:2,connectTimeout:.1,proxy:$c['fault']))->get('http://127.0.0.1:'.$c['http'].'/ok')->await());
    $control=new HttpClient();Async::timeout(2,function()use($control,$c):void{while($control->get($c['control'].'/state')->await()->json()['held']!==0)Async::sleep(0);});
    foreach(['username','password']as$key)foreach(['',str_repeat('x',256),"nul\0byte"]as$bad){$creds=auth();$creds[$key]=$bad;reject(fn()=>new HttpClient(proxy:$c['auth'],proxyCredentials:$creds),ValueError::class);}
    $checks[]='connect_deadline_cleanup_credential_validation';
});clean();
echo json_encode(['status'=>'PASS','ticks'=>$ticks,'checks'=>$checks,'native'=>asyncara_info(),'diagnostics'=>Runtime::diagnostics()]),"\n";
