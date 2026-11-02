<?php
declare(strict_types=1);
namespace Asyncara\MySQL;
use Asyncara\Future;
final class Statement
{
    public function __construct(private Connection $connection,private string $sql){}
    public function execute(array $parameters=[]):Future{return $this->connection->query($this->sql,$parameters);}
}
