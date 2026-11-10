<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';
use Asyncara\App;
use Asyncara\Server\Request;
use Asyncara\Server\Response;

$app = new App();
$app->get('/status', fn() => Response::json(['status' => 'OK', 'engine' => 'Asyncara/Rust']));
$app->get('/work/{id}', function (Request $req) {
    $delay = filter_var($req->query('delay_ms', 0), FILTER_VALIDATE_INT);
    $bytes = filter_var($req->query('payload_bytes', 10), FILTER_VALIDATE_INT);
    if ($delay === false || $bytes === false || $delay < 0 || $delay > 10000 || $bytes < 0 || $bytes > 1048576) return Response::text('Invalid size or delay', 400);
    if ($delay > 0) Asyncara::sleep($delay);
    return Response::json(['worker_id' => (int)$req->param('id'), 'payload' => str_repeat('x', $bytes)]);
});
$app->post('/data', fn(Request $req) => Response::json(['success' => true, 'received' => $req->json()], 201));
// Example authentication: configure a token explicitly; an unset token denies access.
$app->middleware('auth', static function (Request $req, callable $next): Response {
    $token = getenv('ASYNCARA_EXAMPLE_TOKEN');
    return $token !== false && $token !== '' && hash_equals('Bearer '.$token, $req->header('authorization', ''))
        ? $next($req) : Response::text('Unauthorized', 401);
});
$app->group('/api/v1', function (App $router) {
    $router->get('/users', fn() => Response::json(['users' => []]));
    $router->post('/upload', fn() => Response::text('Done'))->middleware('auth');
});
$app->listen($argv[1] ?? '127.0.0.1:8080', ['workers' => (int)($argv[2] ?? 1)],
    static function (string $address): void { echo "Asyncara Server running on http://$address\n"; });
