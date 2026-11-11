<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Future,Scheduler};
use Asyncara\HTTP\HttpClient;
$config=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);
$mode=$argv[2];$result=[];
function demand(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
if(!str_contains($mode,'basedir')){
    $before=getcwd();
    try{
        Async::run(function()use($config,$mode):void{
            chdir($config['trusted']);
            $client=new HttpClient(concurrency:1,caFile:'ca.pem');
            if($mode==='client-config-relative')chdir($config['untrusted']);
            $other=null;
            if($mode==='native-relative')$pending=Scheduler::current()->native('http',[$config['url'].'/small',null,['method'=>'GET','headers'=>[],'caFile'=>'ca.pem'],5000]);
            elseif($mode==='stream-relative')$pending=$client->getStream($config['url'].'/small');
            elseif($mode==='upload-relative')$pending=$client->requestStream('POST',$config['url'].'/upload',['payload'],7);
            else{
                if($mode==='client-queued')$other=$client->get($config['url'].'/small');
                $pending=$client->get($config['url'].'/small');
            }
            chdir($config['untrusted']);
            if($other)demand(strlen($other->await()->body)===65536,'First admitted request failed');
            if($mode==='stream-relative'){
                $count=0;
                try{$pending->consume(static function(string $chunk)use(&$count):void{$count+=strlen($chunk);});}
                finally{$pending->close();}
                demand($count===65536,'Wrong streamed trust snapshot');
            }elseif($mode==='upload-relative')demand($pending->await()->json()['sha256']===hash('sha256','payload'),'Wrong upload');
            else{
                $value=$pending->await();
                demand(strlen($mode==='native-relative'?$value['body']:$value->body)===65536,'Wrong relative trust snapshot');
            }
        });
    }finally{chdir($before);}
}else{
    demand(in_array($mode,['basedir','native-basedir','proxy-basedir','native-proxy-basedir'],true),'Unknown check');
    ini_set('open_basedir',dirname(__DIR__,2).PATH_SEPARATOR.$config['allowed']);
    $warnings=0;
    set_error_handler(static function(int $level,string $message)use(&$warnings):bool{
        if($level!==E_WARNING||!str_contains($message,'open_basedir'))return false;$warnings++;return true;
    });
    try{
        Async::run(function()use($config,$mode):void{
            for($i=0;$i<40;$i++){
                $rejected=false;
                try{
                    if($mode==='native-proxy-basedir')Scheduler::current()->native('http',['http://proxy-target.invalid/small',null,['method'=>'GET','headers'=>[],'proxy'=>$config['url'],'proxyCaFile'=>$config['forbidden']],5000])->await();
                    elseif($mode==='proxy-basedir')(new HttpClient(proxy:$config['url'],proxyCaFile:$config['forbidden']))->get('http://proxy-target.invalid/small')->await();
                    elseif($mode==='native-basedir')Scheduler::current()->native('http',[$config['url'].'/small',null,['method'=>'GET','headers'=>[],'caFile'=>$config['forbidden']],5000])->await();
                    else(new HttpClient(caFile:$config['forbidden']))->get($config['url'].'/small')->await();
                }catch(ValueError $e){$rejected=str_contains($e->getMessage(),'open_basedir');}
                demand($rejected,'HTTP native trust file bypassed open_basedir');
            }
        });
        demand($warnings>=40,'Missing PHP path warnings');
    }finally{restore_error_handler();}
}
gc_collect_cycles();
demand(asyncara_info()['pendingOperations']===0&&asyncara_info()['nativeMemoryBytes']===0&&Future::pendingCount()===0,'Trust path failure leaked owners');
echo json_encode(['status'=>'PASS','mode'=>$mode,'backend'=>asyncara_info()['eventLoopBackend'],'php'=>PHP_VERSION,'debug'=>PHP_DEBUG,'zts'=>PHP_ZTS]),"\n";
