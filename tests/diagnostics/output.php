<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\Trace;

// Deliberately failing deferred output, never installed in a runtime I/O path.
final class BrokenTraceOutput
{
    public mixed $context;
    public static string $data = '', $mode = 'partial';
    public function stream_open(string $path,string $mode,int $options,?string &$opened):bool { self::$data='';return true; }
    public function stream_write(string $data):int {
        $n=self::$mode==='partial'?min(strlen($data),max(0,31-strlen(self::$data))):strlen($data);
        self::$data.=substr($data,0,$n);return $n;
    }
    public function stream_flush():bool { return self::$mode!=='flush'; }
    public function stream_stat():array {return [];}
}
function check(bool $value,string $message):void {if(!$value)throw new RuntimeException($message);}
stream_wrapper_register('broken-trace',BrokenTraceOutput::class);
Trace::enable(16);Trace::mark(1,true);$before=Trace::snapshot();
try {
    foreach(['partial','flush']as$mode){
        BrokenTraceOutput::$mode=$mode;$output=fopen('broken-trace://output','wb');
        try {
            $failed=false;try{Trace::writeTo($output);}catch(RuntimeException){$failed=true;}
            check($failed,'Failed extraction was reported as successful');
            if($mode==='partial'){
                check(strlen(BrokenTraceOutput::$data)===31,'Partial-write control did not reach intended boundary');
                json_decode(BrokenTraceOutput::$data,true);check(json_last_error()!==JSON_ERROR_NONE,'Truncated document accepted');
            }
            check(Trace::snapshot()===$before,'Output failure consumed/corrupted frozen trace');
        }finally{fclose($output);}
    }
    $output=fopen('php://temp','w+b');
    try{Trace::writeTo($output);rewind($output);check(json_decode(stream_get_contents($output),true,512,JSON_THROW_ON_ERROR)===$before,'Recovery export differed');}
    finally{fclose($output);}
}finally{stream_wrapper_unregister('broken-trace');}
echo "PASS partial output, failed flush, explicit failure and intact extraction recovery\n";
