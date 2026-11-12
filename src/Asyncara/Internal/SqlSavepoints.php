<?php
declare(strict_types=1);
namespace Asyncara\Internal;
use Asyncara\{Future, Scheduler};
/** @internal Savepoints keep the current transaction's connection and task affinity. */
trait SqlSavepoints
{
    public function savepoint(string $name): Future { return $this->submitSavepoint('SAVEPOINT ', $name); }
    public function rollbackTo(string $name): Future { return $this->submitSavepoint('ROLLBACK TO SAVEPOINT ', $name); }
    public function releaseSavepoint(string $name): Future { return $this->submitSavepoint('RELEASE SAVEPOINT ', $name); }
    private function submitSavepoint(string $command, string $name): Future
    {
        if (!preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,62}\z/D', $name)) {
            throw new \ValueError('Savepoint name must be an ASCII identifier of 1..63 bytes');
        }
        if ($this->transactionOwner === null || $this->transactionOwner !== Scheduler::current()->task) {
            throw new \LogicException('Savepoint requires the current task to own the transaction');
        }
        return $this->query($command.self::SAVEPOINT_QUOTE.$name.self::SAVEPOINT_QUOTE);
    }
}
