<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Future,Runtime,Scheduler};
use Asyncara\Concurrency\Event;
use Asyncara\IO\{TcpStream,TcpServer,Files};
use Asyncara\Exception\{CancelledException,OperationException,TimeoutException};
$iterations=(int)($argv[1]??10000);$seed=(int)($argv[2]??17);$negative=in_array('--negative-early-io',$argv,true);
mt_srand($seed);$start=hrtime(true);$trace=[];$counts=[];$samples=[];
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function pairStreams():array{$p=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP);check($p!==false,'socketpair');return [new TcpStream($p[0]),new TcpStream($p[1])];}
function sample(string $case,int $n):array{$i=Runtime::information();$s=Runtime::diagnostics();return ['case'=>$case,'operations'=>$n,'nativeBytes'=>$i->nativeMemoryBytes,'nativePending'=>$i->pendingOperations,'workers'=>$i->nativeWorkers,'fds'=>count(scandir('/proc/self/fd'))-2,'phpBytes'=>memory_get_usage(),'scheduler'=>$s];}
try{
 // Real TCP with peer responses withheld by an explicit two-tick barrier.
 $server=new TcpServer();$port=(int)substr(strrchr($server->address(),':'),1);
 Async::run(function()use($server,$port,&$trace,$negative):void{
  $release=new Event();$arrived=new Event();$entered=0;$jobs=[];$peers=[];
  for($i=0;$i<4;$i++){
   $peers[]=Async::spawn(function()use($server,$release,&$trace,$i):void{$p=$server->accept();try{$release->wait();$trace[]=['peer-write',$i,hrtime(true)];$p->write('reply');}finally{$p->close();}});
   $jobs[]=Async::spawn(function()use($port,$arrived,&$entered,&$trace,$i):string{$p=TcpStream::connect('127.0.0.1',$port)->await();try{if(++$entered===4)$arrived->set();$trace[]=['read-pending',$i,hrtime(true)];return $p->readExactly(5);}finally{$p->close();}});
  }
  $arrived->wait();check(Scheduler::current()->statistics()['ioWatchers']>=4,'readers not suspended');
  if($negative){$release->set();Async::gather($jobs);} // deterministic invalid overlap control
  $ticks=0;
  for($t=0;$t<2;$t++){Async::sleep(.005);$pending=count(array_filter($jobs,fn($j)=>!$j->isCompleted()));if($pending===4){$ticks++;$trace[]=['tick',$ticks,hrtime(true)];}}
  check($ticks>=2,'I/O did not overlap or blocked loop: ticks: '.$ticks);
  check(count(array_filter($jobs,fn($j)=>!$j->isCompleted()))===4,'I/O finished before required ticks');
  $release->set();check(Async::gather($jobs)===array_fill(0,4,'reply'),'TCP results');Async::gather($peers);
 });$server->close();unset($server);$counts['tcpPendingTwoTicks']=4;
 // Each batch has real blocked readers and two timer rounds. This counts each
 // wait/resume explicitly, not just elapsed time. Multiple ready Fibers contend.
 $total=0;
 while($total<$iterations){$n=min(64,$iterations-$total);
  Async::run(function()use($n):void{$pairs=[];$jobs=[];$ready=new Event();$entered=0;
   try{
    for($i=0;$i<$n;$i++){$pairs[]=pairStreams();$p=$pairs[$i][0];$jobs[]=Async::spawn(function()use($p,&$entered,$n,$ready):string{if(++$entered===$n)$ready->set();return $p->read(1);});}
    $ready->wait();$timers=[];
    for($i=0;$i<$n;$i++)$timers[]=Async::spawn(function()use($jobs):int{$ticks=0;for($t=0;$t<2;$t++){Async::sleep(.001);check(!array_filter($jobs,fn($j)=>$j->isCompleted()),'I/O completed before timer');$ticks++;}return $ticks;});
    check(Async::gather($timers)===array_fill(0,$n,2),'two ticks');
    $order=range(0,$n-1);shuffle($order);foreach($order as$i)$pairs[$i][1]->write('x');
    check(Async::gather($jobs)===array_fill(0,$n,'x'),'simultaneous readers');
   }finally{foreach($pairs as[$a,$b]){$a->close();$b->close();}}
  });$total+=$n;
 }
 $counts['pendingReadTransitions']=$total;$counts['timerTransitions']=$total*2;
 // Same production stream and scheduler: cancellation on either side of a
 // kernel readiness observation, timeout, close/reopen, then ordinary success.
 Async::run(function()use($iterations,&$counts,&$samples):void{
  foreach(['cancel-before','cancel-after-ready','timeout','close-reopen','ready-success']as$case){
   for($i=0;$i<$iterations;$i++){
    [$a,$b]=pairStreams();$entered=new Event();
    $job=Async::spawn(function()use($a,$entered,$case):string{$entered->set();return $case==='timeout'?Async::timeout(0,fn()=>$a->read(1)):$a->read(1);});
    try{
     $entered->wait();
     if($case==='cancel-before'){$job->cancel();$b->write('x');}
     elseif($case==='cancel-after-ready'){$b->write('x');check(asyncara_poll([99=>$a->resource()],[],0)['read']===[99],'kernel readiness missing');$job->cancel();}
     elseif($case==='close-reopen'){$a->close();[$fresh,$peer]=pairStreams();try{$peer->write('y');check($fresh->read(1)==='y','reopened endpoint');}finally{$fresh->close();$peer->close();}}
     elseif($case==='ready-success')$b->write('x');
     try{$value=$job->await();check($case==='ready-success'&&$value==='x','unexpected success '.$case);$job->cancel();check($job->result()==='x','late cancellation changed completion');}
     catch(CancelledException|OperationException $e){check($case!=='ready-success','unexpected failure '.$case);if($case==='timeout')check($e instanceof TimeoutException,'timeout classification');}
    }finally{$a->close();$b->close();}
    if(($i+1)%1000===0){unset($job,$entered,$a,$b);gc_collect_cycles();$s=sample($case,$i+1);check($s['scheduler']['activeTasks']===1&&$s['scheduler']['ioWatchers']===0&&$s['scheduler']['activeTimers']===0&&$s['nativePending']===0,'unclean boundary');$samples[]=$s;}
   }$counts[$case]=$iterations;
  }
 });
 gc_collect_cycles();$final=sample('final',$iterations);check($final['scheduler']['activeTasks']===0&&$final['scheduler']['pendingFutures']===0&&$final['scheduler']['ioWatchers']===0&&$final['nativePending']===0&&$final['nativeBytes']===0,'final resources');
 echo json_encode(['status'=>'PASS','seed'=>$seed,'php'=>PHP_VERSION,'backend'=>Runtime::information()->eventLoopBackend,'wallSeconds'=>(hrtime(true)-$start)/1e9,'counts'=>$counts,'trace'=>$trace,'samples'=>$samples,'final'=>$final],JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){fwrite(STDERR,json_encode(['status'=>'FAIL','seed'=>$seed,'error'=>(string)$e,'counts'=>$counts,'trace'=>$trace,'samples'=>$samples])."\n");exit(1);}
