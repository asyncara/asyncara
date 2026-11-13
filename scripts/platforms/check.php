<?php
declare(strict_types=1);
require __DIR__.'/autoload.php';
if(!extension_loaded('asyncara'))throw new RuntimeException('Asyncara module is not loaded');
Asyncara\Async::run(static function():void{Asyncara\Async::sleep(.001);});
echo json_encode(['status'=>'PASS','php'=>PHP_VERSION,'bits'=>PHP_INT_SIZE*8,'os'=>PHP_OS_FAMILY,'runtime'=>Asyncara\Runtime::information()],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";
