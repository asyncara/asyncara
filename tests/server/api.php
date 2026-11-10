<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{App, Runtime};
use Asyncara\Server\{Request, Response, Route, Inbox};
function check(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
function rejects(callable $fn): void { try { $fn(); } catch (Throwable) { return; } throw new RuntimeException('Expected rejection'); }
$request = new Request('GET','/x?a=1&a=2&encoded=hello%20world&zero=0');
check($request->query('a')==='2' && $request->query('encoded')==='hello world' && $request->query('zero')==='0' && $request->query('absent',7)===7, 'Query semantics');
foreach ([new App(), new Route('GET','/x',fn()=>Response::text('ok')),new Inbox(),new Asyncara\Server\Workers(), Response::stream([])] as $object) {
    rejects(fn()=>clone $object); rejects(fn()=>serialize($object));
    rejects(fn()=>unserialize('O:'.strlen($object::class).':"'.$object::class.'":0:{}'));
}
$response=Response::json(['x'=>'مرحبا']); check((clone $response)->body===$response->body, 'Response value copy');
$app=new App();$app->get('/x',fn()=>Response::text('ok'));rejects(fn()=>$app->get('/x',fn()=>Response::text('duplicate')));
rejects(fn()=>new Route('GET','/{x}/{x}',fn()=>Response::text('bad')));
rejects(fn()=>Response::text('bad',204));
rejects(fn()=>Response::stream([],204));
foreach ([[], ['certificate'=>__FILE__], ['certificate'=>'https://example.test/cert', 'privateKey'=>'x'], ['unexpected'=>1]] as $tls) {
    rejects(fn()=>(new App())->listen('127.0.0.1:0', ['tls'=>$tls]));
}
$ignoreArgs = ini_set('zend.exception_ignore_args', '0');
asyncara_server_listen('127.0.0.1:0', 16, 1024, 1024, 65536, 1000, 10, false);
asyncara_server_close(); // request-owned engine/wakeup descriptors are a separate lifetime
$beforeFailedTls = count(scandir('/proc/self/fd'));
try {
    asyncara_server_listen('127.0.0.1:0', 16, 1024, 1024, 65536, 1000, 10, false, 'invalid-certificate', 'TEST-PRIVATE-KEY-SHOULD-BE-REDACTED');
    throw new RuntimeException('Expected invalid TLS rejection');
} catch (ValueError $e) {
    check(($e->getTrace()[0]['args'][9] ?? null) instanceof SensitiveParameterValue, 'TLS key exposed in exception trace');
    check(asyncara_server_info() === null && count(scandir('/proc/self/fd')) === $beforeFailedTls, 'Failed TLS listener leaked resources');
} finally { ini_set('zend.exception_ignore_args', (string)$ignoreArgs); }
$bad=new App();$bad->get('/x',fn()=>Response::text('no'))->middleware('unregistered');rejects(fn()=>$bad->listen('127.0.0.1:0'));
$badLimit=new App();rejects(fn()=>$badLimit->listen('127.0.0.1:0',['connections'=>16384],fn()=>$badLimit->stop()));
$fds = null;
for($i=0;$i<50;$i++) {
    $server=new App();$server->get('/',fn()=>Response::text('ok'));
    $server->listen('127.0.0.1:0',[],static function()use($server):void { $server->stop(); $server->stop(); });
    check(asyncara_server_info()===null,'Server owner retained');
    $count=count(scandir('/proc/self/fd'));$fds??=$count;check($count===$fds,'Descriptor leak');
    $metrics=Runtime::diagnostics();
    foreach(['activeTasks','activeTimers','ioWatchers','pendingNative','pendingFutures'] as$key)check($metrics[$key]===0,$key);
}
check(!asyncara_server_can_fork(),'Initialized native runtime must reject unsafe prefork');
echo json_encode(['status'=>'PASS','cycles'=>50,'descriptorsIncludingDotEntries'=>$fds,'runtime'=>$metrics]),"\n";
