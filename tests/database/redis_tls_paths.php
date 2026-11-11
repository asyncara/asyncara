<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Future,Runtime};
use Asyncara\IO\TcpStream;
use Asyncara\Redis\Connection;
$c=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);$mode=$argv[2];$original=getcwd();$warnings=0;
function demand(bool $value,string $why):void{if(!$value)throw new RuntimeException($why);}
function connectPath(array $c,string $mode,string $ca):Future{
 return str_starts_with($mode,'redis')?Connection::connect('127.0.0.1',$c['port'],password:$c['password'],user:'asyncara',resp3:true,tls:true,caFile:$ca):TcpStream::connect('127.0.0.1',$c['port'],tls:true,caFile:$ca);
}
if($mode==='native-input'){
 foreach(['',"bad\0name","bad\nname","bad\rname",'file:///local','https://remote',str_repeat('x',4096),'/' . str_repeat('x',4096)]as$path){
  try{asyncara_tls_ca_path($path);throw new RuntimeException('Malformed CA path admitted');}catch(ValueError){}
 }
 chdir(dirname($c['ca']));demand(asyncara_tls_ca_path('ca.pem')===$c['ca'],'Native relative capture');demand(asyncara_tls_ca_path($c['ca'])===$c['ca'],'Native absolute capture');chdir($original);
}elseif(str_contains($mode,'basedir')||$mode==='native-warning'){
 chdir($c['allowedDirectory']);
 set_error_handler(static function(int $level,string $message)use(&$warnings,$mode):bool{
  if($level!==E_WARNING||!str_contains($message,'open_basedir'))return false;
  $warnings++;if($mode==='native-warning')throw new DomainException('Path warning handler');return true;
 });
 try{
  Async::run(function()use($c,$mode):void{
   $late=str_contains($mode,'late');$pending=$late?connectPath($c,$mode,$c['ca']):null;
   $path=str_contains($mode,'symlink')?$c['allowedDirectory'].'/linked-ca.pem':(str_contains($mode,'-dot-')?$c['allowedDirectory'].'/../certs/ca.pem':$c['ca']);
   ini_set('open_basedir',dirname(__DIR__,2).PATH_SEPARATOR.$c['allowedDirectory']);
   for($i=0;$i<($late?1:40);$i++){
    try{
     if($mode==='native-warning')asyncara_tls_ca_path($c['ca']);
     elseif($pending)$pending->await();else connectPath($c,$mode,$path)->await();
     throw new RuntimeException('Explicit TLS trust escaped path policy');
    }catch(ValueError $error){demand(str_contains($error->getMessage(),'open_basedir'),'Wrong path rejection');}
    catch(DomainException $error){demand($mode==='native-warning'&&$error->getMessage()==='Path warning handler','Overwritten warning exception');}
   }
  });
  demand($warnings>0,'PHP path policy warning absent');
 }finally{restore_error_handler();}
}else{
 try{
  Async::run(function()use($c,$mode):void{
   chdir(dirname($c['ca']));$path=str_ends_with($mode,'absolute')?$c['ca']:'ca.pem';
   $pending=connectPath($c,$mode,$path);chdir($c['wrongDirectory']);
   $connection=$pending->await();
   try{if($connection instanceof Connection)demand($connection->command(['PING'])->await()==='PONG','Redis TLS data');}
   finally{$connection->close();$connection->close();}
  });
 }finally{chdir($original);}
}
gc_collect_cycles();$native=asyncara_info();$d=Runtime::diagnostics();demand(!$native['pendingOperations']&&!$native['nativeMemoryBytes']&&!Future::pendingCount()&&!$d['activeTasks']&&!$d['activeTimers']&&!$d['ioWatchers'],'TLS path owner leakage');
echo json_encode(['status'=>'PASS','mode'=>$mode,'php'=>PHP_VERSION,'backend'=>$native['eventLoopBackend'],'warnings'=>$warnings,'pending'=>$native['pendingOperations'],'nativeBytes'=>$native['nativeMemoryBytes']]),"\n";
