<?php
declare(strict_types=1);
/** Extra pending socket/timer work; never replaces the original HTTP assertion. */
function densityWork(int $seed): array {
    $counts = ['pendingReads'=>0,'ioCompletions'=>0,'timerFirings'=>0,'cancellations'=>0,'closedEndpoints'=>0];
    for ($round=0;$round<4;$round++) {
        $pairs=[];$jobs=[];$ready=new \Asyncara\Concurrency\Event();$entered=0;
        try {
            for ($i=0;$i<16;$i++) {
                $pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP);
                if ($pair===false) throw new RuntimeException('density socketpair');
                $pairs[]=array_map(static fn($s)=>new \Asyncara\IO\TcpStream($s),$pair);
                $read=$pairs[$i][0];
                $jobs[]=\Asyncara\Async::spawn(static function()use($read,$ready,&$entered):string {
                    if(++$entered===16)$ready->set();return $read->read(1);
                });
            }
            $ready->wait();$counts['pendingReads']+=16;
            for($tick=0;$tick<2;$tick++) {
                \Asyncara\Async::sleep(.001);
                foreach($jobs as $job)if($job->isCompleted())throw new RuntimeException('density I/O finished before two ticks');
                $counts['timerFirings']++;
            }
            // Explicit reproducible permutation, independent of global RNG.
            $offset=($seed+$round)%16;
            for($i=0;$i<16;$i++) {
                $index=($i*5+$offset)%16;
                if(($index+$round)%4===0){$jobs[$index]->cancel();$counts['cancellations']++;}
                $pairs[$index][1]->write('x');
            }
            foreach($jobs as $index=>$job) {
                try {if($job->await()!=='x')throw new RuntimeException('density payload');$counts['ioCompletions']++;}
                catch(\Asyncara\Exception\CancelledException $e){if(($index+$round)%4!==0)throw $e;}
            }
        } finally {foreach($pairs as [$a,$b]){$a->close();$b->close();$counts['closedEndpoints']+=2;}}
    }
    if($counts!==['pendingReads'=>64,'ioCompletions'=>48,'timerFirings'=>8,'cancellations'=>16,'closedEndpoints'=>128])throw new RuntimeException('density operation accounting');
    return $counts;
}
