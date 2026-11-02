<?php
declare(strict_types=1);
$body=file_get_contents(getenv('ASYNCARA_BENCH_URL'));
if($body!=='hello asyncara'){http_response_code(500);exit('upstream failure');}
echo $body;
