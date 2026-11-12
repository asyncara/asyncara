<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/autoload.php';
use Asyncara\{App, Async, Runtime};
use Asyncara\Server\{Request, Response};
$app = new App(); $release = new Asyncara\Concurrency\Event();
$ticks = $pending = $cancelled = $echoes = 0;
$app->get('/hold', function () use (&$ticks, &$pending, &$cancelled, $release) {
    $pending++;
    try { Async::sleep(.002); $ticks++; Async::sleep(.002); $ticks++; $release->wait(); return Response::text('held'); }
    catch (Asyncara\Exception\CancelledException $e) { $cancelled++; throw $e; }
    finally { $pending--; }
});
$app->get('/release', function () use ($release) { $release->set(); return Response::text('ok'); });
$app->get('/status', fn() => Response::text('ok'));
$app->post('/echo', function (Request $request) use (&$echoes) {
    $data = $request->json(); $echoes++; return Response::json($data, 201);
});
$app->get('/never', function () use (&$pending, &$cancelled) {
    $pending++;
    try { (new Asyncara\Concurrency\Event())->wait(); return Response::text('unexpected'); }
    catch (Asyncara\Exception\CancelledException $e) { $cancelled++; throw $e; }
    finally { $pending--; }
});
$app->get('/metrics', function () use (&$ticks, &$pending, &$cancelled, &$echoes, $app) {
    return Response::json(['ticks'=>$ticks, 'pending'=>$pending, 'cancelled'=>$cancelled,
        'echoes'=>$echoes, 'native'=>$app->stats()]);
});
$app->get('/stop', function () use ($app) { $app->stop(); return Response::text('stopped'); });
$app->listen('127.0.0.1:0', ['halfClose'=>($argv[1]??'1')==='1', 'streamRequests'=>($argv[2]??'0')==='1',
    'connections'=>16, 'bodyBytes'=>1024, 'responseBytes'=>4096, 'bufferBytes'=>65536, 'timeoutMs'=>1000],
    static function ($address) { echo "READY $address\n"; flush(); });
echo json_encode(['done'=>true,'runtime'=>Runtime::diagnostics()]),"\n";
