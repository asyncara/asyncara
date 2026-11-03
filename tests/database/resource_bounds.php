<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Future};
use Asyncara\PostgreSQL\Connection as Pg;
use Asyncara\SQLite\Connection as Sqlite;
use Asyncara\Redis\Connection as Redis;
use Asyncara\Exception\OperationException;
function check(bool $ok,string $why): void {if(!$ok)throw new RuntimeException($why);}
function rejected(Future $future,string $message): void {
    try {$future->await();}catch(Throwable $e){check(str_contains($e->getMessage(),$message),'Unexpected error: '.$e->getMessage());return;}
    throw new RuntimeException('Expected capacity rejection: '.$message);
}
Async::run(function (): void {
    $sqlite=Sqlite::open(':memory:')->await();
    try {
        rejected($sqlite->query('SELECT length(zeroblob(20000000))'),'too big');
        check($sqlite->query('SELECT 7 AS n')->await()[0]['n']===7,'SQLite unusable after value rejection');
    }finally{$sqlite->close()->await();}
    $pg=['host'=>getenv('ASYNCARA_PG_SOCKET'),'port'=>'55499','dbname'=>'postgres','sslmode'=>'disable'];
    $listener=Pg::connect($pg,notificationLimit:2)->await();$sender=Pg::connect($pg)->await();
    try {
        $listener->query('LISTEN resource_bound')->await();
        for($i=0;$i<3;$i++)$sender->query('SELECT pg_notify($1,$2)',['resource_bound',(string)$i])->await();
        rejected($listener->query('SELECT 1'),'notification');
        rejected($listener->query('SELECT 1'),'closed');
    }finally{$listener->close();$sender->close();}
    $listener=Pg::connect($pg)->await();$sender=Pg::connect($pg)->await();
    try {
        $listener->query('LISTEN resource_bound')->await();
        $sender->query("SELECT pg_notify('resource_bound', n::text) FROM generate_series(1,1100) n")->await();
        rejected($listener->query('SELECT 1'),'notification');
    }finally{$listener->close();$sender->close();}
    $redis=Redis::connect('127.0.0.1',(int)getenv('ASYNCARA_REDIS_PORT'))->await();
    try {
        $redis->command(['SET','asyncara:bounds',str_repeat('x',9*1024*1024)])->await();
        rejected($redis->pipeline([['GET','asyncara:bounds'],['GET','asyncara:bounds']]),'byte limit');
    }finally{$redis->close();}
    $redis=Redis::connect('127.0.0.1',(int)getenv('ASYNCARA_REDIS_PORT'))->await();
    try{$redis->command(['DEL','asyncara:bounds'])->await();}finally{$redis->close();}
});
echo "SQLite foreign value, PostgreSQL notifications, Redis aggregate limits: PASS\n";
