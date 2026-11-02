<?php
declare(strict_types=1);
// Intentionally leave native operations undrained: request shutdown owns cleanup.
for($i=0;$i<100;$i++)asyncara_submit('read',[__FILE__]);
for($i=0;$i<20;$i++)asyncara_submit('sqlite_open',[':memory:']);
for($i=0;$i<10;$i++)asyncara_submit('dns',['localhost']);
for($i=0;$i<20;$i++)asyncara_submit('file_open',[__FILE__,'r']);
