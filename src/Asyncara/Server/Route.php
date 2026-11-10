<?php
declare(strict_types=1);
namespace Asyncara\Server;
final class Route
{
    use \Asyncara\Internal\NonCopyable;
    /** @internal */ public array $middleware = [];
    /** @internal */ public bool $frozen = false;
    public readonly \Closure $handler;
    /** @internal */ public readonly ?string $pattern;
    public function __construct(public readonly string $method, public readonly string $path, callable $handler)
    {
        $this->handler = $handler(...);
        $names = []; $offset = 0; $pattern = '';
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $path, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as $i => [$token, $position]) {
            $name = $matches[1][$i][0];
            if (isset($names[$name])) throw new \ValueError('Duplicate route parameter');
            $names[$name] = true;
            $pattern .= preg_quote(substr($path, $offset, $position - $offset), '~') . '(?P<' . $name . '>[^/]+)';
            $offset = $position + strlen($token);
        }
        $this->pattern = $names ? '~\A' . $pattern . preg_quote(substr($path, $offset), '~') . '\z~D' : null;
    }
    public function middleware(string|callable $middleware): self
    {
        if ($this->frozen) throw new \LogicException('Routes cannot change while the server runs');
        $this->middleware[] = $middleware; return $this;
    }
}
