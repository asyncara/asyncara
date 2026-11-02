<?php
declare(strict_types=1);
namespace Asyncara\WebSocket;
final class Message
{
    public function __construct(public readonly string $data, public readonly bool $binary = false) {}
}
