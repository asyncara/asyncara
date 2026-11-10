<?php
declare(strict_types=1);
/** Convenience facade; milliseconds, unlike Asyncara\Async::sleep(seconds). */
final class Asyncara
{
    public static function sleep(int $milliseconds): void
    {
        if ($milliseconds < 0) throw new \ValueError('Sleep must be nonnegative');
        \Asyncara\Async::sleep($milliseconds / 1000);
    }
}
