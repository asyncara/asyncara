<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Runtime,Scheduler,Future};
use Asyncara\Concurrency\Queue;
use Asyncara\IO\{Files,TcpServer,TcpStream};
use Asyncara\HTTP\HttpClient;
use Asyncara\Pool\ProcessPool;
use Asyncara\SQLite\Connection as Sqlite;
use Asyncara\PostgreSQL\Connection as Pg;
use Asyncara\MySQL\Connection as Maria;
use Asyncara\Redis\Connection as Redis;
use Asyncara\Exception\{CancelledException,TimeoutException,WorkerException};
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function osSample():array {
    $status=file_get_contents('/proc/self/status');preg_match('/VmRSS:\s+(\d+)/',$status,$rss);preg_match('/Threads:\s+(\d+)/',$status,$threads);
    $fds=glob('/proc/self/fd/*');$sockets=0;
    foreach($fds as$fd)if(is_link($fd)&&str_starts_with(readlink($fd),'socket:'))$sockets++;
    return ['rssKiB'=>(int)$rss[1],'threads'=>(int)$threads[1],'fds'=>count($fds),'sockets'=>$sockets];
}
$seconds=(float)(getenv('ASYNCARA_SOAK_SECONDS')?:60);$taskCount=(int)(getenv('ASYNCARA_SOAK_TASKS')?:1000);
if($seconds<=0||$taskCount<1||$taskCount>10000)throw new ValueError('Invalid soak duration/task count');
$start=Scheduler::now();$path=tempnam(sys_get_temp_dir(),'asyncara-soak-');$cycles=0;
try{Async::run(function()use($seconds,$taskCount,$start,$path,&$cycles):void{
    $pool=new ProcessPool(2,dirname(__DIR__).'/fixtures/jobs.php');$http=new HttpClient(4,timeout:2);
    $q=new Queue(8);$pg=$my=$redis=null;$dbCount=0;$ticks=0;$timeouts=0;$cancellations=0;$taskSteps=0;$latency=[];
    $ticker=Async::spawn(function()use(&$ticks):void{while(true){Async::sleep(.005);$ticks++;}});
    try{
        if(getenv('ASYNCARA_PG_SOCKET')){
            $pg=Pg::connect(['host'=>getenv('ASYNCARA_PG_SOCKET'),'port'=>'55499','dbname'=>'postgres','sslmode'=>'disable'])->await();
            $my=Maria::connect(['socket'=>getenv('ASYNCARA_MY_SOCKET'),'user'=>'root','database'=>'mysql'])->await();
            $redis=Redis::connect('127.0.0.1',(int)getenv('ASYNCARA_REDIS_PORT'),resp3:true)->await();$dbCount=3;
        }
        $nextSample=0;
        while(Scheduler::now()-$start<$seconds){
            $db=Sqlite::open(':memory:')->await();
            try{
                $db->query('CREATE TABLE t(n INTEGER)')->await();
                Async::taskGroup(function($g)use($q,$db,$path,$pool,$http,$pg,$my,$redis,$taskCount,&$latency,&$taskSteps):void{
                    $g->spawn(function()use($taskCount,&$latency,&$taskSteps):void{
                        $tasks=[];$dispatch=Scheduler::now();
                        for($i=0;$i<$taskCount;$i++)$tasks[]=Async::spawn(function()use($i,$dispatch,&$latency):int{
                            if($i<32)$latency[]=Scheduler::now()-$dispatch;
                            Async::sleep(($i%7)*.0001);return$i;
                        });
                        check(array_sum(Async::gather($tasks))===intdiv($taskCount*($taskCount-1),2),'Task results');$taskSteps+=$taskCount;
                    });
                    $g->spawn(function()use($q):void{for($i=0;$i<32;$i++)$q->put($i);});
                    $g->spawn(function()use($q,$db):void{for($i=0;$i<32;$i++){$v=$q->get();$db->query('INSERT INTO t VALUES (?)',[$v])->await();$q->taskDone();}});
                    $g->spawn(function()use($path):void{Files::write($path,str_repeat('x',32768))->await();check(strlen(Files::read($path)->await())===32768,'File contents');});
                    $g->spawn(function()use($pool):void{check(count(Async::gather([$pool->submit('cpu',[1000]),$pool->submit('cpu',[1000])]))===2,'Parallel jobs');});
                    $g->spawn(function()use($http):void{
                        $url=getenv('ASYNCARA_SOAK_URL');$tasks=[];
                        for($i=0;$i<4;$i++)$tasks[]=$http->get($url);
                        foreach(Async::gather($tasks)as$r)check($r->status===200&&strlen($r->body)===8192,'HTTP response');
                        $stream=$http->getStream($url);$bytes=0;
                        try{
                            check($stream->headers()->await()->status===200,'HTTP stream status');
                            foreach($stream as$chunk)$bytes+=strlen($chunk);
                            check($bytes===8192,'HTTP stream bytes');
                        }finally{$stream->close();}
                    });
                    $g->spawn(function():void{
                        $server=new TcpServer();$port=(int)substr(strrchr($server->address(),':'),1);
                        try{
                            $echo=Async::spawn(function()use($server):void{$s=$server->accept();try{$s->write($s->readExactly(4));}finally{$s->close();}});
                            $client=TcpStream::connect('127.0.0.1',$port)->await();
                            try{$client->write('ping');check($client->readExactly(4)==='ping','TCP echo');}finally{$client->close();}
                            $echo->await();
                        }finally{$server->close();}
                    });
                    if($pg)$g->spawn(fn()=>$pg->transaction(fn($c)=>$c->query('SELECT 42 AS n')->await())->await());
                    if($my)$g->spawn(fn()=>$my->transaction(fn($c)=>$c->query('SELECT 42 AS n')->await())->await());
                    if($redis)$g->spawn(function()use($redis):void{check($redis->pipeline([['PING'],['ECHO','ok']])->await()===['PONG','ok'],'Redis pipeline');});
                });
                check($db->query('SELECT sum(n) AS n FROM t')->await()[0]['n']===496,'SQLite results');
            }finally{$db->close()->await();}
            $q->join();
            $cancelled=Async::spawn(fn()=>Async::sleep(60));Async::sleep(0);$cancelled->cancel();
            try{$cancelled->await();throw new RuntimeException('Cancellation ignored');}catch(CancelledException){$cancellations++;}
            try{Async::timeout(.001,fn()=>Async::sleep(60));throw new RuntimeException('Timeout ignored');}catch(TimeoutException){$timeouts++;}
            if(++$cycles%100===0){try{$pool->submit('crash')->await();throw new RuntimeException('Crash not observed');}catch(WorkerException){}check($pool->submit('sum',[10])->await()===55,'Crash recovery');}
            if($cycles%25===0)Async::sleep(.05);
            gc_collect_cycles();
            $elapsed=Scheduler::now()-$start;
            if($elapsed>=$nextSample){
                sort($latency);$percentile=fn($p)=>$latency?1000*$latency[min(count($latency)-1,(int)floor($p*count($latency)))]:null;
                $native=Runtime::information();
                check($native->pendingOperations===0&&$native->openFileHandles===0&&$native->sqliteConnections===0,'Retained native work at cycle boundary');
                echo json_encode(['type'=>'sample','seconds'=>$elapsed,'cycles'=>$cycles,'tasksExecuted'=>$taskSteps,'phpBytes'=>memory_get_usage(),'phpAllocatedBytes'=>memory_get_usage(true),'os'=>osSample(),'native'=>$native,'scheduler'=>Runtime::diagnostics(),'processPool'=>$pool->statistics(),'queue'=>$q->statistics(),'databaseConnections'=>$dbCount,'ticks'=>$ticks,'timeouts'=>$timeouts,'cancellations'=>$cancellations,'unexpectedErrors'=>0,'schedulingLatencyMs'=>['median'=>$percentile(.5),'p95'=>$percentile(.95),'p99'=>$percentile(.99)]],JSON_THROW_ON_ERROR),"\n";
                $latency=[];$nextSample=$elapsed+5;
            }elseif(count($latency)>1024)$latency=array_slice($latency,-1024);
        }
    }finally{
        $ticker->cancel();try{$ticker->await();}catch(CancelledException){}
        $pg?->close();$my?->close();$redis?->close();$pool->close();$q->close();
    }
});
gc_collect_cycles();check(Future::pendingCount()===0,'Pending Futures after shutdown');
echo json_encode(['type'=>'complete','seconds'=>Scheduler::now()-$start,'cycles'=>$cycles,'native'=>Runtime::information(),'scheduler'=>Runtime::diagnostics(),'os'=>osSample()],JSON_THROW_ON_ERROR),"\n";
}finally{unlink($path);}
