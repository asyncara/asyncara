<?php
$trace=($argv[1]??'')==='trace';if($trace)asyncara_trace_configure(4096,127);
for($n=0;$n<100;$n++){$id=asyncara_submit('read',[__FILE__]);do{$result=asyncara_poll([],[],1000)['results'];}while(!$result);if($result[0]['id']!==$id||$result[0]['error']!==null)throw new RuntimeException('result');unset($result);}
if(asyncara_info()['nativeMemoryBytes']!==0||asyncara_info()['pendingOperations']!==0)throw new RuntimeException('ownership');
echo "PASS raw native worker lifecycle\n";
