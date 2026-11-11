<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/autoload.php';
use Asyncara\{Async, Future, Runtime, Scheduler};

function demand(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
$pg = getenv('ASYNCARA_PG_MTLS_FIXTURE');
$isPg = is_string($pg) && $pg !== '';
$fixturePath = $isPg ? $pg : getenv('ASYNCARA_MY_MTLS_FIXTURE');
$fixture = json_decode(file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);
$base = $fixture['options'];
$keys = $isPg ? ['sslrootcert', 'sslcert', 'sslkey'] : ['caFile', 'certFile', 'keyFile'];
$mode = $argv[1]; $checks = 0; $warnings = 0; $original = getcwd();
$root = dirname($fixturePath).'/paths-'.$mode;
mkdir($root, 0700); mkdir($root.'/a', 0700); mkdir($root.'/b', 0700);
foreach ($keys as $key) { copy($base[$key], $root.'/a/'.$key); chmod($root.'/a/'.$key, 0600); }
$connect = static fn(array $options) => $isPg
    ? Asyncara\PostgreSQL\Connection::connect($options, timeout: 5)
    : Asyncara\MySQL\Connection::connect($options, timeout: 5);
$open = $isPg ? 'asyncara_pg_open' : 'asyncara_mysql_open';
$close = $isPg ? 'asyncara_pg_close' : 'asyncara_mysql_close';
$resourceType = $isPg ? 'Asyncara PostgreSQL' : 'Asyncara MariaDB';

if ($mode === 'relative') {
    try {
        foreach (['public', 'native', 'public-reference'] as $api) foreach ($keys as $key) {
            Async::run(function () use ($base, $root, $api, $key, $connect, $open, $close, $isPg): void {
                Async::timeout(5, function () use ($base, $root, $api, $key, $connect, $open, $close, $isPg): void {
                    chdir($root.'/a'); $options = $base; $path = $key;
                    if ($api === 'public-reference') $options[$key] = &$path;
                    else $options[$key] = $path;
                    if ($api !== 'native') {
                        $future = $connect($options);
                        demand($path === $key && $options[$key] === $key, 'Submission modified caller options');
                        if ($api === 'public-reference') $path = 'absent-after-submission';
                        chdir($root.'/b'); $db = $future->await();
                        try { demand((int)$db->query('SELECT 42 AS n')->await()[0]['n'] === 42, 'Relative TLS query'); }
                        finally { $db->close(); $db->close(); }
                    } else {
                        $handle = $open($options); chdir($root.'/b');
                        try {
                            demand(get_resource_type($handle) === ($isPg ? 'Asyncara PostgreSQL' : 'Asyncara MariaDB'), 'Native owner identity');
                            $events = 0;
                            while (true) {
                                if ($isPg) {
                                    $state = asyncara_pg_poll($handle);
                                    if ($state['status'] === 3) break;
                                    if ($state['status'] === 1) Scheduler::current()->readable($state['fd']);
                                    elseif ($state['status'] === 2) Scheduler::current()->writable($state['fd']);
                                    else Async::sleep(0);
                                } else {
                                    $state = asyncara_mysql_step($handle, $events);
                                    if (!$state['pending']) break;
                                    if ($state['events']) $events = Scheduler::current()->waitDescriptor($state['fd'], $state['events'], ($state['events'] & 8) ? max(.001, $state['timeoutMs']/1000) : null);
                                    else { Async::sleep(0); $events = 0; }
                                }
                            }
                        } finally { $close($handle); }
                    }
                });
            });
            $checks++;
        }
        if ($isPg) {
            // ./system is a filename; bare system is libpq's system-root selector.
            copy($base['sslrootcert'], $root.'/a/system');
            Async::run(function () use ($base, $root, $connect): void {
                chdir($root.'/a'); $pending = $connect(array_replace($base, ['sslrootcert'=>'./system']));
                chdir($root.'/b'); $db = $pending->await();
                try { demand($db->query('SELECT 1 AS n')->await()[0]['n'] === '1', 'Explicit ./system file'); }
                finally { $db->close(); }
            });
            $checks++;
            foreach (['public', 'native'] as $api) {
                // This invalid mode must be rejected by libpq's system selector,
                // not converted into a relative file or used for unverified TLS.
                $options = array_replace(array_diff_key($base, ['sslcert'=>1, 'sslkey'=>1]), ['sslrootcert'=>'system', 'sslmode'=>'require']);
                $caught = false;
                try {
                    if ($api === 'public') Async::run(fn() => $connect($options)->await());
                    else { $handle = $open($options); $close($handle); }
                } catch (Exception $error) {
                    $caught = str_contains($error->getMessage(), 'system') && str_contains($error->getMessage(), 'verify-full');
                }
                demand($caught, 'libpq system-root selector changed'); $checks++;
            }
        }
    } finally { chdir($original); }
} elseif ($mode === 'invalid') {
    Async::run(function () use ($base, $keys, $connect, $open, $close, &$checks): void {
        foreach (['public', 'native'] as $api) foreach ($keys as $key) foreach (['', "bad\0file", str_repeat('x', 4096)] as $path) {
            $caught = false; $options = array_replace($base, [$key=>$path]);
            try {
                if ($api === 'public') { $db = $connect($options)->await(); $db->close(); }
                else { $handle = $open($options); $close($handle); }
            } catch (ValueError) { $caught = true; }
            demand($caught, 'Malformed TLS filename admitted'); $checks++;
        }
    });
} elseif (in_array($mode, ['policy', 'late-policy'], true)) {
    // Load classes before restricting file access, and inspect cleanup without
    // depending on /proc being readable under open_basedir.
    class_exists(Asyncara\Exception\OperationException::class);
    $marker = new DomainException('Original TLS path warning');
    set_error_handler(static function (int $level, string $message) use (&$warnings, $marker): bool {
        if ($level !== E_WARNING || !str_contains($message, 'open_basedir')) return false;
        $warnings++; throw $marker;
    });
    try {
        Async::run(function () use ($base, $keys, $root, $connect, $open, $close, $marker, $isPg, $mode, &$checks): void {
            $allowed = $base;
            foreach ($keys as $key) $allowed[$key] = $root.'/b/'.$key;
            $pending = [];
            if ($mode === 'late-policy') foreach ($keys as $key) $pending[$key] = $connect(array_replace($allowed, [$key=>$base[$key]]));
            demand(ini_set('open_basedir', dirname(__DIR__,2).PATH_SEPARATOR.$root.'/b') !== false, 'Cannot set private test path policy');
            foreach ($mode === 'late-policy' ? ['public'] : ['public', 'native'] as $api) foreach ($keys as $key) {
                for ($i = 0; $i < ($mode === 'late-policy' ? 1 : 20); $i++) {
                    $options = array_replace($allowed, [$key=>$base[$key]]); $caught = null;
                    try {
                        if ($api === 'public') { $db = ($pending[$key] ?? $connect($options))->await(); $db->close(); }
                        else { $handle = $open($options); $close($handle); }
                    } catch (Throwable $error) { $caught = $error; }
                    demand($caught === $marker, 'Path rejection lost original warning exception'); $checks++;
                }
            }
            if ($isPg && $mode === 'policy') {
                $options = array_diff_key($base, ['sslcert'=>1,'sslkey'=>1]); $caught = null;
                try { $handle = $open($options); $close($handle); } catch (Throwable $error) { $caught = $error; }
                demand($caught === $marker, 'CA policy bypass without client certificate'); $checks++;
            }
        });
        demand($warnings === $checks, 'Missing or duplicate path warning');
    } finally { restore_error_handler(); }
} else throw new ValueError('Unknown TLS filename test mode');
gc_collect_cycles();
$native = asyncara_info(); $scheduler = Runtime::diagnostics();
demand(!$native['pendingOperations'] && !$native['nativeMemoryBytes'] && !Future::pendingCount()
    && !$scheduler['activeTasks'] && !$scheduler['activeTimers'] && !$scheduler['ioWatchers'], 'TLS filename owner leakage');
demand(count(get_resources($resourceType)) === 0, 'Unreleased native SQL resource');
echo json_encode(['status'=>'PASS', 'driver'=>$isPg?'postgresql':'mysql', 'mode'=>$mode, 'checks'=>$checks,
    'warnings'=>$warnings, 'php'=>PHP_VERSION, 'backend'=>$native['eventLoopBackend'], 'native'=>$native], JSON_THROW_ON_ERROR), "\n";
