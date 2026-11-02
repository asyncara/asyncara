<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';

use Asyncara\{Async,Context,Hook,Runtime};
use Asyncara\IO\Files;
use Asyncara\SQLite\Connection;

$before = Runtime::information();
if ($before->pendingOperations !== 0 || Runtime::timeHooksEnabled()) {
    throw new RuntimeException('Native operations or hook configuration escaped the previous request');
}
Runtime::enableHooks(Hook::TIME);
$value = Async::run(function (): int {
    if (Context::get('request-marker') !== null) throw new RuntimeException('Context escaped its request');
    Context::set('request-marker', 'private');
    $db = Connection::open(':memory:')->await();
    try {
        $read = Files::read(__FILE__);
        $timer = Async::spawn(static function (): int { usleep(1000); return 7; });
        $rows = $db->query('SELECT ? AS value', [42])->await();
        if ($read->await() === '' || $timer->await() !== 7) throw new RuntimeException('Concurrent work failed');
        return $rows[0]['value'];
    } finally { $db->close()->await(); }
});
// Exercise RSHUTDOWN with work that userland intentionally does not collect.
for ($i = 0; $i < 24; $i++) asyncara_submit('read', [__FILE__]);
for ($i = 0; $i < 4; $i++) asyncara_submit('sqlite_open', [':memory:']);
for ($i = 0; $i < 4; $i++) asyncara_submit('file_open', [__FILE__, 'r']);
header('Content-Type: application/json');
echo json_encode(['pid' => getmypid(), 'value' => $value], JSON_THROW_ON_ERROR);
