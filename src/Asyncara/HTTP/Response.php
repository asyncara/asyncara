<?php
declare(strict_types=1);
namespace Asyncara\HTTP;
final class Response
{
    public readonly array $headers;
    public function __construct(public readonly int $status, public readonly string $body, string $rawHeaders)
    {
        if (function_exists('asyncara_http_headers')) { $this->headers = asyncara_http_headers($rawHeaders); return; }
        $headers = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (str_starts_with($line, 'HTTP/')) { $headers = []; continue; }
            if (str_contains($line, ':')) { [$name, $value] = explode(':', $line, 2); $headers[strtolower(trim($name))][] = trim($value); }
        }
        $this->headers = $headers;
    }
    public function json(bool $associative = true): mixed { return json_decode($this->body, $associative, 512, JSON_THROW_ON_ERROR); }
}
