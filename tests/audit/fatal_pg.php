<?php
declare(strict_types=1);
require (getenv('ASYNCARA_AUDIT_SOURCE') ?: dirname(__DIR__,2)).'/autoload.php';
use Asyncara\{Async};
use Asyncara\PostgreSQL\Connection;
Async::run(function (): void {
    $db=Connection::connect(['host'=>getenv('ASYNCARA_PG_SOCKET'),'port'=>'55499','dbname'=>'postgres','sslmode'=>'disable'])->await();
    $db->query("select repeat('x',12582912) as body")->await();
    throw new RuntimeException('Expected PHP allocation to fail');
});
