<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/autoload.php';
use Asyncara\{App, Async};
use Asyncara\Server\Response;
$app = new App(); $release = new Asyncara\Concurrency\Event();
$produced = 0; $closed = 0; $ticks = 0;
$eager = null;
$app->get('/head-eager', function () use (&$eager) {
    $eager = Asyncara\Stream\AsyncStream::fromProducer(function ($emitter) { for ($i=0; $i<10; $i++) $emitter->emit('chunk'); }, 1);
    return Response::stream($eager);
});
$app->get('/invalid-eager', function () use (&$eager) {
    $eager = Asyncara\Stream\AsyncStream::fromProducer(function ($emitter) { for ($i=0; $i<10; $i++) $emitter->emit('chunk'); }, 1);
    return Response::stream($eager, 200, ['Content-Length'=>'999']);
});
$app->get('/stream', fn() => Response::stream((function () use ($release) {
    yield 'first'; $release->wait(); yield 'last';
})()));
$app->get('/flood', fn() => Response::stream((function () {
    try { for ($i=0; $i<256; $i++) { $GLOBALS['produced']++; yield str_repeat('x', 65536); } }
    finally { $GLOBALS['closed']++; }
})()));
$app->get('/file', fn() => Response::stream(Asyncara\IO\Files::readStream(__FILE__)));
$app->get('/empty', fn() => Response::stream([]));
$app->get('/complete', fn() => Response::stream((function () { for ($i=0; $i<256; $i++) yield str_repeat('x',65536); })()));
$app->get('/limit', fn() => Response::stream((function () { for ($i=0; $i<257; $i++) yield str_repeat('x',65536); })()));
$app->get('/bad-type', fn() => Response::stream([42]));
$app->get('/large-chunk', fn() => Response::stream([str_repeat('x',65537)]));
$app->get('/failure', fn() => Response::stream((function () { yield 'first'; throw new RuntimeException('secret'); })()));
$app->get('/head', fn() => Response::stream((function () { throw new RuntimeException('HEAD producer must not run'); yield ''; })()));
$app->get('/status', fn() => Response::text('ok'));
$app->get('/release', function () use ($release) { $release->set(); return Response::text('ok'); });
$app->get('/metrics', function () use ($app) {
    Async::sleep(.002); $GLOBALS['ticks']++; Async::sleep(.002); $GLOBALS['ticks']++;
    return Response::json(['produced'=>$GLOBALS['produced'],'closed'=>$GLOBALS['closed'],'ticks'=>$GLOBALS['ticks'],'native'=>$app->stats(),'phpBytes'=>memory_get_usage(),'eagerCompleted'=>$GLOBALS['eager']?->completion()->isCompleted()]);
});
$app->get('/stop', function () use ($app) { $app->stop(); return Response::text('stopped'); });
$options=['responseBytes'=>16777216,'bufferBytes'=>262144,'connections'=>64,'timeoutMs'=>3000];
if (isset($argv[1])) $options['tls']=['certificate'=>$argv[1].'/server.pem','privateKey'=>$argv[1].'/server.key'];
$app->listen('127.0.0.1:0', $options, function ($address) { echo "READY $address\n"; flush(); });
echo json_encode(['done'=>true,'runtime'=>Asyncara\Runtime::diagnostics()]), "\n";
