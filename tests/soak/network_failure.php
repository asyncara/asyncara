<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/autoload.php';
require __DIR__.'/network.php';
use Asyncara\{Async,Future,Runtime};
use Asyncara\Exception\TimeoutException;
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$config = json_decode(getenv('ASYNCARA_SOAK_NETWORK'), true, 32, JSON_THROW_ON_ERROR);
// This peer accepts TCP and deliberately never supplies a TLS handshake.
$config['network']['tls12'] = $config['network']['stall'];
Async::run(function () use ($config): void {
    $network = new MixedNetwork($config);
    try {
        try { $network->cycle(); throw new RuntimeException('Stalled TLS was accepted'); }
        catch (TimeoutException $expected) { check($expected->getMessage() === 'Operation deadline exceeded', 'Original timeout changed'); }
    } finally { $network->close(); }
});
check(Future::pendingCount() === 0 && Runtime::information()->pendingOperations === 0, 'Pending work after failure cleanup');
echo "Network deadline diagnostic and cleanup: PASS\n";
