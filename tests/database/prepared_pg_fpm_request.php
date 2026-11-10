<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/autoload.php';
use Asyncara\{Async, Context, Future, Runtime};
use Asyncara\HTTP\HttpClient;
use Asyncara\PostgreSQL\Connection;

$before = asyncara_info();
if ($before['pendingOperations'] || $before['nativeMemoryBytes'] || Future::pendingCount()
    || Runtime::diagnostics() !== [] || isset($GLOBALS['prepared_request'])) {
    throw new RuntimeException('Cross-request resource state');
}
$GLOBALS['prepared_request'] = $_SERVER['AUDIT_ID'];
$options = json_decode(file_get_contents(getenv('ASYNCARA_PG_TEST_OPTIONS')), true, flags: JSON_THROW_ON_ERROR);
$options['application_name'] = 'asyncara_cache_fpm';
$start = microtime(true);
$result = Async::run(function () use ($options): array {
    if (Context::get('prepared_request') !== null) throw new RuntimeException('Context crossed request');
    Context::set('prepared_request', $_SERVER['AUDIT_ID']);
    $db = Connection::connect($options, preparedCacheLimit: 2, preparedCacheByteLimit: 256)->await();
    try {
        $statement = $db->prepare('SELECT $1::integer AS n, pg_sleep($2::float)');
        $statement->execute([1, 0])->await();
        $names = $db->query("SELECT name FROM pg_prepared_statements WHERE name LIKE 'asyncara_ps_%'")->await();
        if (count($names) !== 1 || $names[0]['name'] !== 'asyncara_ps_1') throw new RuntimeException('Prepared owner crossed request');
        if (($_GET['mode'] ?? '') === 'fatal') {
            $pending = $statement->execute([2, .2]);
            Async::sleep(.01);
            if ($pending->isCompleted()) throw new RuntimeException('Fatal fixture did not leave pending I/O');
            ini_set('memory_limit', '8M');
            str_repeat('x', 16 * 1024 * 1024);
            throw new RuntimeException('Expected fatal allocation');
        }
        $client = new HttpClient(4);
        $http = [];
        for ($i = 0; $i < 4; $i++) $http[] = $client->get($_SERVER['AUDIT_URL']);
        $queries = [$statement->execute([2, .05]), $statement->execute([3, .05])];
        $ticks = 0; $databaseTicks = 0;
        $ticker = Async::spawn(function () use ($http, $queries, &$ticks, &$databaseTicks): void {
            while (array_filter([...$http, ...$queries], static fn($job) => !$job->isCompleted())) {
                Async::sleep(.005);
                if (array_filter($http, static fn($job) => !$job->isCompleted())) $ticks++;
                if (array_filter($queries, static fn($job) => !$job->isCompleted())) $databaseTicks++;
            }
        });
        $rows = Async::gather($queries);
        $intervals = array_map(static fn($response) => $response->json(), Async::gather($http));
        $ticker->await();
        if (array_column(array_column($rows, 0), 'n') !== ['2', '3']) throw new RuntimeException('Prepared ordering/binding');
        if ($ticks < 2 || $databaseTicks < 2 || max(array_column($intervals, 'start')) >= min(array_column($intervals, 'end'))) {
            throw new RuntimeException('I/O did not overlap or blocked loop: '.json_encode(compact('ticks', 'databaseTicks', 'intervals')));
        }
        $statistics = $db->statistics();
        if ($statistics['preparedStatements'] !== 1 || $statistics['preparedHits'] !== 2) throw new RuntimeException('Prepared reuse');
        return ['ticks' => $ticks, 'databaseTicks' => $databaseTicks, 'http' => $intervals, 'prepared' => $statistics];
    } finally { $db->close(); $db->close(); }
});
$after = asyncara_info();
if ($after['pendingOperations'] || $after['nativeMemoryBytes'] || $after['nativeWorkers']) throw new RuntimeException('Native owner escaped run');
header('Content-Type: application/json');
echo json_encode(['id' => $_SERVER['AUDIT_ID'], 'pid' => getmypid(), 'start' => $start,
    'end' => microtime(true), 'before' => $before, 'after' => $after, 'result' => $result], JSON_THROW_ON_ERROR);
