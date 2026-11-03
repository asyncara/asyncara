<?php
declare(strict_types=1);
namespace Asyncara\HTTP;
use Asyncara\{Async,Future,Scheduler};
use Asyncara\Concurrency\Semaphore;
final class HttpClient
{
    private Semaphore $slots;
    public function __construct(int $concurrency = 32, private float $timeout = 30, private ?string $proxy = null, private ?string $caFile = null)
    {
        if ($concurrency < 1 || $concurrency > 256 || $timeout <= 0 || $timeout > 3600 || !is_finite($timeout)) throw new \ValueError('Invalid HTTP limits');
        $this->slots = new Semaphore($concurrency);
    }
    public function get(string $url, array $headers = [], bool $followRedirects = false): Future { return $this->request('GET', $url, null, $headers, $followRedirects); }
    public function post(string $url, mixed $json = null, ?array $form = null, ?string $body = null, array $headers = []): Future
    {
        if (count(array_filter([$json !== null, $form !== null, $body !== null])) > 1) throw new \ValueError('Specify only one request body format');
        if ($json !== null) { $body = json_encode($json, JSON_THROW_ON_ERROR); $headers[] = 'Content-Type: application/json'; }
        if ($form !== null) { $body = http_build_query($form, '', '&', PHP_QUERY_RFC3986); $headers[] = 'Content-Type: application/x-www-form-urlencoded'; }
        return $this->request('POST', $url, $body ?? '', $headers);
    }
    public function request(string $method, string $url, ?string $body = null, array $headers = [], bool $followRedirects = false): Future
    {
        if (!preg_match('/^[A-Z]+$/D', $method)) throw new \ValueError('Invalid HTTP method');
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) throw new \ValueError('Only HTTP(S) URLs are allowed');
        $options = ['method' => $method, 'headers' => $headers, 'followRedirects' => $followRedirects];
        if ($this->proxy !== null) $options['proxy'] = $this->proxy;
        if ($this->caFile !== null) $options['caFile'] = $this->caFile;
        return Async::spawn(fn() => $this->slots->withPermit(function () use ($url, $body, $options): Response {
            $future = Scheduler::current()->native('http', [$url, $body, $options, (int)ceil($this->timeout * 1000)]);
            try { $result = $future->await(); return new Response($result['status'], $result['body'], $result['headers']); }
            finally { if (!$future->isCompleted()) $future->cancel(); }
        }));
    }
}
