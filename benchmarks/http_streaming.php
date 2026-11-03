<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Asyncara\{Async,Runtime,Scheduler};
use Asyncara\HTTP\HttpClient;
$mode=$argv[1];$url=$argv[2];$file=$argv[3];$start=hrtime(true);
$bytes=Async::run(function()use($mode,$url,$file):int{
    $client=new HttpClient(timeout:60);
    if($mode==='buffered-8m')return strlen($client->get($url.'/medium')->await()->body);
    if($mode==='download-32m')return Async::await($client->download($url.'/large',$file));
    if($mode==='upload-32m')return Async::await($client->upload($url.'/upload',$file))->json()['bytes'];
    $stream=$client->getStream($url.($mode==='streamed-8m'?'/medium':'/large'));$bytes=0;
    try{foreach($stream as$chunk){$bytes+=strlen($chunk);if($mode==='slow-consumer-32m')Async::sleep(.0002);}}
    finally{$stream->close();}return$bytes;
});
$seconds=(hrtime(true)-$start)/1e9;
gc_collect_cycles();preg_match('/VmHWM:\s+(\d+)/',file_get_contents('/proc/self/status'),$rss);
echo json_encode(['mode'=>$mode,'bytes'=>$bytes,'seconds'=>$seconds,'MiBPerSecond'=>$bytes/1048576/$seconds,
    'phpPeakBytes'=>memory_get_peak_usage(true),'rssPeakKiB'=>(int)$rss[1],
    'native'=>Runtime::information(),'scheduler'=>Runtime::diagnostics()],JSON_THROW_ON_ERROR),"\n";
