<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
$root=dirname(__DIR__,2);
$p=proc_open(['python3',dirname(__DIR__).'/fixtures/http_stream_server.py',"$root/build/test-certs/cert.pem","$root/build/test-certs/key.pem"],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',"$root/build/options-lifetime-server.log",'a']],$pipes);
function results(array $ids): array {
    $values=[];
    while(count($values)<count($ids)) foreach(asyncara_poll([],[],1000)['results'] as $item) $values[$item['id']]=$item;
    return $values;
}
try {
    $ports=json_decode(fgets($pipes[1]),true,512,JSON_THROW_ON_ERROR);$url='http://127.0.0.1:'.$ports['http'];
    for($i=0;$i<100;$i++) {
        $method='POST';$headers=[];$follow=false;$connect=1000;$body='retained-'.$i;
        $options=['method'=>&$method,'headers'=>&$headers,'followRedirects'=>&$follow,'connectTimeoutMs'=>&$connect];
        $id=asyncara_submit('http',[$url.'/echo',$body,$options,2000]);
        $method='GET';$headers=['X-Wrong: wrong'];$follow=true;$connect=1;$body='changed';unset($options);
        $r=results([$id])[$id];if($r['error'])throw new RuntimeException($r['error']);
        $v=json_decode($r['value']['body'],true,512,JSON_THROW_ON_ERROR);
        if($v['method']!=='POST'||$v['sha256']!==hash('sha256','retained-'.$i))throw new RuntimeException('Submission retained borrowed PHP storage');
    }
    $options=['method'=>'GET','headers'=>[],'followRedirects'=>false,'connectTimeoutMs'=>1000];
    foreach([['method'=>"GET\0bad"],['connectTimeoutMs'=>0],['followRedirects'=>1],['headers'=>['bad'=>"x\r\ny"]]] as $bad) {
        try { asyncara_submit('http',[$url,null,array_replace($options,$bad),1000]);throw new RuntimeException('Invalid native options accepted'); }
        catch(ValueError) {}
    }
    // Fail admission after validation, before start. No borrowed stack pointers
    // may survive into native failure cleanup or request shutdown.
    $ids=[];for($i=0;$i<1024;$i++)$ids[]=asyncara_submit('http',[$url.'/empty',null,$options,2000]);
    try{asyncara_submit('http',[$url,null,$options,2000]);throw new RuntimeException('Queue limit bypassed');}
    catch(Exception $e){if($e instanceof RuntimeException)throw$e;}
    foreach($ids as$id)asyncara_cancel($id);
    foreach(results($ids)as$r)if($r['error']!=='Operation cancelled')throw new RuntimeException('Missing cancelled completion');
    if(asyncara_info()['pendingOperations']||asyncara_info()['nativeMemoryBytes'])throw new RuntimeException('Options/result owners leaked');
    echo "PASS borrowed option lifetime, references, validation, admission failure, 1024 cancellations\n";
}finally{foreach($pipes as$pipe)fclose($pipe);if(proc_close($p)!==0)throw new RuntimeException('Fixture failed');}
