<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/autoload.php';
use Asyncara\{Async, Future, Scheduler};
use Asyncara\PostgreSQL\Connection;
use Asyncara\Exception\{CancelledException, TimeoutException};

function demand(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function fails(string $type, callable $body): void {
    try { $body(); } catch (Throwable $e) { demand($e instanceof $type, $e::class.': '.$e->getMessage()); return; }
    throw new RuntimeException('Expected '.$type);
}
function cached(Connection $db): array {
    return $db->query("SELECT name, statement, generic_plans, custom_plans FROM pg_prepared_statements WHERE name LIKE 'asyncara_%' ORDER BY name")->await();
}
$options = json_decode(file_get_contents(getenv('ASYNCARA_PG_TEST_OPTIONS')), true, flags: JSON_THROW_ON_ERROR);
Async::run(function () use ($options): void {
    // This same probe runs against the preceding implementation, where prepare
    // was only a parameterized query wrapper. Verify server state independently.
    $supports = function_exists('asyncara_pg_prepare');
    $db = $supports ? Connection::connect($options, preparedCacheLimit: 2, preparedCacheByteLimit: 256)->await()
                    : Connection::connect($options)->await();
    try {
        if (($options['sslmode'] ?? 'disable') !== 'disable') {
            demand($db->query('SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()')->await()[0]['ssl'] === 't', 'Prepared fixture did not negotiate TLS');
        }
        $sql = 'SELECT $1::integer AS n';
        $statement = $db->prepare($sql);
        demand($statement->execute([41])->await()[0]['n'] === '41', 'Binding result');
        $rows = cached($db);
        demand(count($rows) === 1 && $rows[0]['statement'] === $sql, 'No named server-side prepared statement');
        if (($GLOBALS['argv'][1] ?? '') === 'probe') return;
        $name = $rows[0]['name'];
        $copy = clone $statement;
        demand($copy->execute([42])->await()[0]['n'] === '42', 'Clone shared canonical connection');
        fails(LogicException::class, fn() => serialize($copy));
        $rows = cached($db);
        demand(count($rows) === 1 && $rows[0]['name'] === $name && (int)$rows[0]['custom_plans'] >= 2, 'Server statement was not reused');
        $b = $db->prepare('SELECT $1::text AS value'); $b->execute(['b'])->await();
        $statement->execute([43])->await(); // A becomes most recently used.
        $c = $db->prepare('SELECT $1::boolean AS flag');
        demand($c->execute([false])->await()[0]['flag'] === 'f', 'Boolean binding');
        $rows = cached($db);
        demand(count($rows) === 2 && in_array($sql, array_column($rows, 'statement'), true)
            && !in_array('SELECT $1::text AS value', array_column($rows, 'statement'), true), 'LRU server eviction');
        demand($db->statistics()['preparedStatements'] === 2 && $db->statistics()['preparedBytes'] <= 256, 'Cache bounds');
        // SQL larger than the cache byte bound uses the original unnamed path.
        $oversize = 'SELECT 7 AS n /*'.str_repeat('x', 300).'*/';
        demand($db->prepare($oversize)->execute()->await()[0]['n'] === '7' && count(cached($db)) === 2, 'Oversize fallback');
        $db->transaction(function (Connection $db): void {
            $db->query('CREATE TEMP TABLE pg_cache_tx(n int)')->await();
            $db->prepare('INSERT INTO pg_cache_tx VALUES($1)')->execute([9])->await();
        })->await();
        fails(DomainException::class, fn() => $db->transaction(function (Connection $db): void {
            $db->prepare('INSERT INTO pg_cache_tx VALUES($1)')->execute([99])->await();
            throw new DomainException('rollback');
        })->await());
        demand($db->query('SELECT sum(n) AS n FROM pg_cache_tx')->await()[0]['n'] === '9', 'Transaction affinity/rollback');

        $ticks = 0; $finished = false;
        $ticker = Async::spawn(function () use (&$ticks, &$finished): void {
            while (!$finished) { Async::sleep(.002); if (!$finished) $ticks++; }
        });
        $slow = $db->prepare('SELECT $1::integer AS n, pg_sleep(.05)');
        $queries = [];
        for ($i = 0; $i < 4; $i++) $queries[] = $slow->execute([$i]);
        $results = Async::gather($queries); $finished = true; $ticker->await();
        demand(array_column(array_column($results, 0), 'n') === ['0', '1', '2', '3'], 'Serialized statement operations');
        demand($ticks >= 2, 'Timer must progress while prepared I/O is pending');
        echo json_encode(['ticksWhilePending' => $ticks, 'statistics' => $db->statistics()]), "\n";
    } finally { $db->close(); $db->close(); }
    if (($GLOBALS['argv'][1] ?? '') === 'probe') return;
    demand($db->statistics()['preparedStatements'] === 0 && $db->statistics()['preparedBytes'] === 0, 'Close cache cleanup');

    // Disabled is the default and preserves utility/DDL prepare behavior.
    $db = Connection::connect($options)->await();
    try {
        $db->prepare('CREATE TEMP TABLE uncached(n int)')->execute()->await();
        $db->prepare('SELECT $1::integer AS n')->execute([1])->await();
        demand(cached($db) === [], 'Default must preserve unnamed queries');
    } finally { $db->close(); }

    // Query errors, caller deallocation, cancellation and deadline never replay.
    foreach (['deallocate', 'cancel', 'timeout'] as $mode) {
        $db = Connection::connect($options, preparedCacheLimit: 2)->await();
        try {
            $statement = $db->prepare('SELECT $1::integer AS n, pg_sleep($2::float)');
            $statement->execute([1, 0])->await();
            if ($mode === 'deallocate') {
                $db->query('DEALLOCATE ALL')->await();
                fails(Throwable::class, fn() => $statement->execute([2, 0])->await());
            } elseif ($mode === 'cancel') {
                $pending = $statement->execute([2, 10]); Async::sleep(.01); $pending->cancel();
                fails(CancelledException::class, $pending->await(...));
            } else {
                fails(TimeoutException::class, fn() => Async::timeout(.02, fn() => Async::await($statement->execute([2, 10]))));
            }
            demand($db->statistics()['closed'] && $db->statistics()['preparedStatements'] === 0, $mode.': failure must close and clear');
        } finally { $db->close(); }
    }
    // A waiter does not own this shared operation. Its timeout must not close it.
    $db = Connection::connect($options, preparedCacheLimit: 8, preparedCacheByteLimit: 512)->await();
    try {
        $pending = $db->prepare('SELECT 10 AS n, pg_sleep(.1)')->execute();
        $survivor = Async::spawn(fn() => $pending->await());
        fails(TimeoutException::class, fn() => Async::timeout(.01, fn() => $pending->await()));
        demand(!$pending->isCompleted() && !$db->statistics()['closed'], 'Waiter timeout cancelled shared operation');
        demand($survivor->await()[0]['n'] === '10', 'Shared survivor lost result');
        for ($i = 0; $i < 100; $i++) {
            demand($db->prepare('SELECT '.$i.' AS n')->execute()->await()[0]['n'] === (string)$i, 'Repeated eviction result');
            demand(count(cached($db)) <= 8 && $db->statistics()['preparedBytes'] <= 512, 'Repeated eviction growth');
        }
        // Single-row mode still drains results across native 256-row batches.
        demand(count($db->prepare('SELECT generate_series(1,1000) AS n')->execute()->await()) === 1000, 'Prepared row batching');
    } finally { $db->close(); }
    $db = Connection::connect($options, preparedCacheLimit: 16, preparedCacheByteLimit: 64)->await();
    try {
        for ($i = 0; $i < 8; $i++) $db->prepare('SELECT '.$i.' AS n /*'.str_repeat('x', 30).'*/')->execute()->await();
        demand(count(cached($db)) === 1 && $db->statistics()['preparedEvictions'] === 7, 'SQL-byte eviction bound');
    } finally { $db->close(); }

    // Block Parse/prepare on an actual relation lock. This is not a sleep-only
    // mock: pg_stat_activity independently proves server-side pending work.
    $locker = Connection::connect($options)->await();
    $blocked = Connection::connect($options, preparedCacheLimit: 2)->await();
    try {
        $locker->query('CREATE TABLE cache_prepare_lock(n integer)')->await();
        $pid = $blocked->query('SELECT pg_backend_pid() AS pid')->await()[0]['pid'];
        $locker->query('BEGIN')->await();
        $locker->query('LOCK cache_prepare_lock IN ACCESS EXCLUSIVE MODE')->await();
        $pending = $blocked->prepare('SELECT n FROM cache_prepare_lock')->execute();
        Async::timeout(5, function () use ($locker, $pid): void {
            while (true) {
                $row = $locker->query('SELECT wait_event_type FROM pg_stat_activity WHERE pid=$1', [$pid])->await();
                if (($row[0]['wait_event_type'] ?? '') === 'Lock') return;
                // End each stats snapshot inside this deliberately open transaction.
                $locker->query('SELECT pg_stat_clear_snapshot()')->await();
                Async::sleep(.001);
            }
        });
        demand(!$pending->isCompleted() && $blocked->statistics()['preparedStatements'] === 0, 'Prepare result published before server completed');
        $prepareTicks = 0;
        for ($i = 0; $i < 2; $i++) {
            Async::sleep(.002);
            demand(!$pending->isCompleted(), 'Prepare completed before lock release');
            $prepareTicks++;
        }
        echo json_encode(['ticksWhilePrepareLocked' => $prepareTicks]), "\n";
        $pending->cancel(); fails(CancelledException::class, $pending->await(...));
        demand($blocked->statistics()['closed'] && $blocked->statistics()['preparedStatements'] === 0, 'Pending preparation cancellation');
        $locker->query('ROLLBACK')->await();
        Async::timeout(5, function () use ($locker, $pid): void {
            while ($locker->query('SELECT pid FROM pg_stat_activity WHERE pid=$1', [$pid])->await()) Async::sleep(.001);
        });
    } finally { $blocked->close(); $locker->close(); }

    // Independent connections may use the same generated names with distinct
    // server sessions. Closing one must not invalidate the other.
    $first = Connection::connect($options, preparedCacheLimit: 2)->await();
    $second = Connection::connect($options, preparedCacheLimit: 2)->await();
    try {
        $first->prepare('SELECT 1 AS n')->execute()->await();
        $other = $second->prepare('SELECT 2 AS n'); $other->execute()->await();
        demand(cached($first)[0]['name'] === cached($second)[0]['name'], 'Expected session-local namespace');
        $first->close();
        demand($other->execute()->await()[0]['n'] === '2', 'Cross-connection statement ownership');
    } finally { $first->close(); $second->close(); }

    // Native API validation/admission and registered resource lifetime, independent
    // of the PHP cache. Drain prepare before execute; no blocking PQexec calls.
    $handle = asyncara_pg_open($options);
    try {
        do {
            $state = asyncara_pg_poll($handle);
            if ($state['status'] === 1) Scheduler::current()->readable($state['fd']);
            if ($state['status'] === 2) Scheduler::current()->writable($state['fd']);
        } while ($state['status'] !== 3);
        $drain = function () use ($handle): array {
            $rows = [];
            do {
                $state = asyncara_pg_read($handle);
                demand(!isset($state['error']), $state['error'] ?? 'Native error');
                array_push($rows, ...$state['rows']);
                if ($state['read'] || $state['write']) Scheduler::current()->waitDescriptor($state['fd'], ($state['read'] ? 1 : 0) | ($state['write'] ? 2 : 0));
                elseif ($state['pending']) Async::sleep(0);
            } while ($state['pending']);
            return $rows;
        };
        fails(ValueError::class, fn() => asyncara_pg_prepare($handle, '', 'SELECT 1', 0));
        fails(ValueError::class, fn() => asyncara_pg_prepare($handle, "bad\0name", 'SELECT 1', 0));
        fails(ValueError::class, fn() => asyncara_pg_prepare($handle, 'test', 'SELECT 1', 10001));
        asyncara_pg_prepare($handle, 'direct_owned', 'SELECT $1::integer AS n', 1);
        fails(ValueError::class, fn() => asyncara_pg_execute($handle, 'direct_owned', [5]));
        $drain();
        asyncara_pg_execute($handle, 'direct_owned', [5]);
        fails(ValueError::class, fn() => asyncara_pg_prepare($handle, 'another', 'SELECT 1', 0));
        demand($drain()[0]['n'] === '5', 'Native prepare/execute');
        fails(ValueError::class, fn() => asyncara_pg_execute($handle, 'direct_owned', [new stdClass()]));
        asyncara_pg_execute($handle, 'direct_owned', [6]);
        demand($drain()[0]['n'] === '6', 'Invalid native parameter damaged connection');
    } finally { asyncara_pg_close($handle); }
    fails(ValueError::class, fn() => Connection::connect($options, preparedCacheLimit: 257));
    fails(ValueError::class, fn() => Connection::connect($options, preparedCacheByteLimit: 0));
});
gc_collect_cycles();
demand(asyncara_info()['pendingOperations'] === 0, 'Native pending cleanup');
echo "PostgreSQL prepared cache PASS\n";
