<?php
declare(strict_types=1);
namespace Asyncara\Database;
use Asyncara\Future;
use Asyncara\SQLite\Connection;
final class Database
{
    public static function connectAsync(array $config): Future
    {
        return match ($config['driver'] ?? '') {
            'sqlite' => Connection::open($config['path'] ?? ':memory:'),
            'postgresql' => \Asyncara\PostgreSQL\Connection::connect(array_diff_key($config, ['driver'=>true])),
            'mysql', 'mariadb' => \Asyncara\MySQL\Connection::connect(array_diff_key($config, ['driver'=>true])),
            default => throw new \LogicException('Requested database driver is not implemented'),
        };
    }
}
