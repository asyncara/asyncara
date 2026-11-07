<?php
for($i=0;$i<100;$i++){$f=new Fiber(static function():int{Fiber::suspend();return 42;});$f->start();$f->resume();if($f->getReturn()!==42)throw new RuntimeException('fiber');unset($f);}echo "PASS plain Fibers without cycles\n";
