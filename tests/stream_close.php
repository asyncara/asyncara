<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Asyncara\{Async,Future,Runtime};
use Asyncara\Stream\AsyncStream;
function verify(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function queue(AsyncStream $stream):Asyncara\Concurrency\Queue{return(new ReflectionProperty(AsyncStream::class,'queue'))->getValue($stream);}
Async::run(function():void {
    $base=Future::pendingCount();
    // Keep the closed stream and its producer reachable. Correct cleanup must
    // not rely on GC discarding an unconsumed private queue and pending Event.
    $ready=new Future();$stream=AsyncStream::fromProducer(function($emitter)use($ready):void {
        $emitter->emit(str_repeat('x',65536));$emitter->emit(str_repeat('y',65536));$ready->resolve();$emitter->emit('blocked');
    },2);
    $ready->await();$stream->close();$stream->close();
    verify(queue($stream)->statistics()['unfinished']===0&&queue($stream)->count()===0,'Closed buffer ownership');
    verify(Future::pendingCount()===$base,'Closed stream retained Future');
    // A currently yielded item is still owned by its iterator. Close must
    // discard only buffered entries and leave that single acknowledgement.
    $ready=new Future();$stream=AsyncStream::fromProducer(function($emitter)use($ready):void {
        $emitter->emit('first');$emitter->emit('second');$ready->resolve();$emitter->emit('third');
    },2);
    $ready->await();$iterator=$stream->getIterator();$iterator->rewind();verify($iterator->current()==='first','First item');
    $stream->close();verify(queue($stream)->count()===0&&queue($stream)->statistics()['unfinished']===1,'Yielded ownership changed');
    unset($iterator);verify(queue($stream)->statistics()['unfinished']===0,'Yield acknowledgement');
    verify(Future::pendingCount()===$base,'Yield retained Future');
    // Producer failure must propagate, but cannot prevent private queue cleanup.
    $stream=AsyncStream::fromProducer(function($emitter):void{$emitter->emit('leftover');throw new DomainException('producer failure');},2);
    try{$stream->completion()->await();throw new RuntimeException('Expected failure');}catch(DomainException){}
    for($i=0;$i<2;$i++)try{$stream->close();throw new RuntimeException('Expected close failure');}catch(DomainException){}
    verify(queue($stream)->count()===0&&queue($stream)->statistics()['unfinished']===0,'Failure cleanup');
    verify(Future::pendingCount()===$base,'Failure retained Future');
    // Closing before the producer starts does not fabricate a queue item.
    $stream=AsyncStream::fromProducer(fn($emitter)=>$emitter->emit('never'),2);$stream->close();
    verify(Future::pendingCount()===$base&&queue($stream)->count()===0,'Unstarted close');
});
verify(Future::pendingCount()===0,'Final pending Future');
echo "PASS stream close releases private buffers, preserves yielded ownership, joins producer and propagates errors\n";
