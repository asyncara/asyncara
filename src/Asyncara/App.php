<?php
declare(strict_types=1);
namespace Asyncara;
use Asyncara\Server\{Request, Response, Route};

/** HTTP/1 server. PHP handlers run on this interpreter's Asyncara Fibers. */
final class App
{
    use Internal\NonCopyable;
    private array $static = [], $dynamic = [], $middleware = [], $tasks = [];
    private string $prefix = '';
    private bool $running = false;
    private bool $workerProcess = false;
    private ?Server\Workers $workers = null;
    public function route(string $method, string $path, callable $handler): Route
    {
        if ($this->running) throw new \LogicException('Register routes before listen');
        if (!preg_match('/\A[A-Z]+\z/D', $method) || !str_starts_with($path, '/') || strpbrk($path, "?\r\n\0") !== false) throw new \ValueError('Invalid route');
        $route = new Route($method, $this->prefix . $path, $handler);
        if ($route->pattern === null) {
            if (isset($this->static[$method][$route->path])) throw new \LogicException('Duplicate route');
            $this->static[$method][$route->path] = $route;
        } else $this->dynamic[$method][] = $route;
        return $route;
    }
    public function get(string $path, callable $handler): Route { return $this->route('GET', $path, $handler); }
    public function post(string $path, callable $handler): Route { return $this->route('POST', $path, $handler); }
    public function put(string $path, callable $handler): Route { return $this->route('PUT', $path, $handler); }
    public function patch(string $path, callable $handler): Route { return $this->route('PATCH', $path, $handler); }
    public function delete(string $path, callable $handler): Route { return $this->route('DELETE', $path, $handler); }
    public function group(string $prefix, callable $register): void
    {
        if ($this->running || !str_starts_with($prefix, '/') || strpbrk($prefix, "?\r\n\0") !== false) throw new \ValueError('Invalid group');
        $previous = $this->prefix; $this->prefix .= rtrim($prefix, '/');
        try { $register($this); } finally { $this->prefix = $previous; }
    }
    public function middleware(string $name, callable $handler): self
    {
        if ($this->running) throw new \LogicException('Register middleware before listen');
        $this->middleware[$name] = $handler(...); return $this;
    }
    private function routes(): \Generator
    {
        foreach ([$this->static, $this->dynamic] as $methods) foreach ($methods as $routes) foreach ($routes as $route) yield $route;
    }
    private function dispatch(array $event): Response
    {
        $path = explode('?', $event['target'], 2)[0]; $method = $event['method']; $params = [];
        if (!str_starts_with($path, '/')) return Response::text('Bad Request', 400);
        $route = $this->static[$method][$path] ?? null;
        if ($route === null) foreach ($this->dynamic[$method] ?? [] as $candidate) {
            if (preg_match($candidate->pattern, $path, $matches)) { $route = $candidate; foreach ($matches as $key => $value) if (is_string($key)) $params[$key] = rawurldecode($value); break; }
        }
        if ($route === null && $method === 'HEAD') { $event['method'] = 'GET'; return $this->dispatch($event); }
        if ($route === null) return Response::text('Not Found', 404);
        $request = new Request($method, $event['target'], $event['body'], $event['headers'], $params);
        $next = $route->handler;
        foreach (array_reverse($route->middleware) as $item) {
            $middleware = is_string($item) ? $this->middleware[$item] : $item;
            $next = static fn(Request $req): Response => $middleware($req, $next);
        }
        return $next($request);
    }
    /** Numeric IP:port. Limits are per process. onReady receives the bound address. */
    public function listen(string $address, array $options = [], ?callable $onReady = null): void
    {
        if ($this->running || Scheduler::tryCurrent()) throw new \LogicException('listen owns a top-level runtime');
        if (!function_exists('asyncara_server_listen')) throw new \RuntimeException('Build Asyncara with the Hyper server feature');
        $workers = $options['workers'] ?? 1; unset($options['workers']);
        if (!is_int($workers) || $workers < 1 || $workers > 64) throw new \ValueError('workers must be 1..64');
        $defaults = ['connections' => 1024, 'bodyBytes' => 1048576, 'responseBytes' => 8388608, 'bufferBytes' => 67108864, 'timeoutMs' => 30000, 'requestsPerConnection' => 10000];
        foreach ($options as $name => $value) if (!array_key_exists($name, $defaults) || !is_int($value)) throw new \ValueError('Unknown or non-integer server option: ' . $name);
        $limits = array_replace($defaults, $options);
        foreach ($this->routes() as $route) foreach ($route->middleware as $item) if (is_string($item) && !isset($this->middleware[$item])) throw new \LogicException('Unregistered middleware: ' . $item);
        if ($workers > 1) {
            if (str_ends_with($address, ':0')) throw new \ValueError('Multiple workers require a fixed port');
            $this->workers = new Server\Workers();
            try {
                $this->workers->run($workers, $limits['timeoutMs'], function (callable $ready) use ($address, $options): void {
                    $this->workers = null; $this->workerProcess = true;
                    $this->listen($address, $options, static fn() => $ready());
                }, $onReady === null ? null : static fn() => $onReady($address));
            } finally { $this->workers = null; }
            return;
        }
        $arguments = [...array_values($limits), $this->workerProcess];
        $info = \asyncara_server_listen($address, ...$arguments);
        $this->running = true;
        $signals = []; $asyncSignals = null;
        if (function_exists('pcntl_async_signals')) {
            $asyncSignals = pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT] as $signal) { $signals[$signal] = pcntl_signal_get_handler($signal); pcntl_signal($signal, fn() => $this->stop()); }
        }
        foreach ($this->routes() as $route) $route->frozen = true;
        try {
            Async::run(function () use ($info, $onReady): void {
                if ($onReady !== null) $onReady($info['address']);
                while (true) {
                    $processed = 0;
                    while ($event = \asyncara_server_next()) {
                        if ($event['kind'] === 3) return;
                        $id = $event['id'];
                        if ($event['kind'] === 2) { ($this->tasks[$id] ?? null)?->cancel(); }
                        else {
                            try { $this->tasks[$id] = Async::spawn(function () use ($id, $event): void {
                                try {
                                    try { $response = $this->dispatch($event); }
                                    catch (\JsonException) { $response = Response::text('Invalid JSON', 400); }
                                    \asyncara_server_respond($id, $response->status, $response->headers, $response->body);
                                } catch (Exception\CancelledException) { /* owner disappeared */ }
                                catch (\Throwable) { \asyncara_server_respond($id, 500, [], ''); }
                                finally { unset($this->tasks[$id]); }
                            }, false); }
                            catch (Exception\CapacityException) { \asyncara_server_respond($id, 503, [], ''); }
                        }
                        if (++$processed === 256) { Async::sleep(0); break; }
                    }
                    if ($processed < 256) Scheduler::current()->waitDescriptor($info['fd'], 1);
                }
            });
        } finally {
            \asyncara_server_close(); $this->tasks = []; $this->running = false;
            foreach ($signals as $signal => $handler) pcntl_signal($signal, $handler);
            if ($asyncSignals !== null) pcntl_async_signals($asyncSignals);
            foreach ($this->routes() as $route) $route->frozen = false;
        }
    }
    /** Stop accepting, flush in-flight responses, then return from listen. */
    public function stop(): void { if ($this->workers) $this->workers->stop(); elseif ($this->running) \asyncara_server_shutdown(); }
    public function stats(): ?array { return $this->running ? \asyncara_server_info() : null; }
}
