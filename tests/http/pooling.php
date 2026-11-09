<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\Async;use Asyncara\HTTP\HttpClient;
$p=proc_open(['python3',dirname(__DIR__,2).'/benchmarks/http/server.py'],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',dirname(__DIR__,2).'/build/pool-server.log','a']],$pipes);
try{
 $port=json_decode(fgets($pipes[1]),true,512,JSON_THROW_ON_ERROR)['port'];
 Async::run(function()use($port){$client=new HttpClient();for($i=0;$i<200;$i++){if(strlen($client->get('http://127.0.0.1:'.$port.'/body?size=1024')->await()->body)!==1024)throw new RuntimeException('Response');}});
 $i=asyncara_info();if($i['httpHandlesCreated']!==1||$i['httpHandlesReused']!==199||$i['httpCachedHandles']!==1||$i['pendingOperations']||$i['nativeMemoryBytes'])throw new RuntimeException('Pool did not reuse/collect exactly');
 echo json_encode($i),"\n";
}finally{fclose($pipes[0]);stream_get_contents($pipes[1]);fclose($pipes[1]);proc_close($p);}
