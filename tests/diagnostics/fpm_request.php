<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\Trace;
if (Trace::$mask !== 0 || asyncara_info()['nativeDiagnosticBytes'] !== 0) throw new RuntimeException('Cross-request diagnostic state');
Trace::enable(4096);
register_shutdown_function(static function (): void {
    // This is PHP's post-execution callback, before extension RSHUTDOWN. It
    // cannot observe the subsequent worker joins or survive hard termination.
    $path=dirname($_SERVER['AUDIT_LARGE_FILE']).'/traces/'.getmypid().'-'.(int)$_SERVER['AUDIT_ID'].'.json';
    try {
        // Original fixture intentionally leaves worker jobs pending. A snapshot
        // can correctly reject admission while one is publishing. Retry only
        // here, after request execution, at most three times without sleeping.
        for($attempt=1;;$attempt++) {
            try {$trace=asyncara_trace_snapshot();break;}
            catch (Exception $e) {if($e->getCode()!==16 || $attempt===3)throw $e;}
        }
        $trace['extractionAttempts']=$attempt;
        $trace['phase']='PHP_SHUTDOWN_CALLBACK_BEFORE_RSHUTDOWN';
        $trace['fatal']=error_get_last() !== null;
        $json=json_encode($trace,JSON_THROW_ON_ERROR)."\n";
        $file=fopen($path,'xb');
        if (!$file) throw new RuntimeException('Trace create failed');
        try {if(fwrite($file,$json)!==strlen($json)||!fflush($file))throw new RuntimeException('Incomplete trace output');}finally{fclose($file);}
    } catch (Throwable $e) {
        file_put_contents($path.'.failed',get_class($e).': '.$e->getMessage()."\n");
    }
});
// Retain every original assertion, ticker and fault workload verbatim.
require dirname(__DIR__).'/audit/fpm_request.php';
