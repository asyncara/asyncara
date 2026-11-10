<?php
declare(strict_types=1);
namespace Asyncara\Server;

/** Input failed before a complete message; App closes the connection. */
final class RequestBodyException extends \RuntimeException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct('Request body could not be consumed');
    }
}
