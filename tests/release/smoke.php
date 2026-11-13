<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/autoload.php';
use Asyncara\{Async, Runtime};
use Asyncara\IO\Files;
use Asyncara\SQLite\Connection;

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$path = tempnam(sys_get_temp_dir(), 'asyncara-release-');
if ($path === false) throw new RuntimeException('Cannot create release smoke fixture');
try {
    check(phpversion('asyncara') === '0.10.0', 'Wrong loaded extension version');
    check(!Runtime::timeHooksEnabled(), 'Hooks must remain opt-in');
    Async::run(static function () use ($path): void {
        $db = Connection::open(':memory:')->await();
        try {
            $file = Files::replaceAtomic($path, 'asyncara-0.10');
            $tasks = [];
            for ($i = 0; $i < 32; $i++) $tasks[] = Async::spawn(static function () use ($i): int { Async::sleep(.001); return $i; });
            check(Async::gather($tasks) === range(0, 31), 'Task results differ');
            $db->query('CREATE TABLE release_check (value TEXT)')->await();
            $db->transaction(static function (Connection $db): void {
                $db->query('INSERT INTO release_check VALUES (?)', ['0.10.0'])->await();
                $db->savepoint('release_test')->await();
                $db->query('DELETE FROM release_check')->await();
                $db->rollbackTo('release_test')->await();
                $db->releaseSavepoint('release_test')->await();
            })->await();
            check($db->query('SELECT value FROM release_check')->await()[0]['value'] === '0.10.0', 'Transaction result differs');
            $file->await();
            check(Files::read($path)->await() === 'asyncara-0.10', 'File result differs');
        } finally { $db->close()->await(); }
    });
    $info = Runtime::information();
    foreach (['pendingOperations', 'openFileHandles', 'sqliteConnections', 'nativeMemoryBytes'] as $key) check($info->$key === 0, 'Resource retained: ' . $key);
    echo json_encode(['status' => 'PASS', 'version' => $info->version, 'backend' => $info->eventLoopBackend,
        'tasks' => 32, 'pendingOperations' => $info->pendingOperations], JSON_THROW_ON_ERROR), "\n";
} finally { unlink($path); }
