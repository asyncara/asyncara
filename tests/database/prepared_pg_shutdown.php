<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/autoload.php';
use Asyncara\Async;
use Asyncara\PostgreSQL\Connection;
$options = json_decode(file_get_contents(getenv('ASYNCARA_PG_TEST_OPTIONS')), true, flags: JSON_THROW_ON_ERROR);
$options['application_name'] = 'asyncara_cache_shutdown';
Async::run(function () use ($options, $argv): void {
    $db = Connection::connect($options, preparedCacheLimit: 2)->await();
    if (($argv[1] ?? '') === 'oom') {
        $db->prepare("SELECT repeat('x',12582912) AS body")->execute()->await();
        throw new RuntimeException('Expected PHP allocation to fail');
    }
    $statement = $db->prepare('SELECT $1::integer AS n, pg_sleep($2::float)');
    $statement->execute([1, 0])->await();
    $pending = $statement->execute([2, .2]);
    Async::sleep(.01);
    if ($pending->isCompleted()) throw new RuntimeException('Shutdown fixture did not leave query pending');
    echo json_encode(['pending' => true, 'cached' => $db->statistics()['preparedStatements']]), "\n";
    if (($argv[1] ?? '') === 'fatal') trigger_error('Intentional prepared request fatal', E_USER_ERROR);
    exit(0);
});
