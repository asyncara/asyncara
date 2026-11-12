<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/autoload.php';

use Asyncara\{App, Async, Runtime};
use Asyncara\Concurrency\Event;
use Asyncara\Server\Response;

$app = new App();
$release = new Event();
$pending = 0;
$ticks = 0;
$cancelled = 0;
$app->get('/hold', function () use (&$pending, &$ticks, &$cancelled, $release) {
    $pending++;
    try {
        Async::sleep(.002); $ticks++;
        Async::sleep(.002); $ticks++;
        $release->wait();
        return Response::text('released');
    } catch (Asyncara\Exception\CancelledException $e) {
        $cancelled++;
        throw $e;
    } finally {
        $pending--;
    }
});
$app->get('/probe', function () { Async::sleep(.002); return Response::text('alive'); });
// Capture mutable counters by reference, not by value.
$app->get('/state', function () use (&$pending, &$ticks, &$cancelled, $app) {
    return Response::json(['pending'=>$pending, 'ticks'=>$ticks, 'cancelled'=>$cancelled,
        'native'=>$app->stats(), 'fds'=>count(scandir('/proc/self/fd'))-2]);
});
$app->get('/release', function () use ($release) { $release->set(); return Response::text('ok'); });
$app->get('/stop', function () use ($app) { $app->stop(); return Response::text('stopped'); });
$exit = 0;
try {
    $app->listen('127.0.0.1:0', ['connections'=>16, 'bodyBytes'=>1024,
        'responseBytes'=>4096, 'bufferBytes'=>65536, 'timeoutMs'=>10000],
        static function ($address) { echo "READY $address\n"; flush(); });
} catch (Throwable $error) {
    $exit = 97;
    echo json_encode(['fatal'=>get_class($error), 'message'=>$error->getMessage()]), "\n";
} finally {
    echo json_encode(['done'=>true, 'pending'=>$pending, 'cancelled'=>$cancelled,
        'runtime'=>Runtime::diagnostics()]), "\n";
}
exit($exit);
