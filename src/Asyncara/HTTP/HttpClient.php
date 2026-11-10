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
    use \Asyncara\Internal\NonCopyable;

    private Semaphore $slots;
    private ?bool $hyper = null;
    private ?string $hyperCaPem = null;
    public function __construct(int $concurrency = 32, private float $timeout = 30, private ?string $proxy = null, private ?string $caFile = null, private float $connectTimeout = 10)
    {
        if ($concurrency < 1 || $concurrency > 256 || $timeout <= 0 || $timeout > 3600 || !is_finite($timeout)) throw new \ValueError('Invalid HTTP limits');
        if ($connectTimeout <= 0 || $connectTimeout > 3600 || !is_finite($connectTimeout)) throw new \ValueError('Invalid HTTP connect timeout');
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
    /** Worker-backed writes. Atomic mode publishes only a complete body. */
    public function download(string $url, string $path, array $headers = [], bool $followRedirects = false,
        bool $atomic = false, bool $durable = false, bool $failOnHttpError = false): Future
    {
        if ($durable && !$atomic) throw new \ValueError('Durability requires atomic download');
        return Async::spawn(function () use ($url, $path, $headers, $followRedirects, $atomic, $durable, $failOnHttpError): int {
            $file = null; $stream = null;
            try {
                if ($atomic) {
                    // A completed acquisition owns a resource, not just a value.
                    // Take ownership before observing waiter cancellation; it
                    // can race the stage Task's completion notification.
                    $file = Async::shield(static fn() => \Asyncara\IO\File::stage($path)->await());
                    Scheduler::current()->currentCancellation()?->throwIfRequested();
                }
                $stream = $this->getStream($url, $headers, $followRedirects);
                if ($failOnHttpError) {
                    $status = $stream->headers()->await()->status;
                    if ($status < 200 || $status >= 300) throw new \Asyncara\Exception\OperationException('HTTP download status '.$status);
                }
                if (!$atomic) return Async::await(Files::writeStream($path, $stream));
                $bytes = 0;
                foreach ($stream as $chunk) $bytes += Async::await($file->write($chunk));
                $stream->close();
                Async::await($file->publish($durable));
                return $bytes;
            } finally {
                try { $stream?->close(); }
                finally { if ($file) Async::shield(static fn() => $file->close()->await()); }
            }
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
    /** 1..16 sequential worker-streamed files; duplicate field names are allowed. */
    public function multipartFiles(string $url, array $fields, array $files, array $headers = []): Future
    {
        if (!$files || count($files) > 16 || !array_is_list($files)) throw new \ValueError('Expected 1..16 multipart file descriptors');
        $metadata = []; $sources = [];
        foreach ($files as $file) {
            if (!is_array($file) || array_diff(array_keys($file), ['field', 'path', 'filename', 'contentType']) ||
                !isset($file['field'], $file['path']) || !is_string($file['field']) || !is_string($file['path']) ||
                $file['path'] === '' || str_contains($file['path'], "\0")) throw new \ValueError('Invalid multipart file descriptor');
            $part = ['field' => $file['field'], 'name' => $file['filename'] ?? basename($file['path']), 'type' => $file['contentType'] ?? 'application/octet-stream'];
            foreach ($part as $value) if (!is_string($value) || $value === '' || strlen($value) > 256 || strpbrk($value, "\0\r\n") !== false) throw new \ValueError('Invalid multipart metadata');
            $metadata[] = $part; $path = $file['path']; $sources[] = static fn() => Files::readStream($path);
        }
        $options = $this->options('POST', $url, $headers, false) + ['multipart' => $fields, 'files' => $metadata];
        return $this->uploadRequest($url, $sources[0], null, $options, $sources);
    }
    private function uploadRequest(string $url, \Closure $source, ?int $length, array $options, ?array $sources = null): Future
    {
        $options['uploadSize'] = $length ?? -1;
        return Async::spawn(fn() => $this->slots->withPermit(function () use ($url, $source, $options, $sources): Response {
            $scheduler = Scheduler::current();
            [$id, $completion] = $scheduler->nativeOperation('http', [$url, null, $options, (int)ceil($this->timeout * 1000)]);
            $pump = null; $unsubscribe = null;
            try {
                $pump = Async::spawn(function () use ($source, $sources, $scheduler, $id, $completion): void {
                  foreach ($sources ?? [$source] as $part => $factory) {
                    if ($completion->isCompleted()) return;
                    $body = $factory();
                    try {
                        foreach ($body as $chunk) {
                            if (!is_string($chunk) || strlen($chunk) > 65536) throw new \ValueError('Upload chunks must be strings of at most 65536 bytes');
                            while (!$completion->isCompleted() && !asyncara_http_write($id, $chunk, false, $part)) $scheduler->httpWritable($id, $completion);
                            if ($completion->isCompleted()) return;
                        }
                        while (!$completion->isCompleted() && !asyncara_http_write($id, '', true, $part)) $scheduler->httpWritable($id, $completion);
                    } finally { if ($body instanceof ReadableStream) $body->close(); }
                  }
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
        if ($this->hyper ??= asyncara_info()['httpBackend'] === 'hyper-prototype') {
            $host = parse_url($url, PHP_URL_HOST);
            if (is_string($host) && (parse_url($url, PHP_URL_SCHEME) === 'https' || inet_pton(trim($host, '[]')) === false)) {
                return $this->preparedRequest($url, $body, $options, $host);
            }
        }
        $scheduler = Scheduler::current();
        if ($this->slots->tryAcquire()) {
            try { $completion = new BufferedRequest($this->slots, $scheduler); }
            catch (\Throwable $error) { $this->slots->release(); throw $error; }
            try { $scheduler->nativeOperation('http', [$url, $body, $options, (int)ceil($this->timeout * 1000)], $completion); }
            catch (\Throwable $error) { $completion->reject($error); }
            return $completion;
        }
        // Contended admission retains the existing cancellable FIFO Task path.
        return Async::spawn(fn() => $this->slots->withPermit(function () use ($url, $body, $options): Response {
            $future = Scheduler::current()->native('http', [$url, $body, $options, (int)ceil($this->timeout * 1000)]);
            try { $result = $future->await(); return new Response($result['status'], $result['body'], $result['headers']); }
            finally {
                // The request owns this native operation and its admission slot.
                // Retain both until cancellation has been collected, just as
                // streaming requests and uploads join their native completion.
                if (!$future->isCompleted()) {
                    $future->cancel();
                    Async::shield(static function () use ($future): void {
                        try { $future->await(); } catch (\Throwable) {}
                    });
                }
            }
        }));
    }
    /** Hyper resolves DNS and loads trust bytes using existing bounded native workers. */
    private function preparedRequest(string $url, ?string $body, array $options, string $host): Future
    {
        return Async::spawn(fn() => $this->slots->withPermit(function () use ($url, $body, $options, $host): Response {
            $tls = parse_url($url, PHP_URL_SCHEME) === 'https';
            $numeric = inet_pton(trim($host, '[]')) !== false;
            if ($options['followRedirects'] ||
                isset($options['proxy']) || (!$tls && isset($options['caFile'])) ||
                parse_url($url, PHP_URL_USER) !== null || (!$numeric &&
                (strlen($host) > 253 || !preg_match('/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*\.?$/D', $host)))) {
                throw new \Asyncara\Exception\OperationException('Invalid or unsupported Hyper origin');
            }
            foreach (['http_proxy', 'HTTP_PROXY', 'https_proxy', 'HTTPS_PROXY', 'all_proxy', 'ALL_PROXY'] as $name) {
                if (($proxy = getenv($name)) !== false && $proxy !== '') throw new \Asyncara\Exception\OperationException('Hyper proxy is unsupported');
            }
            $start = Scheduler::now();
            $addresses = Async::timeout(min($this->timeout, $this->connectTimeout), function () use ($host, $numeric, $tls, &$options): array {
                if ($tls) {
                    if ($this->hyperCaPem === null) {
                        // Linux system trust snapshot or explicit caFile. No blocking
                        // trust-store load is performed by Rust's progress callbacks.
                        $path = $this->caFile ?? (getenv('SSL_CERT_FILE') ?: '/etc/ssl/certs/ca-certificates.crt');
                        if ($path === '' || str_contains($path, "\0") || str_contains($path, '://')) throw new \ValueError('CA file must be a local path');
                        $pem = Async::using(static fn() => Files::open($path), static function ($file): string {
                            $data = '';
                            while (strlen($data) <= 1048576) {
                                $chunk = Async::await($file->read(min(65536, 1048577 - strlen($data))));
                                if ($chunk === '') break;
                                $data .= $chunk;
                            }
                            return $data;
                        }, static fn($file) => $file->close());
                        if ($pem === '' || strlen($pem) > 1048576) throw new \Asyncara\Exception\OperationException('CA file must contain 1..1048576 bytes');
                        $this->hyperCaPem = $pem;
                    }
                    unset($options['caFile']); $options['caPem'] = $this->hyperCaPem;
                }
                return $numeric ? [] : Async::await(\Asyncara\IO\Dns::resolve($host));
            });
            Scheduler::current()->currentCancellation()?->throwIfRequested();
            $addresses = array_values(array_unique($addresses));
            if (!$numeric && (!$addresses || count($addresses) > 16)) throw new \Asyncara\Exception\OperationException('Hyper DNS address limit (1..16)');
            $elapsed = Scheduler::now() - $start;
            $remaining = $this->timeout - $elapsed;
            $connect = $this->connectTimeout - $elapsed;
            if ($remaining <= 0 || $connect <= 0) throw new \Asyncara\Exception\TimeoutException('HTTP DNS deadline exceeded');
            if (!$numeric) $options['resolvedAddresses'] = $addresses;
            $options['connectTimeoutMs'] = (int)ceil($connect * 1000);
            $result = Async::await(Scheduler::current()->native('http', [$url, $body, $options, (int)ceil($remaining * 1000)]));
            return new Response($result['status'], $result['body'], $result['headers']);
        }));
    }
    private function options(string $method, string $url, array $headers, bool $followRedirects): array
    {
        if (!preg_match('/^[A-Z]+$/D', $method)) throw new \ValueError('Invalid HTTP method');
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) throw new \ValueError('Only HTTP(S) URLs are allowed');
        $options = ['method' => $method, 'headers' => $headers, 'followRedirects' => $followRedirects, 'connectTimeoutMs' => (int)ceil($this->connectTimeout * 1000)];
        if ($this->proxy !== null) $options['proxy'] = $this->proxy;
        if ($this->caFile !== null) $options['caFile'] = $this->caFile;
        return $options;
    }
}
