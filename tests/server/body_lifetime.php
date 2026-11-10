<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/autoload.php';
use Asyncara\App;
use Asyncara\Server\Response;
$app = new App();
$app->post('/discard', fn() => Response::text('ok'));
$app->get('/memory', function () use ($app) {
    gc_collect_cycles();
    return Response::json(['phpBytes'=>memory_get_usage(false), 'native'=>$app->stats()]);
});
$app->get('/stop', function () use ($app) { $app->stop(); return Response::text('ok'); });
$app->listen('127.0.0.1:0', ['bodyBytes'=>1048576], static function ($address) {
    echo "READY $address\n"; flush();
});
