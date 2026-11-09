<?php
declare(strict_types=1);
require (getenv('ASYNCARA_SOURCE_ROOT') ?: dirname(__DIR__,2)).'/autoload.php';
use Asyncara\{Async,Runtime};use Asyncara\HTTP\HttpClient;
$p=proc_open(['python3',dirname(__DIR__,2).'/benchmarks/http/server.py'],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',dirname(__DIR__,2).'/build/budget-server.log','a']],$pipes);
try {
 $port=json_decode(fgets($pipes[1]),true,512,JSON_THROW_ON_ERROR)['port'];Runtime::configure(['nativeMemoryLimit'=>7*1024*1024]);
 Async::run(function()use($port){$r=(new HttpClient())->get('http://127.0.0.1:'.$port.'/body?size=3145728')->await();if($r->body!==str_repeat('x',3145728))throw new RuntimeException('Buffer ownership corruption');});
 $i=asyncara_info();if($i['nativeMemoryBytes']||$i['pendingOperations'])throw new RuntimeException('Uncollected native result');echo json_encode($i),"\n";
}finally{fclose($pipes[0]);stream_get_contents($pipes[1]);fclose($pipes[1]);proc_close($p);}
