<?php
declare(strict_types=1);
$id=asyncara_submit('file_stage',[$argv[1].'/result']);do{$r=asyncara_poll([],[],100);}while(!$r['results']);
$file=$r['results'][0]['value'];asyncara_submit('file_write',[$file,'pending data']);
if(($argv[2]??'normal')==='fatal'){ini_set('memory_limit','8M');$x=str_repeat('x',16*1024*1024);}
echo "staged file left for shutdown\n";
