<?php
declare(strict_types=1);
namespace Asyncara;
interface Awaitable { public function await(?CancellationToken $cancellation = null): mixed; }
