<?php
declare(strict_types=1);
namespace Asyncara\SQLite;
use Asyncara\{Async,Future,Scheduler,Task};
use Asyncara\Concurrency\Semaphore;
final class Connection
{
    private Semaphore $gate;
    private Semaphore $operations;
    private bool $closed = false;
    private ?Task $transactionOwner = null;
    private function __construct(private int $id) { $this->gate = new Semaphore(1); $this->operations = new Semaphore(1); }
    private function __clone() {}
    public function __serialize(): array { throw new \LogicException('Native SQLite connections cannot be serialized'); }
    public function __unserialize(array $data): void { throw new \LogicException('Native SQLite connections cannot be unserialized'); }
    public static function open(string $path): Future
    {
        if ($path !== ':memory:') $path = \asyncara_file_path($path);
        return Async::spawn(static fn() => Async::shield(static fn() => new self(Scheduler::current()->native('sqlite_open', [$path])->await())));
    }
    public function query(string $sql, array $parameters = []): Future
    {
        $caller = Scheduler::current()->task;
        return Async::spawn(function () use ($caller, $sql, $parameters): array {
            $owns = $this->transactionOwner !== null && $this->transactionOwner === $caller;
            if (!$owns) $this->gate->acquire();
            try {
                return $this->operations->withPermit(function () use ($sql, $parameters): array {
                $this->assertOpen();
                $future = Scheduler::current()->native('sqlite_query', [$this->id, $sql, array_values($parameters)]);
                $remove = Scheduler::current()->currentCancellation()?->subscribe(static fn() => $future->cancel());
                try { $result = Async::shield($future->await(...)); } finally { if ($remove) $remove(); }
                Scheduler::current()->currentCancellation()?->throwIfRequested();
                return $result;
                });
            } finally { if (!$owns) $this->gate->release(); }
        });
    }
    public function execute(string $sql, array $parameters = []): Future { return $this->query($sql, $parameters); }
    public function prepare(string $sql): Statement { return new Statement($this, $sql); }
    public function beginTransaction(): Future
    {
        $caller = Scheduler::current()->task ?? throw new \LogicException('Transaction requires a task');
        if ($this->transactionOwner === $caller) throw new \LogicException('Nested transactions are not supported');
        return Async::spawn(function () use ($caller): void {
            $this->gate->acquire();
            try { $this->assertOpen(); Async::shield(fn() => Scheduler::current()->native('sqlite_query', [$this->id, 'BEGIN', []])->await()); $this->transactionOwner = $caller; }
            catch (\Throwable $e) { $this->gate->release(); throw $e; }
        });
    }
    private function endTransaction(string $sql): Future
    {
        if ($this->transactionOwner !== Scheduler::current()->task) throw new \LogicException('Transaction belongs to another task or is not active');
        return Async::spawn(function () use ($sql): void {
            Async::shield(fn() => $this->operations->withPermit(fn() => Scheduler::current()->native('sqlite_query', [$this->id, $sql, []])->await()));
            $this->transactionOwner = null; $this->gate->release();
        });
    }
    public function commit(): Future { return $this->endTransaction('COMMIT'); }
    public function rollback(): Future { return $this->endTransaction('ROLLBACK'); }
    public function transaction(callable $body): Future
    {
        return Async::spawn(function () use ($body): mixed {
            Async::shield(fn() => $this->beginTransaction()->await());
            try { Scheduler::current()->currentCancellation()?->throwIfRequested(); $value = $body($this); Async::shield(fn() => $this->commit()->await()); return $value; }
            catch (\Throwable $e) {
                if ($this->transactionOwner !== null) Async::shield(fn() => $this->rollback()->await());
                throw $e;
            }
        });
    }
    public function close(): Future
    {
        if ($this->transactionOwner !== null) throw new \LogicException('Finish the transaction before closing');
        return Async::spawn(fn() => $this->gate->withPermit(function (): void {
            if ($this->closed) return;
            Async::shield(fn() => Scheduler::current()->native('sqlite_close', [$this->id])->await()); $this->closed = true;
        }));
    }
    private function assertOpen(): void { if ($this->closed) throw new \LogicException('SQLite connection closed'); }
}
