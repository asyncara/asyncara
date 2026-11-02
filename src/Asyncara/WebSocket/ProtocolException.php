<?php
declare(strict_types=1);
namespace Asyncara\WebSocket;
final class ProtocolException extends \Asyncara\Exception\OperationException
{
    public function __construct(string $message, public readonly int $closeCode = 1002)
    {
        parent::__construct($message, $closeCode);
    }
}
