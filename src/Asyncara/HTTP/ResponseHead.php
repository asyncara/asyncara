<?php
declare(strict_types=1);
namespace Asyncara\HTTP;
final class ResponseHead
{
    public readonly array $headers;
    public function __construct(public readonly int $status, string $rawHeaders)
    {
        $this->headers = (new Response($status, '', $rawHeaders))->headers;
    }
}
