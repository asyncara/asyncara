<?php
declare(strict_types=1);
namespace Asyncara\Pool;
use Asyncara\{Async,Future,Scheduler,Task};
use Asyncara\Concurrency\Queue;
use Asyncara\Exception\{WorkerException,CapacityException};
use Asyncara\Internal\IpcFrame;
final class ProcessPool
{
    private array $workers = [], $jobs = [];
    private Queue $available;
    private int $next = 0;
    private bool $closed = false;
    private string $bootstrap;
    private int $pendingBytes = 0;
    private int $restarts = 0;
    public function __construct(int $workers, string $bootstrap, private int $queueLimit = 256, private int $queueBytes = 67108864, private int $workerMemoryBytes = 268435456)
    {
        if ($workers < 1 || $workers > 64 || $queueLimit < 1 || $queueLimit > 100000 || $queueBytes < 1 || $queueBytes > 1073741824 || $workerMemoryBytes < 8388608) throw new \ValueError('Invalid pool limits');
        $this->bootstrap = realpath($bootstrap) ?: throw new \ValueError('Worker bootstrap does not exist');
        $this->available = new Queue($workers);
        try { for ($i = 0; $i < $workers; $i++) { $this->workers[$i] = $this->start(); $this->available->put($i); } }
        catch (\Throwable $e) { foreach ($this->workers as $worker) $this->stop($worker); throw $e; }
    }
    private function start(): array
    {
        $process = proc_open([PHP_BINARY, '-n', '-d', 'memory_limit='.$this->workerMemoryBytes, dirname(__DIR__, 3) . '/bin/worker.php', $this->bootstrap], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', 'php://stderr', 'a']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new WorkerException('Unable to start worker');
        stream_set_blocking($pipes[0], false); stream_set_blocking($pipes[1], false);
        return ['process' => $process, 'in' => $pipes[0], 'out' => $pipes[1], 'ready' => false];
    }
    private function stop(array $worker): void
    {
        foreach (['in', 'out'] as $key) if (is_resource($worker[$key])) { Scheduler::tryCurrent()?->closed($worker[$key]); fclose($worker[$key]); }
        if (is_resource($worker['process'])) { $status = proc_get_status($worker['process']); if ($status['running']) proc_terminate($worker['process'], 9); proc_close($worker['process']); }
    }
    private function readExact(mixed $stream, int $bytes): string
    {
        $result = '';
        while (strlen($result) < $bytes) {
            $data = fread($stream, min(65536, $bytes - strlen($result)));
            if ($data === false || ($data === '' && feof($stream))) throw new WorkerException('Worker exited or closed IPC');
            $result .= $data;
            if (strlen($result) < $bytes) Scheduler::current()->readable($stream);
        }
        return $result;
    }
    private function readFrame(mixed $stream): array
    {
        $size = IpcFrame::length($this->readExact($stream, 4));
        return IpcFrame::decode($this->readExact($stream, $size));
    }
    public function submit(string|array $job, array $arguments = [], float $timeout = 30): Future
    {
        if ($this->closed) throw new \LogicException('Pool closed');
        if (count($this->jobs) >= $this->queueLimit) throw new CapacityException('Process queue limit reached');
        if ($timeout <= 0 || !is_finite($timeout)) throw new \ValueError('Timeout must be positive');
        if (is_array($job)) { if (count($job) !== 2 || !is_string($job[0]) || !is_string($job[1])) throw new \ValueError('Use a registered callable descriptor'); $job = implode('::', $job); }
        $id = ++$this->next;
        $request = ['id' => $id, 'job' => $job, 'arguments' => $arguments];
        $json = IpcFrame::encode($request);
        $charge = strlen($json) + 4;
        if ($charge > $this->queueBytes - $this->pendingBytes) throw new CapacityException('Process pending byte limit reached');
        $task = Async::spawn(fn() => Async::timeout($timeout, function () use ($id, $json): mixed {
            $index = $this->available->get(); $healthy = false;
            try {
                $worker = &$this->workers[$index];
                if (!$worker['ready']) { $ready = $this->readFrame($worker['out']); if (($ready['ready'] ?? false) !== true) throw new WorkerException('Worker initialization failed'); $worker['ready'] = true; }
                $frame = pack('N', strlen($json)) . $json; $offset = 0;
                while ($offset < strlen($frame)) {
                    $n = @fwrite($worker['in'], substr($frame, $offset, 65536));
                    if ($n === false || ($n === 0 && feof($worker['in']))) throw new WorkerException('Worker IPC write failed');
                    $offset += $n; if ($offset < strlen($frame)) Scheduler::current()->writable($worker['in']);
                }
                $result = $this->readFrame($worker['out']);
                if (($result['id'] ?? null) !== $id) throw new WorkerException('Unexpected worker result ID');
                $healthy = true;
                if (isset($result['error'])) throw new WorkerException($result['error']);
                return $result['value'] ?? null;
            } finally {
                if (!$healthy) { $this->stop($this->workers[$index]); if (!$this->closed) { $this->workers[$index] = $this->start(); $this->restarts++; } }
                if (!$this->closed) $this->available->tryPut($index);
                $this->available->taskDone();
            }
        }));
        $this->jobs[$id] = $task;
        $this->pendingBytes += $charge;
        $task->subscribe(function () use ($id, $charge): void { unset($this->jobs[$id]); $this->pendingBytes -= $charge; }); return $task;
    }
    public function statistics(): array
    {
        return ['workers'=>count($this->workers), 'restarts'=>$this->restarts, 'pendingJobs'=>count($this->jobs), 'pendingBytes'=>$this->pendingBytes, 'queueLimit'=>$this->queueLimit, 'queueBytes'=>$this->queueBytes, 'workerMemoryBytes'=>$this->workerMemoryBytes, 'closed'=>$this->closed];
    }
    private function retire(array $worker): void
    {
        if (!is_resource($worker['out'])) { $this->stop($worker); return; }
        if (is_resource($worker['in'])) { Scheduler::current()->closed($worker['in']); fclose($worker['in']); }
        try {
            Async::timeout(1.0, static function () use ($worker): void {
                while (!feof($worker['out'])) { Scheduler::current()->readable($worker['out']); fread($worker['out'], 4096); }
            });
        } catch (\Asyncara\Exception\TimeoutException) {
            // Unresponsive trusted worker is terminated by stop() below.
        } finally { $this->stop($worker); }
    }
    public function close(): void
    {
        if ($this->closed) return; $this->closed = true;
        foreach ($this->jobs as $task) $task->cancel();
        Async::shield(function (): void { foreach ($this->jobs as $task) { try { $task->await(); } catch (\Throwable) {} } });
        $this->available->close(); Async::shield(function (): void { foreach ($this->workers as $worker) $this->retire($worker); }); $this->workers = [];
    }
    public function __destruct() { foreach ($this->workers as $worker) $this->stop($worker); }
}
