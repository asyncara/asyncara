<?php
declare(strict_types=1);
namespace Asyncara\Internal;

/** Request-local references cannot be reconstructed in another request. */
trait NotSerializable
{
    final public function __serialize(): array
    {
        throw new \LogicException(static::class.' contains request-local state and cannot be serialized');
    }
    final public function __unserialize(array $data): void
    {
        throw new \LogicException(static::class.' cannot be reconstructed from serialized state');
    }
}
