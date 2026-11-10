<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/autoload.php';
use Asyncara\{App, Context};
use Asyncara\Server\Response;
$app = new App();
$app->get('/pid', fn() => Response::json(['pid'=>getmypid(), 'state'=>Context::get('test')]));
$app->get('/set', function () { Context::set('test','private'); Asyncara::sleep(2); return Response::text('ok'); });
$app->get('/stop', function () use ($app) { $app->stop(); return Response::text('stop'); });
$app->listen('127.0.0.1:'.$argv[1], ['workers'=>4, 'timeoutMs'=>2000], static function ($address) { echo "READY $address\n"; flush(); });
