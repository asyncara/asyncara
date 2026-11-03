<?php
declare(strict_types=1);
namespace Asyncara;
use Asyncara\Exception\{CancelledException,CapacityException,DeadlockException,OperationException};
final class Scheduler
{
    private static ?self $active = null;
    private \SplQueue $ready;
    private \SplPriorityQueue $heap;
    private array $tasks = [], $failures = [], $timers = [], $readers = [], $writers = [], $native = [], $httpReaders = [], $httpWriters = [];
    private int $next = 0, $nextTimer = 0, $nextWatch = 0;
    public ?Task $task = null;
    private array $metrics = ['turns' => 0, 'taskSteps' => 0, 'maxStepSeconds' => 0.0, 'maxTimerLagSeconds' => 0.0];
    public function __construct(private int $taskLimit = 20000)
    {
        $this->ready = new \SplQueue(); $this->heap = new \SplPriorityQueue();
        $this->heap->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
    }
    public static function tryCurrent(): ?self { return self::$active; }
    public static function current(): self { return self::$active ?? throw new \LogicException('Operation requires Async::run()'); }
    public function currentCancellation(): ?CancellationToken { return $this->task && !$this->task->shield ? $this->task->cancellation->token : null; }
    public static function now(): float { return hrtime(true) / 1e9; }
    public function spawn(callable $body, bool $inherit = true): Task
    {
        if (count($this->tasks) + count($this->failures) >= $this->taskLimit) {
            $this->failures = array_filter($this->failures, static fn(Task $t): bool => !$t->observed);
            if (count($this->tasks) + count($this->failures) >= $this->taskLimit) throw new CapacityException('Task limit reached (includes retained unobserved failures)');
        }
        $task = new Task(++$this->next, $this, $body, $inherit ? ($this->task?->context ?? []) : []);
        $this->tasks[$task->id] = $task; $this->enqueue($task); return $task;
    }
    /** @internal */ public function enqueue(Task $task): void
    {
        if (!$task->queued && !$task->isCompleted()) { $task->queued = true; $task->state = 'ready'; $this->ready->enqueue($task); }
    }
    /** @internal */ public function completed(Task $task): void
    {
        unset($this->tasks[$task->id]);
        $observed = $task->observed; $error = $task->exception(); $task->observed = $observed;
        if ($error && !$observed && !$error instanceof CancelledException) $this->failures[] = $task;
    }
    public function suspend(\Closure $register, ?CancellationToken $token = null): mixed
    {
        $task = $this->task ?? throw new \LogicException('Await requires a scheduled Fiber');
        if (\Fiber::getCurrent() !== $task->fiber) throw new \LogicException('Await requires the current Task Fiber; independent Fibers cannot register its waits');
        $token?->throwIfRequested();
        $settled = false; $suspended = false; $value = null; $error = null;
        $resume = function (mixed $v = null, ?\Throwable $e = null) use (&$settled, &$suspended, &$value, &$error, $task): void {
            if ($settled) return;
            $settled = true; $value = $v; $error = $e;
            if ($suspended) $this->enqueue($task);
        };
        $remove = $register($resume);
        $unsubscribe = $token?->subscribe(static fn(CancelledException $e) => $resume(null, $e));
        try {
            if (!$settled) { $suspended = true; \Fiber::suspend(); }
            if ($error) throw $error;
            return $value;
        } finally { $remove(); if ($unsubscribe) $unsubscribe(); }
    }
    public function delay(float $seconds, \Closure $callback, bool $waitTimer = false): Timer
    {
        if (!is_finite($seconds) || $seconds < 0 || $seconds > 31536000) throw new \ValueError('Delay must be finite and between 0 and one year');
        if (count($this->timers) >= 100000) throw new \OverflowException('Timer limit reached');
        $id = ++$this->nextTimer; $at = self::now() + $seconds;
        $timer = new Timer(function () use ($id): void { unset($this->timers[$id]); });
        $this->timers[$id] = [$at, $callback, $timer, $waitTimer]; $this->heap->insert($id, [-$at, -$id]); return $timer;
    }
    private function timers(): void
    {
        $now = self::now();
        while (!$this->heap->isEmpty()) {
            $top = $this->heap->top(); $id = $top['data'];
            if (!isset($this->timers[$id])) { $this->heap->extract(); continue; }
            [$at, $callback, $timer] = $this->timers[$id];
            if ($at > $now) break;
            $this->heap->extract(); $timer->cancel();
            $this->metrics['maxTimerLagSeconds'] = max($this->metrics['maxTimerLagSeconds'], $now - $at);
            $callback();
        }
        if ($this->heap->count() > 2 * count($this->timers) + 1024) {
            $this->heap = new \SplPriorityQueue(); $this->heap->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
            foreach ($this->timers as $id => [$at]) $this->heap->insert($id, [-$at, -$id]);
        }
    }
    /** @param resource $stream */
    public function readable(mixed $stream): void { $this->io($stream, false); }
    /** @param resource $stream */
    public function writable(mixed $stream): void { $this->io($stream, true); }
    private function io(mixed $stream, bool $write): void
    {
        if (!is_resource($stream) && (!is_int($stream) || $stream < 0)) throw new \ValueError('Stream is closed or descriptor is invalid');
        $this->suspend(function (\Closure $resume) use ($stream, $write): \Closure {
            $id = ++$this->nextWatch;
            if ($write) $this->writers[$id] = [$stream, $resume]; else $this->readers[$id] = [$stream, $resume];
            return function () use ($id, $write): void { if ($write) unset($this->writers[$id]); else unset($this->readers[$id]); };
        }, $this->currentCancellation());
    }
    /** Native connector readiness: 1=read, 2=write, 8=timeout. */
    public function waitDescriptor(int $fd, int $events, ?float $timeout = null): int
    {
        if ($fd < 0 || !($events & 3) && $timeout === null) throw new \ValueError('Invalid descriptor wait');
        return $this->suspend(function (\Closure $resume) use ($fd, $events, $timeout): \Closure {
            $read = $write = null; $timer = null;
            if ($events & 1) { $read = ++$this->nextWatch; $this->readers[$read] = [$fd, static fn() => $resume(1)]; }
            if ($events & 2) { $write = ++$this->nextWatch; $this->writers[$write] = [$fd, static fn() => $resume(2)]; }
            if ($timeout !== null) $timer = $this->delay($timeout, static fn() => $resume(8), true);
            return function () use ($read, $write, $timer): void { unset($this->readers[$read], $this->writers[$write]); $timer?->cancel(); };
        }, $this->currentCancellation());
    }
    /** @internal Wake waiters before closing a stream. */
    public function closed(mixed $stream): void
    {
        foreach ([$this->readers, $this->writers] as $watchers) foreach ($watchers as [$s, $resume]) if ($s === $stream) $resume(null, new OperationException('Stream closed while waiting'));
        foreach ($this->readers as $id => [$s]) if ($s === $stream) unset($this->readers[$id]);
        foreach ($this->writers as $id => [$s]) if ($s === $stream) unset($this->writers[$id]);
    }
    public function native(string $op, array $args): Future
    {
        return $this->nativeOperation($op, $args)[1];
    }
    /** @internal Native operation identity for bounded HTTP streaming. */
    public function nativeOperation(string $op, array $args): array
    {
        // Reserve the PHP-visible completion before submitting native work.
        $future = new Future(); $id = asyncara_submit($op, $args);
        $future->canceller = static fn() => asyncara_cancel($id);
        $this->native[$id] = $future; return [$id, $future];
    }
    /** @internal Register only after draining the native response buffer. */
    public function httpReadable(int $id, Future $completion): void
    {
        if ($completion->isCompleted()) return;
        if (isset($this->httpReaders[$id])) throw new \LogicException('HTTP stream already has a reader');
        $this->suspend(function (\Closure $resume) use ($id): \Closure {
            $this->httpReaders[$id] = $resume;
            return function () use ($id): void { unset($this->httpReaders[$id]); };
        }, $this->currentCancellation());
    }
    /** @internal Register only after a native upload buffer rejected a write. */
    public function httpWritable(int $id, Future $completion): void
    {
        if ($completion->isCompleted()) return;
        if (isset($this->httpWriters[$id])) throw new \LogicException('HTTP stream already has a writer');
        $this->suspend(function (\Closure $resume) use ($id): \Closure {
            $this->httpWriters[$id] = $resume;
            return function () use ($id): void { unset($this->httpWriters[$id]); };
        }, $this->currentCancellation());
    }
    private function poll(): void
    {
        $this->timers();
        $timeout = $this->ready->isEmpty() ? -1 : 0;
        if ($timeout !== 0 && !$this->heap->isEmpty()) $timeout = min(3600000, max(0, (int)ceil((-$this->heap->top()['priority'][0] - self::now()) * 1000)));
        if ($timeout < 0 && !$this->readers && !$this->writers && !$this->native) {
            if ($this->tasks) throw new DeadlockException('Tasks are waiting without any timers, I/O or native work');
            return;
        }
        $read = $write = [];
        foreach ($this->readers as $id => [$stream]) $read[$id] = $stream;
        foreach ($this->writers as $id => [$stream]) $write[$id] = $stream;
        $result = asyncara_poll($read, $write, $timeout);
        foreach ($result['read'] as $id) { $callback = $this->readers[$id][1] ?? null; unset($this->readers[$id]); if ($callback) $callback(); }
        foreach ($result['write'] as $id) { $callback = $this->writers[$id][1] ?? null; unset($this->writers[$id]); if ($callback) $callback(); }
        foreach ($result['results'] as $item) {
            $future = $this->native[$item['id']] ?? null; unset($this->native[$item['id']]);
            if (!$future) continue;
            if ($item['error'] !== null) $future->reject($item['error'] === 'Operation cancelled' ? new CancelledException($item['error']) :
                (($item['errno'] ?? 0) ? new Exception\FilesystemException($item['error'], $item['errno'], $item['committed']) : new OperationException($item['error'])));
            else $future->resolve($item['value']);
            if (isset($this->httpReaders[$item['id']])) ($this->httpReaders[$item['id']])();
            if (isset($this->httpWriters[$item['id']])) ($this->httpWriters[$item['id']])();
        }
        foreach ($result['httpReadable'] as $id) if (isset($this->httpReaders[$id])) ($this->httpReaders[$id])();
        foreach ($result['httpWritable'] as $id) if (isset($this->httpWriters[$id])) ($this->httpWriters[$id])();
    }
    private function turn(?Task $root = null): void
    {
        $this->metrics['turns']++;
        $this->timers();
        $count = min(256, $this->ready->count());
        for ($i = 0; $i < $count; $i++) {
            $task = $this->ready->dequeue(); $this->task = $task; $start = self::now();
            try { $task->step(); } finally { $this->task = null; }
            $duration = self::now() - $start; $this->metrics['taskSteps']++;
            $this->metrics['maxStepSeconds'] = max($this->metrics['maxStepSeconds'], $duration);
            if (Runtime::configuration()['slowTaskSeconds'] > 0 && $duration > Runtime::configuration()['slowTaskSeconds']) trigger_error(sprintf('Asyncara: Task #%d occupied the event-loop thread for %.3f ms (measured, cause unknown)', $task->id, $duration * 1000), E_USER_WARNING);
            // Observe root failure before entering an otherwise unbounded I/O wait.
            if ($root?->isCompleted() && $root->exception()) return;
        }
        // A cancelled task's finally block can create another delayed callback.
        // Draining must discard those too before it computes a blocking wait.
        if ($root === null) foreach ($this->timers as [, , $timer, $waitTimer]) if (!$waitTimer) $timer->cancel();
        if ($this->tasks || $this->native || $this->timers) $this->poll();
    }
    public function run(callable $body): mixed
    {
        if (self::$active) throw new \LogicException('Nested Async::run() is not supported');
        $root = $this->spawn($body); $root->observed = true; self::$active = $this;
        $oldHooks = asyncara_hooks(Runtime::timeHooksEnabled());
        try {
            while ($this->tasks || $this->native || $this->timers) {
                $this->turn($root);
                if ($root->isCompleted() && $root->exception()) $root->result();
            }
            $value = $root->result();
            foreach ($this->failures as $task) if (!$task->observed) throw $task->exception();
            return $value;
        } finally {
            try {
                // Suspension timers may belong to shielded acquisition/cleanup.
                // Cancellation removes ordinary waits; shielded waits must finish.
                foreach ($this->timers as [, , $timer, $waitTimer]) if (!$waitTimer) $timer->cancel();
                foreach ($this->tasks as $task) $task->cancel();
                foreach ($this->native as $future) $future->cancel();
                while ($this->tasks || $this->native) $this->turn();
            } finally { foreach ($this->timers as [, , $timer]) $timer->cancel(); $this->failures = []; Runtime::recordMetrics($this->statistics()); asyncara_hooks($oldHooks); self::$active = null; }
        }
    }
    public function statistics(): array
    {
        return $this->metrics + ['activeTasks' => count($this->tasks), 'pendingFutures' => Future::pendingCount(), 'retainedFailures' => count($this->failures), 'readyTasks' => $this->ready->count(), 'activeTimers' => count($this->timers), 'ioWatchers' => count($this->readers) + count($this->writers), 'pendingNative' => count($this->native), 'memoryBytes' => memory_get_usage(true)];
    }
}
