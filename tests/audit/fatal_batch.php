<?php
declare(strict_types=1);
// The parent supplies an isolated sparse file. Four native results must be
// ready together: one call must not copy past Zend's pending soft OOM.
ini_set('memory_limit', '8M');
for ($i = 0; $i < 4; $i++) asyncara_submit('read', [$argv[1]]);
usleep(250000);
while (true) asyncara_poll([], [], 1000);
