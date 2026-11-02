<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Asyncara\{Async,Context,Runtime};
use Asyncara\Concurrency\{Event,Lock,Queue,Semaphore};
use Asyncara\HTTP\HttpClient;
use Asyncara\IO\{Files,TcpServer};
use Asyncara\SQLite\Connection;
use Asyncara\Pool\ProcessPool;
use Asyncara\Exception\CancelledException;

// Self-contained local demonstration: no public HTTP service or database needed.
Async::run(function():void {
    Context::set('trace_id',bin2hex(random_bytes(8)));
    $directory=sys_get_temp_dir().'/asyncara-tour-'.bin2hex(random_bytes(8));
    Files::mkdir($directory)->await();
    $db=Connection::open($directory.'/tour.db')->await();
    $pool=new ProcessPool(2,__DIR__.'/jobs.php');
    $server=new TcpServer();$port=(int)substr(strrchr($server->address(),':'),1);
    $count=6;
    try {
        $db->query('create table responses(id integer primary key, body text)')->await();
        // Minimal deterministic fixture for this example, not an HTTP server API.
        $serving=Async::spawn(fn()=>Async::taskGroup(function($group)use($server,$count):void {
            for($i=0;$i<$count;$i++){
                $socket=$server->accept();
                $group->spawn(function()use($socket):void {
                    try{
                        $headers='';while(!str_contains($headers,"\r\n\r\n")){
                            $part=$socket->read();if($part===''||strlen($headers)>8192)throw new RuntimeException('Invalid fixture request');$headers.=$part;
                        }
                        Async::sleep(.02);$body=json_encode(['service'=>'Asyncara','ok'=>true],JSON_THROW_ON_ERROR);
                        $socket->write("HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nConnection: close\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body);
                    }finally{$socket->close();}
                });
            }
        }));
        $client=new HttpClient(3);$slots=new Semaphore(3);$queue=new Queue(2);$ready=new Event();$lock=new Lock();$completed=0;
        Async::taskGroup(function($group)use($count,$port,$client,$slots,$queue,$ready,$lock,&$completed,$db,$directory):void {
            $group->spawn(function()use($count,$queue,$ready,$db,$directory):void {
                $ready->set();
                for($i=0;$i<$count;$i++){
                    [$id,$body]=$queue->get();
                    try{Files::write("$directory/response-$id.json",$body)->await();$db->query('insert into responses values (?,?)',[$id,$body])->await();}
                    finally{$queue->taskDone();}
                }
            });
            for($i=0;$i<$count;$i++)$group->spawn(function()use($i,$port,$client,$slots,$queue,$ready,$lock,&$completed):void {
                $ready->wait();
                $slots->withPermit(function()use($i,$port,$client,$queue,$lock,&$completed):void {
                    $response=Async::timeout(2,fn()=>$client->get("http://127.0.0.1:$port/item/$i")->await());
                    $queue->put([$i,$response->body]);
                    $lock->synchronized(function()use(&$completed):void{$completed++;});
                });
            });
        });
        $queue->join();$queue->close();$serving->await();
        $cancelled=Async::spawn(function():void {try{Async::sleep(30);}finally{echo "Cancellation cleanup executed\n";}});
        Async::sleep(0);$cancelled->cancel();try{$cancelled->await();}catch(CancelledException){}
        $cpu=Async::gather([$pool->submit('digest',[250000]),$pool->submit('digest',[250000])]);
        $rows=$db->query('select count(*) as n from responses')->await();
        $saved=Files::read($directory.'/response-0.json')->await();
        echo json_encode(['trace'=>Context::get('trace_id'),'requests'=>$completed,'sqliteRows'=>$rows[0]['n'],'savedResponse'=>json_decode($saved,true),'cpuWorkers'=>$cpu,'backends'=>Runtime::information()],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";
    }finally{
        Async::shield(function()use($server,$pool,$db,$directory):void {
            $server->close();$pool->close();$db->close()->await();
            foreach(Files::entries($directory)->await()as$name)Files::delete($directory.'/'.$name)->await();
            Files::rmdir($directory)->await();
        });
    }
});
