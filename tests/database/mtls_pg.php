<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/autoload.php';
use Asyncara\{Async, Future, Scheduler};
use Asyncara\PostgreSQL\Connection;
use Asyncara\Exception\CancelledException;

function demand(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function rejected(callable $operation, string $name): string {
    try { $value = $operation(); }
    catch (Throwable $e) {
        demand(in_array($e::class, [ValueError::class,Exception::class], true), 'Unexpected rejection type for '.$name.': '.$e::class);
        return $e::class;
    }
    if ($value instanceof Connection) $value->close();
    throw new RuntimeException('Accepted invalid client TLS case: '.$name);
}
$fixture = json_decode(file_get_contents(getenv('ASYNCARA_PG_MTLS_FIXTURE')), true, flags: JSON_THROW_ON_ERROR);
$cases = []; $ticks = 0;
Async::run(function () use ($fixture, &$cases, &$ticks): void {
    $base = $fixture['options'];
    $db = Connection::connect($base, timeout: 5, preparedCacheLimit: 2)->await();
    try {
        $tls = $db->query('SELECT ssl,version,client_dn FROM pg_stat_ssl WHERE pid=pg_backend_pid()')->await()[0];
        demand($tls['ssl'] === 't' && str_contains($tls['client_dn'], 'CN='.$base['user']), 'Server did not authenticate the client certificate');
        demand(in_array($tls['version'], ['TLSv1.2','TLSv1.3'], true), 'Unexpected TLS protocol');
        if (($GLOBALS['argv'][1] ?? '') === 'probe') return;
        $statement = $db->prepare('SELECT $1::int AS n');
        demand($statement->execute([37])->await()[0]['n'] === '37', 'Prepared bind over mTLS');
        demand($statement->execute([38])->await()[0]['n'] === '38' && $db->statistics()['preparedHits'] === 1, 'Prepared reuse over mTLS');
        // An independent authenticated connection holds a real server lock.
        // It cannot release it until two timers have run while the query waits.
        $owner = Connection::connect($base, timeout: 5)->await();
        try {
            $owner->query('SELECT pg_advisory_lock(910223)')->await();
            $pending = $db->query('SELECT pg_advisory_lock(910223)');
            for ($i = 0; $i < 2; $i++) {
                Async::sleep(.002);
                demand(!$pending->isCompleted(), 'Locked query completed before independent release');
                $ticks++;
            }
            $owner->query('SELECT pg_advisory_unlock(910223)')->await();
            $pending->await();
            $db->query('SELECT pg_advisory_unlock(910223)')->await();
        } finally { $owner->close(); }
        $db->transaction(function (Connection $tx): void {
            $tx->query('CREATE TEMP TABLE mtls_tx(n int)')->await();
            $tx->query('INSERT INTO mtls_tx VALUES(42)')->await();
        })->await();
        demand($db->query('SELECT n FROM mtls_tx')->await()[0]['n'] === '42', 'Transaction over mTLS');
    } finally { $db->close(); $db->close(); }

    $secret = file_get_contents($fixture['passphraseFile']);
    // Exercise every allowed native option in one call, including defaults.
    $encrypted = array_replace($base, ['sslkey'=>$fixture['encryptedKey'], 'sslpassword'=>$secret, 'password'=>'unused-with-certificate-auth']);
    $db = Connection::connect($encrypted, timeout: 5)->await();
    try { demand($db->query('SELECT 1 AS n')->await()[0]['n'] === '1', 'Encrypted client key'); }
    finally { $db->close(); }
    $invalid = [
        'missing-cert' => array_diff_key($base, ['sslcert'=>1,'sslkey'=>1]),
        'unknown-client-ca' => array_replace($base, ['sslcert'=>$fixture['untrustedCert'],'sslkey'=>$fixture['untrustedKey']]),
        'wrong-client-name' => array_replace($base, ['sslcert'=>$fixture['otherCert'],'sslkey'=>$fixture['otherKey']]),
        'key-mismatch' => array_replace($base, ['sslkey'=>$fixture['otherKey']]),
        'wrong-server-ca' => array_replace($base, ['sslrootcert'=>$fixture['untrustedCa']]),
        'wrong-server-name' => array_replace($base, ['host'=>'wrong.invalid']),
        'missing-cert-file' => array_replace($base, ['sslcert'=>$fixture['missingPath']]),
        'missing-key-file' => array_replace($base, ['sslkey'=>$fixture['missingPath']]),
        'encrypted-wrong-password' => array_replace($encrypted, ['sslpassword'=>'deliberately-wrong-test-value']),
        'encrypted-no-password' => array_diff_key($encrypted, ['sslpassword'=>1]),
        'empty-password' => array_replace($encrypted, ['sslpassword'=>'']),
        'incomplete-pair' => array_diff_key($base, ['sslkey'=>1]),
        'empty-cert' => array_replace($base, ['sslcert'=>'']),
        'key-engine' => array_replace($base, ['sslkey'=>'engine:unsupported']),
        'key-password-only' => ['host'=>'127.0.0.1','sslpassword'=>'nonempty-test-value'],
        'unix-socket' => array_replace($base, ['host'=>'/tmp']),
        'downgrade' => array_replace($base, ['sslmode'=>'disable']),
    ];
    foreach ($invalid as $name=>$options) {
        $cases[$name] = rejected(fn()=>Connection::connect($options, timeout: 5)->await(), $name);
    }
    $db = Connection::connect(array_diff_key($base, ['sslmode'=>1]), timeout: 5)->await();
    try { demand($db->query('SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()')->await()[0]['ssl'] === 't', 'Default verify-full mTLS'); }
    finally { $db->close(); }
    // A rejected handshake must not poison a later independent connection.
    $db = Connection::connect($base, timeout: 5)->await();
    try {
        $owner = Connection::connect($base, timeout: 5)->await();
        try {
            $owner->query('SELECT pg_advisory_lock(910224)')->await();
            $query = $db->query('SELECT pg_advisory_lock(910224)');
            Async::sleep(.002); demand(!$query->isCompleted(), 'Cancellation requires pending query');
            $query->cancel();
            try { $query->await(); throw new RuntimeException('Cancelled query resolved'); }
            catch (CancelledException) {}
            demand($db->statistics()['closed'], 'Operation cancellation did not close its connection');
            $owner->query('SELECT pg_advisory_unlock(910224)')->await();
        } finally { $owner->close(); }
    } finally { $db->close(); }
});
if (($GLOBALS['argv'][1] ?? '') !== 'probe') {
    ini_set('open_basedir', dirname(__DIR__,2).PATH_SEPARATOR.dirname(getenv('ASYNCARA_PG_MTLS_FIXTURE')));
    $warnings=0;
    set_error_handler(static function(int $severity,string $message)use(&$warnings):bool{
        if($severity!==E_WARNING || !str_contains($message,'open_basedir'))return false;
        $warnings++;return true;
    });
    try {
        $caught=false;
        try{asyncara_pg_open(array_replace($fixture['options'],['sslcert'=>'/outside-asyncara-mtls/client.pem']));}
        catch(ValueError $e){$caught=str_contains($e->getMessage(),'open_basedir');}
        demand($caught && $warnings>0,'Client certificate bypassed open_basedir');
    } finally {restore_error_handler();}
}
gc_collect_cycles();
demand(asyncara_info()['pendingOperations'] === 0 && asyncara_info()['nativeMemoryBytes'] === 0 && Future::pendingCount() === 0, 'Pending native/Future owners after mTLS');
echo json_encode(['status'=>'PASS','backend'=>asyncara_info()['eventLoopBackend'],'invalidCases'=>$cases,'ticksWhilePending'=>$ticks,'native'=>asyncara_info()]), "\n";
