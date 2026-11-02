<?php
declare(strict_types=1);
namespace Asyncara\Exception;
/** POSIX errno is preserved separately from the human-readable native message. */
final class FilesystemException extends OperationException
{
    public function __construct(string $message, public readonly int $errno, public readonly bool $committed = false)
    {
        parent::__construct($message, $errno);
    }
    public function isMissing(): bool { return $this->errno === 2 || $this->errno === 20; }
}
