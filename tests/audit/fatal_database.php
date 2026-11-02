<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\Async;
Async::run(function () use ($argv): void {
    if ($argv[1] === 'sqlite') {
        $db=Asyncara\SQLite\Connection::open(':memory:')->await();
        $db->query('select zeroblob(12582912) as body')->await();
    } else {
        $db=Asyncara\MySQL\Connection::connect(['socket'=>getenv('ASYNCARA_MY_SOCKET'),'user'=>'root','database'=>'mysql'])->await();
        $db->query("select repeat('x',12582912) as body")->await();
    }
    throw new RuntimeException('Expected PHP allocation to fail');
});
