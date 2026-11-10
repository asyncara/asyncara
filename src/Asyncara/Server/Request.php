<?php
declare(strict_types=1);
namespace Asyncara\Server;

/** Immutable metadata; opt-in body input shares a handler-scoped stream owner. */
final class Request
{
    private ?array $queryValues = null, $headerValues = null;
    private ?string $collected = null;
    public readonly string $path;
    public function __construct(public readonly string $method, public readonly string $target, public readonly string $body = '', private readonly string $rawHeaders = '', private readonly array $params = [], private readonly ?RequestBodyStream $input = null)
    {
        $this->path = explode('?', $target, 2)[0];
    }
    public function param(string $name, mixed $default = null): mixed { return $this->params[$name] ?? $default; }
    public function query(string $name, mixed $default = null): mixed
    {
        if ($this->queryValues === null) {
            $this->queryValues = [];
            $query = explode('?', $this->target, 2)[1] ?? '';
            foreach ($query === '' ? [] : explode('&', $query) as $pair) {
                [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
                $this->queryValues[urldecode($key)] = urldecode($value);
            }
        }
        return $this->queryValues[$name] ?? $default;
    }
    public function header(string $name, ?string $default = null): ?string
    {
        if ($this->headerValues === null) {
            $this->headerValues = [];
            foreach (explode("\r\n", $this->rawHeaders) as $line) {
                if ($line === '') continue;
                [$key, $value] = explode(': ', $line, 2);
                $this->headerValues[$key][] = $value;
            }
        }
        return isset($this->headerValues[strtolower($name)]) ? implode(', ', $this->headerValues[strtolower($name)]) : $default;
    }
    /** ext-json's C parser. Invalid JSON throws JsonException (App returns 400). */
    public function json(bool $associative = true): mixed
    {
        if ($this->input !== null && $this->collected === null) {
            $bytes = '';
            foreach ($this->input as $chunk) $bytes .= $chunk;
            $this->collected = $bytes;
        }
        return json_decode($this->collected ?? $this->body, $associative, 512, JSON_THROW_ON_ERROR);
    }
    /** Body bytes in native streaming mode, or one already-buffered value. */
    public function stream(): iterable
    {
        if ($this->input !== null) return $this->input;
        return (function (): \Generator { if ($this->body !== '') yield $this->body; })();
    }
}
