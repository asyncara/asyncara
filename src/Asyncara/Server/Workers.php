<?php
declare(strict_types=1);
namespace Asyncara\Server;

/** Prefork supervisor. No Zend state crosses threads; each process owns an engine. */
final class Workers
{
    use \Asyncara\Internal\NonCopyable;
    private array $children = [];
    private bool $stopping = false;
    public function stop(): void
    {
        if ($this->stopping) return;
        $this->stopping = true;
        foreach ($this->children as $pid => $_) posix_kill($pid, SIGTERM);
    }
    public function run(int $count, int $shutdownMs, callable $child, ?callable $ready): void
    {
        if (PHP_SAPI !== 'cli' || !function_exists('pcntl_fork') || !function_exists('posix_kill')) throw new \RuntimeException('Multiple server workers require CLI, pcntl and posix');
        if (!\asyncara_server_can_fork() || count(glob('/proc/self/task/*')) !== 1) throw new \LogicException('Start workers before initializing any native runtime or thread; open resources inside each worker');
        $oldAsync = pcntl_async_signals(true); $handlers = [];
        foreach ([SIGINT, SIGTERM] as $signal) { $handlers[$signal] = pcntl_signal_get_handler($signal); pcntl_signal($signal, fn() => $this->stop()); }
        $error = null;
        try {
            for ($i = 0; $i < $count; $i++) {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                if (!$pair) throw new \RuntimeException('Worker readiness channel failed');
                $pid = pcntl_fork();
                if ($pid === -1) { foreach ($pair as $stream) fclose($stream); throw new \RuntimeException('Worker fork failed'); }
                if ($pid === 0) {
                    fclose($pair[0]); foreach ($this->children as $stream) if (is_resource($stream)) fclose($stream);
                    $this->children = [];
                    // Child installs its own App handlers, never the parent's PID list.
                    foreach ($handlers as $signal => $handler) pcntl_signal($signal, $handler);
                    pcntl_async_signals($oldAsync);
                    try {
                        $child(static function () use ($pair): void { fwrite($pair[1], "R"); fclose($pair[1]); });
                        exit(0);
                    } catch (\Throwable $e) {
                        if (is_resource($pair[1])) fclose($pair[1]);
                        fwrite(STDERR, "Asyncara server worker failed: " . $e->getMessage() . "\n"); exit(1);
                    }
                }
                fclose($pair[1]); stream_set_blocking($pair[0], false); $this->children[$pid] = $pair[0];
            }
            $deadline = hrtime(true) + 10_000_000_000;
            $waiting = $this->children;
            while ($waiting && !$this->stopping) {
                if (hrtime(true) >= $deadline) throw new \RuntimeException('Workers did not become ready');
                $read = array_values($waiting); $write = $except = [];
                if (@stream_select($read, $write, $except, 0, 100000) === false) continue;
                foreach ($read as $stream) {
                    if (fread($stream, 1) !== 'R') throw new \RuntimeException('Worker exited before listening');
                    $pid = array_search($stream, $waiting, true); unset($waiting[$pid]); fclose($stream); $this->children[$pid] = null;
                }
            }
            if (!$this->stopping && $ready !== null) $ready();
            $stopAt = null;
            while ($this->children) {
                foreach ($this->children as $pid => $stream) {
                    if (pcntl_waitpid($pid, $status, WNOHANG) === $pid) {
                        unset($this->children[$pid]);
                        if (is_resource($stream)) fclose($stream);
                        if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) $error = new \RuntimeException('HTTP worker failed; PID ' . $pid);
                        $this->stop(); // no silent partial pool or automatic replay
                    }
                }
                if ($this->stopping) {
                    $stopAt ??= hrtime(true) + ($shutdownMs + 5000) * 1000000;
                    if (hrtime(true) >= $stopAt) { foreach ($this->children as $pid => $_) posix_kill($pid, SIGKILL); $error ??= new \RuntimeException('Worker graceful shutdown deadline exceeded'); }
                }
                if ($this->children) usleep(10000); // supervisor only, not an event-loop thread
            }
            if ($error) throw $error;
        } finally {
            $this->stop();
            // Startup failures still own all child PIDs. Reap only these children.
            $until = hrtime(true) + ($shutdownMs + 5000) * 1000000;
            while ($this->children) {
                foreach ($this->children as $pid => $stream) {
                    if (hrtime(true) >= $until) posix_kill($pid, SIGKILL);
                    if (pcntl_waitpid($pid, $status, WNOHANG) === $pid) { if (is_resource($stream)) fclose($stream); unset($this->children[$pid]); }
                }
                if ($this->children) usleep(10000);
            }
            foreach ($handlers as $signal => $handler) pcntl_signal($signal, $handler);
            pcntl_async_signals($oldAsync);
        }
    }
}
