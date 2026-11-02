<?php
declare(strict_types=1);
namespace Asyncara\IO;
use Asyncara\{Future,Scheduler};
final class Dns
{
    public static function resolve(string $host): Future { return Scheduler::current()->native('dns', [$host]); }
}
