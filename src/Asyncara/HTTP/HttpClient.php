<?php
declare(strict_types=1);
namespace Asyncara\HTTP;
use Asyncara\{Async,Future,Scheduler};
use Asyncara\Concurrency\Semaphore;
use Asyncara\IO\Files;
use Asyncara\Stream\AsyncStream;
use Asyncara\Stream\ReadableStream;
final class HttpClient
{
    private Semaphore $slots;
    public function __construct(int $concurrency = 32, private float $timeout = 30, private ?string $proxy = null, private ?string $caFile = null)
    {
        if ($concurrency < 1 || $concurrency > 256 || $timeout <= 0 || $timeout > 3600 || !is_finite($timeout)) throw new \ValueError('Invalid HTTP limits');
        $this->slots = new Semaphore($concurrency);
    }
    public function get(string $url, array $headers = [], bool $followRedirects = false): Future { return $this->request('GET', $url, null, $headers, $followRedirects); }
    /** Encoded HTTP/1.1 response bytes with bounded buffers. Close on early exit. */
    public function getStream(string $url, array $headers = [], bool $followRedirects = false): ResponseStream
    {
        $options = $this->options('GET', $url, $headers, $followRedirects) + ['streamResponse' => true];
        $head = new Future();
        $stream = AsyncStream::fromProducer(function ($emitter) use ($url, $options, $head): void {
          try {
            $this->slots->withPermit(function () use ($url, $options, $emitter, $head): void {
                $scheduler = Scheduler::current();
                [$id, $completion] = $scheduler->nativeOperation('http', [$url, null, $options, (int)ceil($this->timeout * 1000)]);
                try {
                    while (!$completion->isCompleted()) {
                        if (!$head->isCompleted() && ($headers = asyncara_http_head($id)) !== null)
                            $head->resolve(new ResponseHead($headers['status'], $headers['headers']));
                        $chunk = asyncara_http_read($id);
                        if ($chunk !== '') $emitter->emit($chunk);
                        else $scheduler->httpReadable($id, $completion);
                    }
                    $result = $completion->await();
                    if (!$head->isCompleted()) $head->resolve(new ResponseHead($result['status'], $result['headers']));
                    if ($result['body'] !== '') $emitter->emit($result['body']);
                } finally {
                    if (!$completion->isCompleted()) $completion->cancel();
                    Async::shield(static function () use ($completion): void { try { $completion->await(); } catch (\Throwable) {} });
                }
            });
          } catch (\Throwable $error) { if (!$head->isCompleted()) $head->reject($error); throw $error; }
        }, 2);
        return new ResponseStream($stream, $head);
    }
    /** Stream to worker-backed file writes; failure can leave a partial file. */
    public function download(string $url, string $path, array $headers = [], bool $followRedirects = false): Future
    {
        return Async::spawn(function () use ($url, $path, $headers, $followRedirects): int {
            $stream = $this->getStream($url, $headers, $followRedirects);
            try { return Async::await(Files::writeStream($path, $stream)); }
            finally { $stream->close(); }
        });
    }
    /** Worker-backed file input; unknown length is sent using chunked transfer. */
    public function upload(string $url, string $path, string $method = 'PUT', array $headers = []): Future
    {
        // Open lazily in the owned producer so failed validation cannot abandon it.
        $options = $this->options($method, $url, $headers, false);
        return $this->uploadRequest($url, static fn() => Files::readStream($path), null, $options);
    }
    /** Each producer chunk must be a string of at most 64 KiB. No redirect replay. */
    public function requestStream(string $method, string $url, iterable $body, ?int $contentLength = null, array $headers = []): Future
    {
        if ($contentLength !== null && $contentLength < 0) throw new \ValueError('Invalid upload content length');
        return $this->uploadRequest($url, static fn() => $body, $contentLength, $this->options($method, $url, $headers, false));
    }
    /** Curl MIME fields plus one worker-streamed file; bounded scalar fields. */
    public function multipart(string $url, array $fields, string $fileField, string $path, ?string $filename = null, string $contentType = 'application/octet-stream', array $headers = []): Future
    {
        $options = $this->options('POST', $url, $headers, false) + [
            'multipart' => $fields, 'fileField' => $fileField, 'fileName' => $filename ?? basename($path), 'fileType' => $contentType,
        ];
        return $this->uploadRequest($url, static fn() => Files::readStream($path), null, $options);
    }
    private function uploadRequest(string $url, \Closure $source, ?int $length, array $options): Future
    {
        $options['uploadSize'] = $length ?? -1;
        return Async::spawn(fn() => $this->slots->withPermit(function () use ($url, $source, $options): Response {
            $scheduler = Scheduler::current();
            [$id, $completion] = $scheduler->nativeOperation('http', [$url, null, $options, (int)ceil($this->timeout * 1000)]);
            $pump = null; $unsubscribe = null;
            try {
                $pump = Async::spawn(function () use ($source, $scheduler, $id, $completion): void {
                    $body = $source();
                    try {
                        foreach ($body as $chunk) {
                            if (!is_string($chunk) || strlen($chunk) > 65536) throw new \ValueError('Upload chunks must be strings of at most 65536 bytes');
                            while (!$completion->isCompleted() && !asyncara_http_write($id, $chunk, false)) $scheduler->httpWritable($id, $completion);
                            if ($completion->isCompleted()) return;
                        }
                        while (!$completion->isCompleted() && !asyncara_http_write($id, '', true)) $scheduler->httpWritable($id, $completion);
                    } finally { if ($body instanceof ReadableStream) $body->close(); }
                });
                $unsubscribe = $pump->subscribe(static function (Future $done) use ($completion): void { if ($done->exception()) $completion->cancel(); });
                try { $result = $completion->await(); }
                catch (\Throwable $error) { throw $pump->exception() ?? $error; }
                return new Response($result['status'], $result['body'], $result['headers']);
            } finally {
                $completion->cancel(); $pump?->cancel();
                Async::shield(static function () use ($completion, $pump): void {
                    if ($pump) try { $pump->await(); } catch (\Throwable) {}
                    try { $completion->await(); } catch (\Throwable) {}
                });
                if ($unsubscribe) $unsubscribe();
            }
        }));
    }
    public function post(string $url, mixed $json = null, ?array $form = null, ?string $body = null, array $headers = []): Future
    {
        if (count(array_filter([$json !== null, $form !== null, $body !== null])) > 1) throw new \ValueError('Specify only one request body format');
        if ($json !== null) { $body = json_encode($json, JSON_THROW_ON_ERROR); $headers[] = 'Content-Type: application/json'; }
        if ($form !== null) { $body = http_build_query($form, '', '&', PHP_QUERY_RFC3986); $headers[] = 'Content-Type: application/x-www-form-urlencoded'; }
        return $this->request('POST', $url, $body ?? '', $headers);
    }
    public function request(string $method, string $url, ?string $body = null, array $headers = [], bool $followRedirects = false): Future
    {
        $options = $this->options($method, $url, $headers, $followRedirects);
        return Async::spawn(fn() => $this->slots->withPermit(function () use ($url, $body, $options): Response {
            $future = Scheduler::current()->native('http', [$url, $body, $options, (int)ceil($this->timeout * 1000)]);
            try { $result = $future->await(); return new Response($result['status'], $result['body'], $result['headers']); }
            finally { if (!$future->isCompleted()) $future->cancel(); }
        }));
    }
    private function options(string $method, string $url, array $headers, bool $followRedirects): array
    {
        if (!preg_match('/^[A-Z]+$/D', $method)) throw new \ValueError('Invalid HTTP method');
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) throw new \ValueError('Only HTTP(S) URLs are allowed');
        $options = ['method' => $method, 'headers' => $headers, 'followRedirects' => $followRedirects];
        if ($this->proxy !== null) $options['proxy'] = $this->proxy;
        if ($this->caFile !== null) $options['caFile'] = $this->caFile;
        return $options;
    }
}
