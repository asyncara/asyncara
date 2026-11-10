<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/autoload.php';
use Asyncara\App;
use Asyncara\Server\{Request, Response};
$app = new App();
// Immutable cache, populated before requests in both implementations.
$payloads = [10=>str_repeat('x',10), 1024=>str_repeat('x',1024), 65536=>str_repeat('x',65536)];
$handler = static function (Request $req) use ($payloads): Response {
    $delay = (int)$req->query('delay_ms', 0);
    if ($delay > 0) Asyncara::sleep($delay);
    $size = (int)$req->query('payload_bytes',10);
    if (!isset($payloads[$size])) return Response::text('Bad Request',400);
    return Response::json(['id'=>(int)$req->query('id',0),'payload'=>$payloads[$size]]);
};
$app->get('/status', $handler); $app->get('/work', $handler);
$app->listen('127.0.0.1:'.($argv[1] ?? '8080'), ['connections'=>4096, 'workers'=>(int)($argv[2] ?? 1)], static function ($address) { echo "READY $address\n"; flush(); });
