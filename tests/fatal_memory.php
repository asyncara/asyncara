<?php
declare(strict_types=1);
// The parent expects PHP's memory-limit fatal error. Native result ownership
// must survive Zend's bailout so RSHUTDOWN can release every allocation.
$id = asyncara_submit('read', [$argv[1]]);
while (true) {
    $batch = asyncara_poll([], [], 1000);
    if ($batch['results']) throw new RuntimeException('Expected result conversion to exceed PHP memory');
}
