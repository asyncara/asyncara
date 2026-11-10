<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/autoload.php';
use Asyncara\{App, Async, Scheduler};
use Asyncara\Server\{Request, Response};
$app = new App(); $ticks = 0; $pending = 0; $cancelled = 0; $release = new Asyncara\Concurrency\Event();
$app->get('/status', fn() => Response::json(['status' => 'OK', 'engine' => 'Asyncara/Rust']));
$app->get('/work/{id}', function (Request $req) {
    Asyncara::sleep((int)$req->query('delay_ms', 0));
    return Response::json(['worker_id' => (int)$req->param('id'), 'payload' => str_repeat('x', (int)$req->query('payload_bytes', 10))]);
});
$app->post('/data', fn(Request $req) => Response::json(['success' => true, 'received' => $req->json()], 201));
$app->middleware('auth', fn(Request $req, callable $next) => $req->header('authorization') === 'test-token' ? $next($req) : Response::text('Unauthorized', 401));
$app->group('/api/v1', function (App $router) {
    $router->get('/users', fn() => Response::json(['users' => []]));
    $router->post('/upload', fn() => Response::text('Done'))->middleware('auth');
});
$app->get('/hold', function () use (&$ticks, &$pending, &$cancelled, $release) {
    $pending++;
    try { Async::sleep(0.002); $ticks++; Async::sleep(0.002); $ticks++; $release->wait(); return Response::text('released'); }
    catch (Asyncara\Exception\CancelledException $e) { $cancelled++; throw $e; }
    finally { $pending--; }
});
$app->get('/release', function () use ($release) { $release->set(); return Response::text('ok'); });
$app->get('/never', function () use (&$pending, &$cancelled) {
    $pending++;
    try { (new Asyncara\Concurrency\Event())->wait(); return Response::text('unexpected'); }
    catch (Asyncara\Exception\CancelledException $e) { $cancelled++; throw $e; }
    finally { $pending--; }
});
$app->get('/metrics', function () use (&$ticks, &$pending, &$cancelled, $app) { return Response::json(['ticks'=>$ticks,'pending'=>$pending,'cancelled'=>$cancelled,'runtime'=>Scheduler::current()->statistics(),'native'=>$app->stats(),'fds'=>count(scandir('/proc/self/fd'))-2]); });
$app->get('/error', fn() => throw new RuntimeException('secret test error'));
$app->get('/bad-header', fn() => Response::text('unsafe', 200, ['x-test'=>"bad\r\ninjected: true"]));
$app->get('/oversized', fn() => Response::text(str_repeat('x', 2097153)));
$app->get('/large', fn() => Response::text(str_repeat('x', 2097152)));
$app->get('/stop', function () use ($app) { $app->stop(); return Response::text('stopped'); });
$app->listen('127.0.0.1:0', ['connections'=>64, 'bodyBytes'=>1024, 'responseBytes'=>2097152, 'bufferBytes'=>4194304, 'timeoutMs'=>1500, 'requestsPerConnection'=>50], static function ($address) { echo "READY $address\n"; flush(); });
echo json_encode(['done'=>true, 'runtime'=>Asyncara\Runtime::diagnostics()]), "\n";
