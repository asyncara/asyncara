<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/autoload.php';
use Asyncara\{App, Async};
use Asyncara\Server\{Request, Response};
$app = new App(); $seen = 0; $ticks = 0; $closed = 0;
$upload = function (Request $request) use (&$seen, &$ticks, &$closed) {
    $hash = hash_init('sha256'); $length = 0; $chunks = 0;
    try {
        foreach ($request->stream() as $chunk) {
            if (strlen($chunk) > 65536) throw new LogicException('Unbounded input chunk');
            $seen += strlen($chunk); $length += strlen($chunk); $chunks++;
            hash_update($hash, $chunk);
            if ($chunks === 1) { Async::sleep(.002); $ticks++; Async::sleep(.002); $ticks++; }
            if ($request->path === '/slow') Async::sleep(.001);
        }
        return Response::json(['bytes'=>$length,'sha256'=>hash_final($hash),'chunks'=>$chunks]);
    } finally { $closed++; }
};
$app->post('/upload', $upload); $app->post('/slow', $upload);
$app->post('/json', fn(Request $request) => Response::json([$request->json(), $request->json()]));
$app->post('/early', fn() => Response::text('rejected', 403));
$app->post('/throw', function (Request $request) { foreach ($request->stream() as $chunk) throw new RuntimeException('not public'); });
$app->post('/owner', function (Request $request) use ($upload) {
    $stream = $request->stream();
    foreach ([fn() => clone $stream, fn() => serialize($stream)] as $copy) {
        try { $copy(); throw new RuntimeException('Copy allowed'); } catch (Error|LogicException) {}
    }
    Async::spawn(function () use ($stream) {
        try { foreach ($stream as $_) {} throw new RuntimeException('Foreign consumer allowed'); }
        catch (LogicException) {}
        try { $stream->consume(static fn($chunk) => null); throw new RuntimeException('Foreign consume allowed'); } catch (LogicException) {}
        try { $stream->close(); throw new RuntimeException('Foreign close allowed'); } catch (LogicException) {}
    })->await();
    return $upload($request); // rejected consumer must not consume/cancel this owner
});
$app->get('/metrics', fn() => Response::json(['seen'=>$GLOBALS['seen'],'ticks'=>$GLOBALS['ticks'],'closed'=>$GLOBALS['closed'],'server'=>$app->stats(),'php'=>memory_get_usage(),'peak'=>memory_get_peak_usage()]));
$app->get('/stop', function () use ($app) { $app->stop(); return Response::text('stop'); });
$options = ['streamRequests'=>true,'bodyBytes'=>8388608,'bufferBytes'=>262144,'connections'=>64,'timeoutMs'=>3000];
if (isset($argv[1])) $options['tls']=['certificate'=>$argv[1].'/server.pem','privateKey'=>$argv[1].'/server.key'];
$app->listen('127.0.0.1:0', $options, function ($address) { echo "READY $address\n"; flush(); });
echo json_encode(['done'=>true,'runtime'=>Asyncara\Runtime::diagnostics()]), "\n";
