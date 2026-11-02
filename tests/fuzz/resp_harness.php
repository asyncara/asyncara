<?php
declare(strict_types=1);
use Asyncara\IO\TcpStream;
use Asyncara\Redis\Connection;
/** Exercise the production parser without extracting/reimplementing it. */
function parseResp(string $wire, int $prefix = 0): mixed
{
    $stream=fopen('php://memory','w+b');
    fwrite($stream,substr($wire,$prefix));rewind($stream);
    $class=new ReflectionClass(Connection::class);
    $connection=$class->newInstanceWithoutConstructor();
    $class->getConstructor()->invoke($connection,new TcpStream($stream),1.0);
    $class->getProperty('buffer')->setValue($connection,substr($wire,0,$prefix));
    try{return $class->getMethod('readReply')->invoke($connection);}
    finally{$connection->close();}
}
