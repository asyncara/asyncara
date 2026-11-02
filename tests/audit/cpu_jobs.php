<?php
declare(strict_types=1);
return [
 'ready'=>fn()=>getmypid(),
 'cpu'=>function():array{
  $usage=static function():float{$r=getrusage();return $r['ru_utime.tv_sec']+$r['ru_utime.tv_usec']/1e6+$r['ru_stime.tv_sec']+$r['ru_stime.tv_usec']/1e6;};
  $start=hrtime(true);$cpu=$usage();$value='seed';
  do{for($i=0;$i<4096;$i++)$value=hash('sha256',$value,true);$used=$usage()-$cpu;}while($used<.5);
  return ['pid'=>getmypid(),'start'=>$start,'end'=>hrtime(true),'cpuSeconds'=>$used];
 },
];
