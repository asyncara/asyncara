<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Scheduler};use Asyncara\HTTP\HttpClient;use Asyncara\IO\{File,Files};
use Asyncara\Exception\{OperationException,CancelledException,FilesystemException,TimeoutException};
function check(bool $v,string $why):void{if(!$v)throw new RuntimeException($why);}
function rejects(string $class,callable $fn):void{try{$fn();}catch(Throwable $e){if($e instanceof $class)return;throw$e;}throw new RuntimeException('Expected '.$class);}
$root=dirname(__DIR__,2);$dir=sys_get_temp_dir().'/asyncara-http-'.bin2hex(random_bytes(8));mkdir($dir,0700);
$body=str_repeat('multipart-body-\0',32768);file_put_contents($dir.'/one',$body);file_put_contents($dir.'/empty','');file_put_contents($dir.'/two',strrev($body));file_put_contents($dir.'/target','OLD');
$server=proc_open(['python3',dirname(__DIR__).'/fixtures/http_stream_server.py',"$root/build/test-certs/cert.pem","$root/build/test-certs/key.pem"],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',"$root/build/http-completion-server.log",'a']],$pipes);
$ports=json_decode(fgets($pipes[1]),true,512,JSON_THROW_ON_ERROR);$url='http://127.0.0.1:'.$ports['http'];$stage='start';$result=[];
try{
 Async::run(function()use($url,$dir,$body,$ports,$root,&$stage,&$result){
  $client=new HttpClient(concurrency:4,timeout:5);$files=[['field'=>'file[]','path'=>$dir.'/one','filename'=>'one.bin'],['field'=>'empty','path'=>$dir.'/empty'],['field'=>'file[]','path'=>$dir.'/two','contentType'=>'text/plain']];
  $stage='multiple MIME files';
  for($n=0;$n<4;$n++){
   $r=$client->multipartFiles($url.'/multipart',['description'=>'hello','binary'=>"a\0b"],$files)->await()->json();
   check(count($r['parts'])===5,'MIME part count');foreach([hash('sha256',$body),hash('sha256',''),hash('sha256',strrev($body))]as$i=>$h)check($r['parts'][$i+2]['sha256']===$h,'MIME part hash');
   check($r['parts'][2]['name']==='file[]'&&$r['parts'][4]['name']==='file[]','duplicate field names');
  }
  $stage='missing file and permit recovery';$bad=$files;$bad[1]['path']=$dir.'/missing';rejects(OperationException::class,fn()=>$client->multipartFiles($url.'/multipart',[],$bad)->await());check($client->get($url.'/empty')->await()->status===204,'permit recovery');
  rejects(ValueError::class,fn()=>$client->multipartFiles($url,[],[]));rejects(ValueError::class,fn()=>$client->multipartFiles($url,[],array_fill(0,17,$files[0])));$bad=$files;$bad[0]['filename']="bad\r\nInjected: x";rejects(ValueError::class,fn()=>$client->multipartFiles($url,[],$bad));
  $stage='stream cancellation';$large=array_fill(0,16,$files[0]);$job=$client->multipartFiles($url.'/slow-upload',[],$large);Async::sleep(.01);$job->cancel();rejects(CancelledException::class,$job->await(...));
  $stage='atomic failure';rejects(OperationException::class,fn()=>$client->download($url.'/broken',$dir.'/target',atomic:true)->await());check(Files::read($dir.'/target')->await()==='OLD','partial response replaced destination');
  rejects(OperationException::class,fn()=>$client->download($url.'/status',$dir.'/target',atomic:true,failOnHttpError:true)->await());check(Files::read($dir.'/target')->await()==='OLD','HTTP error published');
  $job=$client->download($url.'/stall',$dir.'/target',atomic:true);Async::sleep(.01);$job->cancel();rejects(CancelledException::class,$job->await(...));check(Files::read($dir.'/target')->await()==='OLD','cancelled download published');
  check(!glob($dir.'/.asyncara-*'),'failed staging file leaked');
  $stage='atomic successful and durable';$bytes=$client->download($url.'/medium',$dir.'/target',atomic:true,durable:true)->await();check($bytes===8388608&&Files::stat($dir.'/target')->await()['size']===$bytes,'atomic size');check((Files::stat($dir.'/target')->await()['mode']&0777)===0600,'private staging mode');
  $stage='pinned directory';Files::mkdir($dir.'/pinned')->await();$file=File::stage($dir.'/pinned/result')->await();Files::rename($dir.'/pinned',$dir.'/moved')->await();Files::mkdir($dir.'/pinned')->await();$file->write('PINNED')->await();$file->publish(true)->await();rejects(FilesystemException::class,fn()=>$file->publish()->await());$file->close()->await();$file->close()->await();check(Files::read($dir.'/moved/result')->await()==='PINNED'&&!Files::exists($dir.'/pinned/result')->await(),'publish reopened destination directory');
  $stage='cancelled staging close';$file=File::stage($dir.'/discard')->await();$file->write('discard')->await();$close=$file->close();Async::sleep(0);$close->cancel();try{$close->await();}catch(CancelledException){}check(!glob($dir.'/.asyncara-*'),'cancelled close left staging file');
  $stage='publish invalid target';$file=File::stage($dir.'/moved')->await();$file->write('not a directory')->await();rejects(FilesystemException::class,fn()=>$file->publish()->await());$file->close()->await();check(!glob($dir.'/.asyncara-*'),'publish error temp cleanup');
  $stage='redirect body and loop';$s=$client->getStream($url.'/redirect-body',followRedirects:true);$chunks=iterator_to_array($s);$s->close();check(strlen(implode('',$chunks))===65536,'intermediate redirect body leaked');rejects(OperationException::class,fn()=>$client->get($url.'/redirect-loop',followRedirects:true)->await());rejects(OperationException::class,fn()=>$client->get($url.'/redirect-file',followRedirects:true)->await());
  $stage='TLS multiple-file';$tls=new HttpClient(caFile:"$root/build/test-certs/cert.pem");check(count($tls->multipartFiles('https://localhost:'.$ports['https'].'/multipart',[],$files)->await()->json()['parts'])===3,'TLS MIME');
  $stage='reuse isolation and POST redirect';
  check($client->request('POST',$url.'/post-redirect','data',followRedirects:true)->await()->json()['method']==='GET','303 preserved POST');
  check($client->get($url.'/echo-headers',['X-Marker: one','Cookie: test=one'])->await()->json()['marker']==='one','header setup');
  $clean=$client->get($url.'/echo-headers')->await()->json();check($clean['marker']===null&&$clean['cookie']===null&&$clean['length']===null,'pooled request options leaked');
  $stage='header bounds';rejects(ValueError::class,fn()=>$client->get($url.'/small',array_fill(0,257,'X: y'))->await());rejects(ValueError::class,fn()=>$client->get($url.'/small',['X: '.str_repeat('a',65536)])->await());
  $stage='native multipart validation';$metadata=[['field'=>'f','name'=>'n','type'=>'text/plain']];
  foreach([['files'=>$metadata],['files'=>$metadata,'multipart'=>[],'uploadSize'=>0],['files'=>$metadata,'multipart'=>[],'uploadSize'=>-1,'fileField'=>'mixed']]as$options)
   rejects(ValueError::class,fn()=>asyncara_submit('http',[$url,null,$options,1000]));
  $stage='native cleanup';$i=asyncara_info();check($i['pendingOperations']===0&&$i['openFileHandles']===0&&$i['nativeMemoryBytes']===0,'native owners leaked');$result=$i;
 });
 echo json_encode(['status'=>'PASS','stage'=>$stage,'native'=>$result],JSON_THROW_ON_ERROR),"\n";
}finally{
 fclose($pipes[0]);fclose($pipes[1]);proc_close($server);
 foreach(glob($dir.'/moved/*')?:[]as$f)unlink($f);foreach(glob($dir.'/pinned/*')?:[]as$f)unlink($f);if(is_dir($dir.'/moved'))rmdir($dir.'/moved');if(is_dir($dir.'/pinned'))rmdir($dir.'/pinned');foreach(array_merge(glob($dir.'/*')?:[],glob($dir.'/.asyncara-*')?:[])as$f)if(is_file($f))unlink($f);rmdir($dir);
}
