<?php
declare(strict_types=1);
namespace Asyncara\Server;

/** Server value object. Asyncara\HTTP\Response remains the client response. */
final class Response
{
    public function __construct(public readonly string $body = '', public readonly int $status = 200, public readonly array $headers = [])
    {
        if ($status < 200 || $status > 599) throw new \ValueError('Response status must be 200..599');
        if (in_array($status, [204, 205, 304], true) && $body !== '') throw new \ValueError('This status requires an empty body');
    }
    public static function json(mixed $value, int $status = 200, array $headers = []): self
    {
        return new self(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $status, ['content-type' => 'application/json; charset=utf-8'] + $headers);
    }
    public static function text(string $value, int $status = 200, array $headers = []): self
    {
        return new self($value, $status, ['content-type' => 'text/plain; charset=utf-8'] + $headers);
    }
    /** Chunks are produced on demand; maximum chunk size is 64 KiB. */
    public static function stream(iterable $chunks, int $status = 200, array $headers = []): StreamResponse
    {
        return new StreamResponse($chunks, $status, $headers);
    }
}
