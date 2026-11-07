<?php
declare(strict_types=1);
namespace Asyncara\Internal;

/** Share the canonical object by reference; never duplicate ownership state. */
trait NonCopyable
{
    use NotSerializable;
    // Reject before PHP constructs a clone whose destructor could close the
    // canonical resource. Future subclasses must retain the same policy.
    final protected function __clone() {}
}
