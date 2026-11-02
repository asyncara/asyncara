<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Asyncara\Async;
use Asyncara\IO\Files;
$directory = sys_get_temp_dir().'/asyncara-atomic-bench-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
$results = [];
try {
    foreach (['write','atomic','durable'] as $mode) for ($sample = 0; $sample < 3; $sample++) {
        $latencies = []; $body = str_repeat('x', 65536); $path = $directory.'/data';
        $start = hrtime(true);
        Async::run(function () use ($mode,$body,$path,&$latencies): void {
            for ($i = 0; $i < 100; $i++) {
                $begin = hrtime(true);
                if ($mode === 'write') Files::write($path,$body)->await();
                else Files::replaceAtomic($path,$body,durable:$mode === 'durable')->await();
                $latencies[] = (hrtime(true)-$begin)/1e6;
            }
        });
        $seconds = (hrtime(true)-$start)/1e9; sort($latencies);
        $results[] = ['mode'=>$mode,'sample'=>$sample,'operations'=>100,'bytesPerWrite'=>65536,
            'seconds'=>$seconds,'operationsPerSecond'=>100/$seconds,'medianMs'=>$latencies[49],
            'p95Ms'=>$latencies[94],'p99Ms'=>$latencies[98],'latenciesMs'=>$latencies];
    }
} finally { unlink($directory.'/data'); rmdir($directory); }
echo json_encode(['php'=>PHP_VERSION,'platform'=>php_uname(),'note'=>'Sequential warm-cache worker writes; shared host; durability syscalls are additional semantics, not equivalent workloads','samples'=>$results],JSON_PRETTY_PRINT),"\n";
