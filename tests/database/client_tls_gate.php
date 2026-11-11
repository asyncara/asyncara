<?php
$before=count(glob('/proc/self/fd/*'));
foreach([
    [['host'=>'127.0.0.1','certFile'=>'/missing/cert','keyFile'=>'/missing/key'],'callback/status'],
    [['host'=>'127.0.0.1','tls'=>false,'certFile'=>'/missing/cert','keyFile'=>'/missing/key'],'verified TCP'],
    [['socket'=>'/missing/socket','certFile'=>'/missing/cert','keyFile'=>'/missing/key'],'verified TCP'],
    [['host'=>'127.0.0.1','keyPassword'=>'synthetic'],'requires a client'],
]as[$options,$reason]){
 for($i=0;$i<100;$i++){
  $caught=false;try{asyncara_mysql_open($options);}catch(ValueError $e){$caught=str_contains($e->getMessage(),$reason);}
  if(!$caught)throw new RuntimeException('Unsupported connector gate bypassed');
 }
}
gc_collect_cycles();
if(count(glob('/proc/self/fd/*'))!==$before)throw new RuntimeException('Descriptor leaked on failed native registration');
echo "PASS 400 explicit client TLS rejection/cleanup controls on stock Connector/C\n";
