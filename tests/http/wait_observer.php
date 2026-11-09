<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/autoload.php';
use Asyncara\{Async,Future,CancellationSource,Scheduler};
use Asyncara\Exception\CancelledException;
Async::run(function (): void {
    for ($i=0; $i<2000; $i++) {
        $future = new Future(); $source = new CancellationSource();
        $waiter = Async::spawn(function () use ($future,$source): string {
            try { return $future->await($source->token); } catch (CancelledException) { return 'cancelled'; }
        });
        $other = Async::spawn($future->await(...));
        Async::sleep(0); // Both waits register before controlled completion order.
        if ($i%2) { $future->resolve('result'); $source->cancel(); }
        else { $source->cancel(); $future->resolve('result'); }
        if ($waiter->await() !== ($i%2 ? 'result' : 'cancelled') || $other->await() !== 'result') throw new RuntimeException('First-settler/shared wait mismatch');
        $old = $source; $source = new CancellationSource(); $next = new Future();
        $task = Async::spawn(function () use ($next,$source): string {
            $done = new Future(); $done->resolve('immediate');
            if ($done->await() !== 'immediate') throw new RuntimeException('Immediate wait mismatch');
            return $next->await($source->token);
        });
        Async::sleep(0); $old->cancel(); $next->resolve('next');
        if ($task->await() !== 'next') throw new RuntimeException('Prior observer affected next wait');
    }
    // Reuse the same Task's callbacks after rejection and cancelled waits.
    for ($i=0; $i<10000; $i++) {
        $future=new Future(); $source=new CancellationSource();
        Async::spawn(static function () use ($future,$source,$i): void {
            if ($i%2) $source->cancel();
            $future->reject(new DomainException('expected'));
        });
        try { $future->await($source->token); throw new RuntimeException('Missing exception'); }
        catch (CancelledException|DomainException) {}
        try { Async::shield($future->await(...)); } catch (DomainException) {}
    }
});
if (Future::pendingCount() !== 0 || asyncara_info()['pendingOperations'] !== 0) throw new RuntimeException('Wait observer retained pending work');
echo "PASS 2000 shared first-settler races + 10000 reused exceptional waits\n";
