<?php
declare(strict_types=1);
namespace Asyncara;
final class TaskGroup
{
    use \Asyncara\Internal\NonCopyable;

    private array $tasks = [];
    private ?\Throwable $failure = null;
    private bool $closed = false;
    public function spawn(callable $body): Task
    {
        if ($this->closed) throw new \LogicException('Task group is closed');
        $task = Async::spawn($body); $this->tasks[] = $task; $task->observed = true;
        $task->subscribe(function (Task $done): void {
            $error = $done->exception();
            if ($error && !$this->failure) { $this->failure = $error; $this->cancel(); }
        });
        if ($this->failure) $task->cancel();
        return $task;
    }
    public function cancel(): void { foreach ($this->tasks as $task) $task->cancel(); }
    /** @internal */
    public function finish(): void
    {
        $this->closed = true;
        try { foreach ($this->tasks as $task) $task->await(); }
        catch (\Throwable $e) {
            $this->failure ??= $e; $this->cancel();
            Async::shield(function (): void { foreach ($this->tasks as $task) { try { $task->await(); } catch (\Throwable) {} } });
        }
        if ($this->failure) throw $this->failure;
    }
}
