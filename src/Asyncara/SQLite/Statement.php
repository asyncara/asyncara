<?php
declare(strict_types=1);
namespace Asyncara\SQLite;
use Asyncara\Future;
/** Each execution prepares and binds on the connection's native worker queue. */
final class Statement
{
    use \Asyncara\Internal\NotSerializable;

    public function __construct(private Connection $connection, private string $sql) {}
    public function execute(array $parameters = []): Future { return $this->connection->query($this->sql, $parameters); }
}
