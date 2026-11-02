<?php
declare(strict_types=1);
namespace Asyncara;
final class WaitResult { public function __construct(public readonly array $completed, public readonly array $pending) {} }
