<?php
declare(strict_types=1);
namespace Asyncara;
use Asyncara\Exception\CancelledException;
final class CancellationSource
{
    use \Asyncara\Internal\NotSerializable;

    public readonly CancellationToken $token;
    public function __construct() { $this->token = new CancellationToken(); }
    public function cancel(?CancelledException $reason = null): void { $this->token->request($reason); }
}
