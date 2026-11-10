<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/autoload.php';
use Asyncara\{App, Context};
use Asyncara\Server\Response;
$app = new App();
$app->get('/pid', fn() => Response::json(['pid'=>getmypid(), 'state'=>Context::get('test')]));
$app->get('/set', function () { Context::set('test','private'); Asyncara::sleep(2); return Response::text('ok'); });
$app->get('/stop', function () use ($app) { $app->stop(); return Response::text('stop'); });
$options = ['workers'=>4, 'timeoutMs'=>2000];
if (getenv('ASYNCARA_TEST_STREAM_REQUESTS') === '1') {
    $options['streamRequests'] = true;
    $app->post('/input', function (Asyncara\Server\Request $request) { $hash=hash_init('sha256');$n=0;foreach($request->stream() as $chunk){$n+=strlen($chunk);hash_update($hash,$chunk);}return Response::json(['bytes'=>$n,'sha256'=>hash_final($hash),'pid'=>getmypid()]); });
}
if (isset($argv[2])) $options['tls'] = ['certificate'=>$argv[2].'/server.pem', 'privateKey'=>$argv[2].'/server.key'];
$app->listen('127.0.0.1:'.$argv[1], $options, static function ($address) { echo "READY $address\n"; flush(); });
