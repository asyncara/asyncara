<?php
declare(strict_types=1);
namespace Asyncara\PostgreSQL;
use Asyncara\Future;
/** Parameterized extended protocol; optional bounded server cache belongs to Connection. */
final class Statement
{
    use \Asyncara\Internal\NotSerializable;

    public function __construct(private Connection $connection,private string $sql){}
    public function execute(array $parameters=[]):Future{return $this->connection->executePrepared($this->sql,$parameters);}
}
