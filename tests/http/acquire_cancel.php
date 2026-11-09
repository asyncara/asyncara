<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/autoload.php';
use Asyncara\{Async, Future, Scheduler};
use Asyncara\HTTP\HttpClient;
use Asyncara\Exception\CancelledException;

// Observe the real completion registry without changing it. Cancellation occurs
// inside the completion notification, after the stage worker has created its
// descriptor but before the download Fiber can take ownership of the File.
// No sleeps or mocked completion; the regular scheduler/worker paths execute.
$registry = new ReflectionProperty(Scheduler::class, 'native');
$directory = sys_get_temp_dir().'/asyncara-http-acquire-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
try {
    Async::run(function () use ($registry, $directory): void {
        $client = new HttpClient();
        for ($cycle = 0; $cycle < 20; $cycle++) {
            $download = $client->download('http://127.0.0.1:1/not-started', $directory.'/target', atomic: true);
            $fired = false;
            $observer = Async::spawn(function () use ($registry, $download, &$fired): void {
                for ($turn = 0; $turn < 10; $turn++) {
                    $pending = $registry->getValue(Scheduler::current());
                    if ($pending) {
                        if (count($pending) !== 1) throw new RuntimeException('Expected only the stage operation');
                        reset($pending)->subscribe(static function (Future $completion) use ($download, &$fired): void {
                            $fired = $completion->exception() === null && is_int($completion->result());
                            $download->cancel();
                        });
                        return;
                    }
                    Async::sleep(0); // cooperative dispatch, not a wall-clock delay
                }
                throw new RuntimeException('Stage boundary not observed');
            });
            try { $download->await(); throw new RuntimeException('Cancellation missing'); }
            catch (CancelledException) {}
            $observer->await();
            $info = asyncara_info();
            if (!$fired || $info['pendingOperations'] || $info['openFileHandles'] ||
                $info['nativeMemoryBytes'] || glob($directory.'/.asyncara-*')) {
                throw new RuntimeException('Stage owner escaped cancellation: '.json_encode($info));
            }
            if ($info['httpHandlesCreated']) throw new RuntimeException('Network started after cancelled acquisition');
        }
    });
    echo "PASS 20 cancellations at stage-completion ownership boundary\n";
} finally {
    // Leave a failed case's native staging entry to the real request shutdown.
    // Do not conceal the defect by unlinking it from the test.
    if (count(scandir($directory)) === 2) rmdir($directory);
}
