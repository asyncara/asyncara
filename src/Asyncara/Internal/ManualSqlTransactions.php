<?php
declare(strict_types=1);
namespace Asyncara\Internal;
use Asyncara\{Async, Future, Scheduler, Task};

/** @internal Connection-affine manual transactions for socket SQL drivers. */
trait ManualSqlTransactions
{
    private bool $manualTransaction = false;
    private bool $transactionStarting = false;
    private bool $transactionEnding = false;
    private bool $transactionBeginPending = false;
    private ?\Closure $transactionOwnerDetach = null;

    public function beginTransaction(): Future
    {
        $caller = Scheduler::current()->task ?? throw new \LogicException('Transaction requires a task');
        if ($this->transactionOwner === $caller || $this->transactionBeginPending) {
            throw new \LogicException('Nested or overlapping transaction begin is not supported; use savepoints');
        }
        $this->transactionBeginPending = true;
        try {
            $future = Async::spawn(fn() => Async::timeout($this->timeout, function () use ($caller): void {
                $this->gate->acquire();
                if ($this->closed || $caller->isCompleted()) {
                    $this->gate->release();
                    throw new \LogicException('Connection closed or transaction owner completed');
                }
                $this->manualTransaction = true;
                $this->transactionStarting = true;
                $this->transactionOwner = $caller;
                try {
                    // Request-local ownership: an abandoned owner cannot leave the gate held.
                    // Closing a SQL connection abandons its transaction; no remote acknowledgement is claimed.
                    $this->transactionOwnerDetach = $caller->subscribe(fn() => $this->close());
                    $this->transactionCommand('BEGIN');
                    if ($this->closed) throw new \LogicException('Connection closed during transaction begin');
                    $this->transactionStarting = false;
                } catch (\Throwable $error) {
                    $this->close();
                    throw $error;
                }
            }));
        } catch (\Throwable $error) {
            $this->transactionBeginPending = false;
            throw $error;
        }
        // Also runs if the begin Task is cancelled before it ever starts.
        $future->subscribe(function (): void { $this->transactionBeginPending = false; });
        return $future;
    }

    public function commit(): Future { return $this->finishManualTransaction('COMMIT'); }
    public function rollback(): Future { return $this->finishManualTransaction('ROLLBACK'); }

    public function inTransaction(): bool
    {
        return !$this->closed && $this->transactionOwner !== null && !$this->transactionStarting;
    }

    private function assertManualOwner(Task $caller): void
    {
        if (!$this->manualTransaction || $this->transactionOwner !== $caller ||
            $this->transactionStarting || $this->transactionEnding || $this->closed) {
            throw new \LogicException('No active manual transaction owned by this task');
        }
    }

    private function finishManualTransaction(string $sql): Future
    {
        $caller = Scheduler::current()->task ?? throw new \LogicException('Transaction requires a task');
        $this->assertManualOwner($caller);
        return Async::spawn(function () use ($caller, $sql): void {
            // Recheck after scheduling: another finish or close may have won the race.
            $this->assertManualOwner($caller);
            $this->transactionEnding = true;
            try {
                Async::shield(fn() => Async::timeout($this->timeout, fn() => $this->transactionCommand($sql)));
            } catch (\Throwable $error) {
                $this->close();
                throw $error;
            } finally {
                $this->releaseManualTransaction();
            }
        });
    }

    /** Exactly one release, including close/error and owner completion paths. */
    private function releaseManualTransaction(): void
    {
        if (!$this->manualTransaction) return;
        $this->manualTransaction = $this->transactionStarting = $this->transactionEnding = false;
        $this->transactionOwner = null;
        $detach = $this->transactionOwnerDetach; $this->transactionOwnerDetach = null;
        if ($detach) $detach();
        $this->gate->release();
    }
}
