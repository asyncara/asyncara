<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Future,Runtime};
use Asyncara\HTTP\HttpClient;

function demand(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$config=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);
$root=$config['files'];mkdir($root,0700);mkdir($root.'/a');mkdir($root.'/b');$cwd=getcwd();$cases=[];
try {
    Async::run(function()use($config,$root,&$cases):void {
        $url=$config['url'];$client=new HttpClient(concurrency:1);$control=new HttpClient();
        $modes=['upload','download','atomic'];
        if($config['transport']==='curl-multi')$modes=[...$modes,'multipart','multi'];
        foreach([false,true]as$queued)foreach($modes as$mode){
            file_put_contents($root.'/a/value','first');file_put_contents($root.'/b/value','second');
            $hold=null;
            if($queued){$hold=$client->getStream($url.'/headers-first');demand($hold->headers()->await()->status===200,'Held response head');}
            chdir($root.'/a');
            $operation=match($mode){
                'upload'=>$client->upload($url.'/upload','value'),
                'multipart'=>$client->multipart($url.'/multipart',[],'data','value'),
                'multi'=>$client->multipartFiles($url.'/multipart',[],[['field'=>'data','path'=>'value'],['field'=>'data2','path'=>'value']]),
                'download'=>$client->download($url.'/small','value'),
                'atomic'=>$client->download($url.'/small','value',atomic:true),
            };
            chdir($root.'/b');$ticks=0;
            if($hold){
                // Independent peer gate keeps real network I/O pending until two ticks.
                for($i=0;$i<2;$i++){Async::sleep(.002);demand(!$operation->isCompleted(),'Queued file request completed before permit');$ticks++;}
                $control->get($url.'/release-body')->await();$hold->consume(static fn($part)=>null);$hold->close();
            }
            $value=$operation->await();
            if(in_array($mode,['download','atomic'],true)){
                demand($value===65536&&strlen(file_get_contents($root.'/a/value'))===65536&&file_get_contents($root.'/b/value')==='second','Download changed submitted directory');
            }else{
                $value=$value->json();
                if($mode==='upload')demand($value['sha256']===hash('sha256','first'),'Upload read another file');
                else{demand(count($value['parts'])===($mode==='multi'?2:1),'Multipart count');foreach($value['parts']as$part)demand($part['sha256']===hash('sha256','first'),'Multipart read another file');}
            }
            demand(asyncara_info()['openFileHandles']===0,'File descriptor retained');
            $cases[]=['mode'=>$mode,'queued'=>$queued,'ticksWhilePending'=>$ticks];
        }
    });
    gc_collect_cycles();$native=asyncara_info();$d=Runtime::diagnostics();
    demand(!$native['openFileHandles']&&!$native['pendingOperations']&&!$native['nativeMemoryBytes']&&!Future::pendingCount()&&!$d['activeTasks']&&!$d['activeTimers']&&!$d['ioWatchers'],'File request owner leak');
    echo json_encode(['status'=>'PASS','cases'=>$cases,'backend'=>$native['eventLoopBackend'],'transport'=>$config['transport'],'php'=>PHP_VERSION]),"\n";
}finally{chdir($cwd);foreach(['a','b']as$d){foreach(glob($root.'/'.$d.'/*')as$f)unlink($f);rmdir($root.'/'.$d);}rmdir($root);}
