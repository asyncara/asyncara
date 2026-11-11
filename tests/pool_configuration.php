<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Asyncara\{Async,Future};
use Asyncara\Pool\ConnectionPool;
$created=0;$closed=0;
$pool=new ConnectionPool(function()use(&$created):object{$created++;return new stdClass();},function($connection)use(&$closed):void{$closed++;},size:2);
if($created||$pool->statistics()['availableSlots']!==2)throw new RuntimeException('Configuration eagerly created resource');
Async::run(function()use($pool):void{
    $release=new Future();$started=new Future();
    $owner=Async::spawn(fn()=>$pool->withConnection(function($db)use($release,$started):object{$started->resolve();$release->await();return$db;}));
    $started->await();
    $other=$pool->withConnection(static fn($db):object=>$db);
    $release->resolve();if($owner->await()===$other)throw new RuntimeException('Concurrent leases share resource');
    if($pool->statistics()['borrowers']!==0)throw new RuntimeException('Lease retained');
    $pool->close();$pool->close();
});
if($created!==2||$closed!==2||Future::pendingCount())throw new RuntimeException('Resource lifetime mismatch');
echo "PASS pool configuration outside runtime; lazy factories, independent leases, exact close\n";
