<?php
declare(strict_types=1);
// The parent owns the local server. Leave a paused native transfer for RSHUTDOWN.
$id=asyncara_submit('http',[$argv[1].'/large',null,['streamResponse'=>true],10000]);
$deadline=hrtime(true)+3_000_000_000;
while(asyncara_info()['httpStreamingPaused']===0) {
    asyncara_poll([],[],20);
    if(hrtime(true)>$deadline)throw new RuntimeException('Shutdown fixture never paused');
}
if($argv[2]==='fatal') {
    ini_set('memory_limit','8M');
    $never=str_repeat('x',16*1024*1024);
    throw new RuntimeException('Memory exhaustion did not occur');
}
echo "Paused native transfer left for request shutdown\n";
