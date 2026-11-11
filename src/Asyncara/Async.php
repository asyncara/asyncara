<?php
declare(strict_types=1);
namespace Asyncara;
use Asyncara\Exception\TimeoutException;
final class Async
{
    public static function run(#[\SensitiveParameter] callable $body): mixed { return (new Scheduler(Runtime::configuration()['taskLimit']))->run($body); }
    public static function spawn(#[\SensitiveParameter] callable $body, bool $inheritContext = true): Task { return Scheduler::current()->spawn($body, $inheritContext); }
    /** Await an owned operation; cancellation also cancels and joins its unfinished work. */
    public static function await(Future $future): mixed
    {
        try { return $future->await(); }
        finally {
            if (!$future->isCompleted()) {
                $future->cancel();
                self::shield(static function () use ($future): void { try { $future->await(); } catch (\Throwable) {} });
            }
        }
    }
    public static function gather(array $tasks): array
    {
        $results = [];
        foreach ($tasks as $key => $task) { if (!$task instanceof Awaitable) throw new \TypeError('gather expects Awaitables'); $results[$key] = $task->await(); }
        return $results;
    }
    public static function sleep(float $seconds): void
    {
        $scheduler = Scheduler::current();
        $scheduler->suspend(function (\Closure $resume) use ($scheduler, $seconds): \Closure {
            $timer = $scheduler->delay($seconds, $resume, true); return $timer->cancel(...);
        }, $scheduler->currentCancellation());
    }
    public static function cancel(Task|Future $task): void { $task->cancel(); }
    public static function callLater(float $seconds, callable $body): Timer { return Scheduler::current()->delay($seconds, static fn() => self::spawn($body)); }
    public static function repeat(float $seconds, callable $body): Task
    {
        if ($seconds <= 0 || !is_finite($seconds)) throw new \ValueError('Repeat interval must be finite and positive');
        return self::spawn(static function () use ($seconds, $body): void { while (true) { self::sleep($seconds); $body(); } });
    }
    public static function shield(callable $body): mixed
    {
        $task = Scheduler::current()->task ?? throw new \LogicException('Shield requires a task'); $task->shield++;
        try { return $body(); } finally { $task->shield--; }
    }
    public static function timeout(float $seconds, callable $body): mixed
    {
        if ($seconds < 0 || !is_finite($seconds)) throw new \ValueError('Timeout must be finite and nonnegative');
        $task = self::spawn($body); $expired = false;
        $timer = Scheduler::current()->delay($seconds, static function () use ($task, &$expired): void {
            $expired = true; $task->cancellation->cancel(new TimeoutException('Operation deadline exceeded'));
        }, true);
        try {
            $result = $task->await();
            if ($expired) throw new TimeoutException('Operation deadline exceeded');
            return $result;
        } finally {
            $timer->cancel();
            if (!$task->isCompleted()) { $task->cancel(); self::shield(static function () use ($task): void { try { $task->await(); } catch (\Throwable) {} }); }
        }
    }
    public static function taskGroup(callable $body): mixed
    {
        $group = new TaskGroup();
        try { $value = $body($group); }
        catch (\Throwable $e) {
            $group->cancel(); self::shield(static function () use ($group): void { try { $group->finish(); } catch (\Throwable) {} }); throw $e;
        }
        $group->finish(); return $value;
    }
    public static function wait(array $tasks, ?float $timeout = null, bool $all = true): WaitResult
    {
        if ($timeout !== null && ($timeout < 0 || !is_finite($timeout))) throw new \ValueError('Invalid wait timeout');
        $future = new Future(); $unsubscribes = []; $timer = null;
        $check = static function () use ($tasks, $all, $future): void {
            $complete = 0; foreach ($tasks as $task) if ($task->isCompleted()) $complete++;
            if (!$future->isCompleted() && ($all ? $complete === count($tasks) : $complete > 0 || !$tasks)) $future->resolve();
        };
        try {
            foreach ($tasks as $task) {
                if (!$task instanceof Future) throw new \TypeError('wait expects Futures');
                $unsubscribes[] = $task->subscribe($check);
            }
            $check();
            if ($timeout !== null) $timer = Scheduler::current()->delay($timeout, static function () use ($future): void { if (!$future->isCompleted()) $future->resolve(); }, true);
            $future->await(); $done = $pending = [];
            foreach ($tasks as $key => $task) { if ($task->isCompleted()) $done[$key] = $task; else $pending[$key] = $task; }
            return new WaitResult($done, $pending);
        } finally { $timer?->cancel(); foreach ($unsubscribes as $unsubscribe) $unsubscribe(); }
    }
    /** @return \Generator<int|string,Future> */
    public static function asCompleted(array $tasks): \Generator
    {
        $queue = new Concurrency\Queue(max(1, count($tasks))); $remove = [];
        try {
            foreach ($tasks as $key => $task) {
                if (!$task instanceof Future) throw new \TypeError('asCompleted expects Futures');
                $remove[] = $task->subscribe(static fn(Future $done) => $queue->tryPut([$key, $done]));
            }
            for ($i = 0; $i < count($tasks); $i++) { [$key, $task] = $queue->get(); $queue->taskDone(); yield $key => $task; }
        } finally { foreach ($remove as $unsubscribe) $unsubscribe(); $queue->close(); }
    }
    public static function using(callable $acquire, callable $body, callable $release): mixed
    {
        $resource = self::shield(static function () use ($acquire): mixed {
            $value = $acquire(); return $value instanceof Awaitable ? $value->await() : $value;
        });
        try { Scheduler::current()->currentCancellation()?->throwIfRequested(); return $body($resource); }
        finally { self::shield(static function () use ($release, $resource): void { $result = $release($resource); if ($result instanceof Awaitable) $result->await(); }); }
    }
}
