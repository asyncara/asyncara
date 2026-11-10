<?php
declare(strict_types=1);
namespace Asyncara;
use Asyncara\Server\{Request, Response, StreamResponse, Route};

/** HTTP/1 server. PHP handlers run on this interpreter's Asyncara Fibers. */
final class App
{
    use Internal\NonCopyable;
    private array $static = [], $dynamic = [], $middleware = [], $tasks = [];
    private array $inboxes = [];
    private string $prefix = '';
    private bool $running = false;
    private bool $workerProcess = false;
    private ?Server\Workers $workers = null;
    private int $handlerTasksCreated = 0, $requestsHandled = 0;
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
    private function dispatch(array $event, bool $headFallback = false, ?Server\RequestBodyStream $input = null): Response|StreamResponse
    {
        $path = explode('?', $event['target'], 2)[0]; $method = $headFallback ? 'GET' : $event['method']; $params = [];
        if (!str_starts_with($path, '/')) return Response::text('Bad Request', 400);
        $route = $this->static[$method][$path] ?? null;
        if ($route === null) foreach ($this->dynamic[$method] ?? [] as $candidate) {
            if (preg_match($candidate->pattern, $path, $matches)) { $route = $candidate; foreach ($matches as $key => $value) if (is_string($key)) $params[$key] = rawurldecode($value); break; }
        }
        if ($route === null && $method === 'HEAD') return $this->dispatch($event, true, $input);
        if ($route === null) return Response::text('Not Found', 404);
        $request = new Request($event['method'], $event['target'], $event['body'], $event['headers'], $params, $input);
        $next = $route->handler;
        foreach (array_reverse($route->middleware) as $item) {
            $middleware = is_string($item) ? $this->middleware[$item] : $item;
            $next = static fn(Request $req): Response|StreamResponse => $middleware($req, $next);
        }
        return $next($request);
    }
    /** Numeric IP:port. Limits are per process. onReady receives the bound address. */
    public function listen(string $address, array $options = [], ?callable $onReady = null): void
    {
        if (PHP_SAPI !== 'cli') throw new \LogicException('App server requires the CLI runtime');
        if ($this->running || Scheduler::tryCurrent()) throw new \LogicException('listen owns a top-level runtime');
        if (!function_exists('asyncara_server_listen')) throw new \RuntimeException('Build Asyncara with the Hyper server feature');
        $workers = $options['workers'] ?? 1; unset($options['workers']);
        $tls = $options['tls'] ?? null; unset($options['tls']);
        $streamRequests = $options['streamRequests'] ?? false; unset($options['streamRequests']);
        if (!is_bool($streamRequests)) throw new \ValueError('streamRequests must be boolean');
        if ($tls !== null && !is_array($tls)) throw new \ValueError('tls must be an array');
        if (!is_int($workers) || $workers < 1 || $workers > 64) throw new \ValueError('workers must be 1..64');
        $defaults = ['connections' => 1024, 'bodyBytes' => 1048576, 'responseBytes' => 8388608, 'bufferBytes' => 67108864, 'timeoutMs' => 30000, 'requestsPerConnection' => 10000];
        foreach ($options as $name => $value) if (!array_key_exists($name, $defaults) || !is_int($value)) throw new \ValueError('Unknown or non-integer server option: ' . $name);
        $limits = array_replace($defaults, $options);
        foreach ($this->routes() as $route) foreach ($route->middleware as $item) if (is_string($item) && !isset($this->middleware[$item])) throw new \LogicException('Unregistered middleware: ' . $item);
        if ($workers > 1) {
            if (str_ends_with($address, ':0')) throw new \ValueError('Multiple workers require a fixed port');
            $this->workers = new Server\Workers();
            try {
                $this->workers->run($workers, $limits['timeoutMs'], function (callable $ready) use ($address, $options, $tls, $streamRequests): void {
                    $this->workers = null; $this->workerProcess = true;
                    $this->listen($address, $options + ['streamRequests' => $streamRequests] + ($tls === null ? [] : ['tls' => $tls]), static fn() => $ready());
                }, $onReady === null ? null : static fn() => $onReady($address));
            } finally { $this->workers = null; }
            return;
        }
        $pem = ['', '', ''];
        if ($tls !== null) {
            foreach ($tls as $name => $_) if (!in_array($name, ['certificate', 'privateKey', 'clientCa'], true)) throw new \ValueError('Unknown TLS option: ' . $name);
            foreach (['certificate', 'privateKey', 'clientCa'] as $i => $name) {
                if ($name === 'clientCa' && !isset($tls[$name])) continue;
                $path = $tls[$name] ?? null;
                if (!is_string($path) || $path === '' || str_contains($path, "\0") || str_contains($path, '://')) throw new \ValueError('TLS requires local certificate/privateKey paths');
                // Setup only, before accepting or entering the runtime. PHP enforces open_basedir.
                $bytes = file_get_contents($path, false, null, 0, 262145);
                if ($bytes === false || $bytes === '' || strlen($bytes) > 262144) throw new \ValueError('TLS PEM file must contain 1..262144 bytes');
                $pem[$i] = $bytes;
            }
        }
        $arguments = [...array_values($limits), $this->workerProcess, ...$pem, $streamRequests];
        $info = \asyncara_server_listen($address, ...$arguments);
        unset($arguments, $pem, $bytes); // Do not retain PEM/key strings in the listener frame.
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
                        if ($event['kind'] === 11) { $event['kind'] = 1; $event['streamed'] = true; }
                        $connection = $event['connection'];
                        if ($event['kind'] === 2 || $event['kind'] === 4) {
                            ($this->tasks[$connection] ?? null)?->cancel();
                            unset($this->tasks[$connection], $this->inboxes[$connection]);
                        } elseif (isset($this->inboxes[$connection])) { $this->inboxes[$connection]->put($event); }
                        elseif ($event['kind'] === 1) {
                            $inbox = new Server\Inbox();
                            try { $this->tasks[$connection] = Async::spawn(function () use ($connection, $inbox): void {
                                try {
                                    while (true) {
                                        $event = $inbox->take();
                                        // A Fiber is reused, request Context is not. PHP globals
                                        // remain explicitly process scoped, as documented.
                                        Scheduler::current()->task->context = [];
                                        $id = $event['id'];
                                        $this->requestsHandled++;
                                        $streaming = false;
                                        $input = isset($event['streamed']) ? new Server\RequestBodyStream($id, $inbox) : null;
                                        try {
                                            try { $response = $this->dispatch($event, false, $input); }
                                            catch (Server\RequestBodyException $e) { $response = Response::text('Request body rejected', $e->status); }
                                            catch (\JsonException) { $response = Response::text('Invalid JSON', 400); }
                                            finally { $input?->close(); }
                                            if ($response instanceof StreamResponse && $event['method'] !== 'HEAD') {
                                                try {
                                                    $streaming = \asyncara_server_respond($id, $response->status, $response->headers, '', true);
                                                    if ($streaming) $response->send($id, $inbox);
                                                } finally { $response->close(); }
                                            } else {
                                                if ($response instanceof StreamResponse) $response->close();
                                                \asyncara_server_respond($id, $response->status, $response->headers, $response instanceof Response ? $response->body : '');
                                            }
                                        } catch (Exception\CancelledException $e) { if ($streaming) \asyncara_server_chunk($id, '', -1); throw $e; }
                                        catch (\Throwable) {
                                            if ($streaming) { \asyncara_server_chunk($id, '', -1); return; }
                                            \asyncara_server_respond($id, 500, [], '');
                                        }
                                        unset($response, $event, $input);
                                        Scheduler::current()->task->context = [];
                                    }
                                } catch (Exception\CancelledException) { /* owner disappeared */ }
                                finally { unset($this->tasks[$connection], $this->inboxes[$connection]); }
                            }, false); $inbox->put($event); $this->inboxes[$connection] = $inbox; $this->handlerTasksCreated++; }
                            catch (Exception\CapacityException) { \asyncara_server_respond($event['id'], 503, [], ''); }
                        }
                        if (++$processed === 256) { Async::sleep(0); break; }
                    }
                    if ($processed < 256) Scheduler::current()->waitDescriptor($info['fd'], 1);
                }
            });
        } finally {
            \asyncara_server_close(); $this->tasks = $this->inboxes = []; $this->running = false;
            foreach ($signals as $signal => $handler) pcntl_signal($signal, $handler);
            if ($asyncSignals !== null) pcntl_async_signals($asyncSignals);
            foreach ($this->routes() as $route) $route->frozen = false;
        }
    }
    /** Stop accepting, flush in-flight responses, then return from listen. */
    public function stop(): void { if ($this->workers) $this->workers->stop(); elseif ($this->running) \asyncara_server_shutdown(); }
    public function stats(): ?array { return $this->running ? \asyncara_server_info() + ['handlerTasksCreated'=>$this->handlerTasksCreated, 'requestsHandled'=>$this->requestsHandled] : null; }
}
