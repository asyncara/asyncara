<?php
declare(strict_types=1);
namespace Asyncara\HTTP;
use Asyncara\IO\Files;
use Asyncara\Stream\AsyncStream;
/** @internal Bounded MIME producer for the Hyper transport's ordinary upload path. */
final class Multipart
{
    public readonly string $contentType;
    private string $boundary;
    private array $fields = [];
    private array $files = [];
    public function __construct(array $fields, array $files)
    {
        if (count($fields) > 64 || count($files) < 1 || count($files) > 16) throw new \ValueError('Multipart part count limit');
        $bytes = 0;
        foreach ($fields as $name => $value) {
            if (!is_string($name)) throw new \ValueError('Multipart field names must be strings');
            self::metadata($name);
            if (!is_string($value) || strlen($value) > 65536) throw new \ValueError('Multipart values must be strings of at most 65536 bytes');
            $bytes += strlen($value);
            $this->fields[$name] = (string)$value;
        }
        if ($bytes > 1048576) throw new \ValueError('Multipart field buffer limit');
        foreach ($files as $file) {
            $part = [];
            foreach (['field','name','type'] as $key) {
                self::metadata($file[$key]);
                $part[$key] = (string)$file[$key];
            }
            $part['path'] = (string)$file['path'];
            $this->files[] = $part;
        }
        $this->boundary = 'asyncara-'.bin2hex(random_bytes(24));
        $this->contentType = 'multipart/form-data; boundary='.$this->boundary;
    }
    private static function metadata(string $value): void
    {
        if ($value === '' || strlen($value) > 256 || strpbrk($value, "\0\r\n") !== false) throw new \ValueError('Invalid multipart metadata');
    }
    private static function quoted(string $value): string { return str_replace(['\\','"'], ['\\\\','\\"'], $value); }
    public function stream(): AsyncStream
    {
        return AsyncStream::fromProducer(function ($emitter): void {
            foreach ($this->fields as $name => $value) {
                $emitter->emit('--'.$this->boundary."\r\nContent-Disposition: form-data; name=\"".self::quoted($name)."\"\r\n\r\n");
                if ($value !== '') $emitter->emit($value);
                $emitter->emit("\r\n");
            }
            foreach ($this->files as $file) {
                $emitter->emit('--'.$this->boundary."\r\nContent-Disposition: form-data; name=\"".self::quoted($file['field']).'"; filename="'.self::quoted($file['name'])."\"\r\nContent-Type: ".$file['type']."\r\n\r\n");
                $stream = Files::readStream($file['path']);
                try { foreach ($stream as $chunk) $emitter->emit($chunk); }
                finally { $stream->close(); }
                $emitter->emit("\r\n");
            }
            $emitter->emit('--'.$this->boundary."--\r\n");
        }, 2);
    }
}
