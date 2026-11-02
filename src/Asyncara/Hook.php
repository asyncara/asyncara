<?php
declare(strict_types=1);
namespace Asyncara;
final class Hook
{
    public const TIME = 1, HTTP = 2, STREAM = 4, FILESYSTEM = 8, DATABASE = 16;
    public const ALL = self::TIME;
    public const ALLOW_BLOCKING = 'allow', WARN = 'warn', STRICT = 'strict';
}
