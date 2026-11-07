<?php
declare(strict_types=1);
namespace Asyncara {
    // Diagnostic-only delay before the real native wait, never loaded by gates
    // or FPM acceptance. No replacement clock or native completion path.
    function asyncara_poll(array $read,array $write,int $timeout):array {
        if(($GLOBALS['injectDelay']??false) && $timeout>0 && is_file($GLOBALS['controlGate'])) {
            $GLOBALS['injectDelay']=false;$GLOBALS['delayAtNs']=\hrtime(true);
            \time_nanosleep(0,300000000);
            $GLOBALS['resumeAtNs']=\hrtime(true);
        }
        return \asyncara_poll($read,$write,$timeout);
    }
}
namespace {
 require dirname(__DIR__,2).'/autoload.php';
 use Asyncara\{Async,Runtime};use Asyncara\HTTP\HttpClient;
 $GLOBALS['controlGate']=$argv[2];$GLOBALS['injectDelay']=false;
 $ticks=0;$times=[];
 $responses=Async::run(function()use($argv,&$ticks,&$times):array {
    $client=new HttpClient(4);$jobs=[];
    for($i=0;$i<4;$i++)$jobs[]=$client->get($argv[1]);
    $gate=\Asyncara\IO\TcpStream::connect('127.0.0.1',(int)$argv[4])->await();
    try {if($gate->readExactly(1)!=='G')throw new RuntimeException('peer gate');}finally{$gate->close();}
    $GLOBALS['injectDelay']=($argv[3]??'')==='delay';
    $ticker=Async::spawn(function()use($jobs,&$ticks,&$times):void {
        while(count(array_filter($jobs,fn($j)=>!$j->isCompleted()))) {
            Async::sleep(.005);
            if(count(array_filter($jobs,fn($j)=>!$j->isCompleted()))){$ticks++;$times[]=hrtime(true);}
        }
    });
    $result=Async::gather($jobs);$ticker->await();return $result;
 });
 $intervals=array_map(fn($r)=>$r->json(),$responses);
 $overlap=max(array_column($intervals,'start'))<min(array_column($intervals,'end'));
 $injected=isset($GLOBALS['delayAtNs']);
 echo json_encode(['diagnostic'=>true,'backend'=>Runtime::information()->eventLoopBackend,'ticks'=>$ticks,'originalAcceptanceSatisfied'=>$ticks>=2&&$overlap,'intervals'=>$intervals,'overlap'=>$overlap,'delayAtNs'=>$GLOBALS['delayAtNs']??null,'resumeAtNs'=>$GLOBALS['resumeAtNs']??null,'tickTimesNs'=>$times,'scheduler'=>Runtime::diagnostics(),'native'=>Runtime::information()])."\n";
 if(!$overlap || (!$injected && $ticks<2))exit(1);
}
