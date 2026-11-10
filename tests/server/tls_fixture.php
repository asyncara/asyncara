<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/autoload.php';
use Asyncara\{App, Async};
use Asyncara\Server\Response;
$app = new App(); $ticks = 0; $pending = 0; $cancelled = 0;
$release = new Asyncara\Concurrency\Event();
$app->get('/status', fn() => Response::text('ok'));
$app->get('/large', fn() => Response::text(str_repeat('x', 2097152)));
$app->post('/echo', fn($request) => Response::text($request->body));
$app->get('/hold', function () use (&$ticks, &$pending, &$cancelled, $release) {
    $pending++;
    try { Async::sleep(.002); $ticks++; Async::sleep(.002); $ticks++; $release->wait(); return Response::text('released'); }
    catch (Asyncara\Exception\CancelledException $e) { $cancelled++; throw $e; }
    finally { $pending--; }
});
$app->get('/release', function () use ($release) { $release->set(); return Response::text('ok'); });
$app->get('/metrics', fn() => Response::json(['native'=>$app->stats(), 'ticks'=>$GLOBALS['ticks'], 'pending'=>$GLOBALS['pending'], 'cancelled'=>$GLOBALS['cancelled']]));
$app->get('/stop', function () use ($app) { $app->stop(); return Response::text('stopped'); });
$tls = ['certificate'=>$argv[1].'/server.pem', 'privateKey'=>$argv[1].'/server.key'];
if (($argv[2] ?? '') === 'mtls') $tls['clientCa'] = $argv[1].'/ca.pem';
$app->listen('127.0.0.1:0', ['tls'=>$tls, 'timeoutMs'=>2000, 'connections'=>64, 'bodyBytes'=>2097152, 'responseBytes'=>2097152], function ($address) { echo "READY $address\n"; flush(); });
echo json_encode(['done'=>true, 'runtime'=>Asyncara\Runtime::diagnostics()]), "\n";
