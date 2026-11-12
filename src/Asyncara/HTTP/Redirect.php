<?php
declare(strict_types=1);
namespace Asyncara\HTTP;
use Asyncara\Exception\OperationException;
/** @internal HTTP redirect policy and RFC 3986 relative-reference resolution. */
final class Redirect
{
    public const LIMIT = 5;
    public static function next(string $url, int $status, array $responseHeaders, int $hops, bool $identity): ?string
    {
        if (!in_array($status, [301,302,303,307,308], true) || !isset($responseHeaders['location'])) return null;
        $locations = $responseHeaders['location'];
        if (count($locations) !== 1) throw new OperationException('Ambiguous HTTP redirect Location');
        if ($hops >= self::LIMIT) throw new OperationException('HTTP redirect limit exceeded');
        $next = self::resolve($url, $locations[0]);
        if (parse_url($url, PHP_URL_SCHEME) === 'https' && parse_url($next, PHP_URL_SCHEME) !== 'https')
            throw new OperationException('HTTPS redirect downgrade rejected');
        if ($identity && self::origin($url) !== self::origin($next))
            throw new OperationException('Client TLS identity cannot follow a cross-origin redirect');
        return $next;
    }
    public static function headers(#[\SensitiveParameter] array $headers, bool $crossOrigin, bool $dropBody): array
    {
        $removed = ['host'];
        if ($crossOrigin) $removed = [...$removed, 'authorization', 'cookie', 'cookie2', 'origin', 'referer'];
        if ($dropBody) $removed = [...$removed, 'content-type', 'content-length', 'content-encoding', 'content-language', 'content-location', 'content-md5', 'digest', 'transfer-encoding', 'expect'];
        return array_values(array_filter($headers, static function ($line) use ($removed): bool {
            $name = strtolower(trim(explode(':', $line, 2)[0]));
            return !in_array($name, $removed, true);
        }));
    }
    public static function origin(string $url): string
    {
        $u = self::parts($url);$scheme = strtolower($u['scheme'] ?? '');
        return $scheme.'://'.strtolower($u['host'] ?? '').':'.($u['port'] ?? ($scheme === 'https' ? 443 : 80));
    }
    private static function parts(#[\SensitiveParameter] string $url): array
    {
        // Reject ambiguous or invalid wire targets before resolving or admitting
        // an operation. Never normalize a backslash into a different authority.
        if (strlen($url) > 8192 || preg_match('/[\x00-\x20\x7f-\xff\\\\]/', $url) || preg_match('/%(?![0-9A-Fa-f]{2})/', $url))
            throw new OperationException('Invalid HTTP redirect URI');
        try { $u = parse_url($url); } catch (\ValueError) { $u = false; }
        if ($u === false || isset($u['user']) || isset($u['pass'])) throw new OperationException('Invalid HTTP redirect authority');
        return $u;
    }
    public static function resolve(#[\SensitiveParameter] string $base, #[\SensitiveParameter] string $reference): string
    {
        $b = self::parts($base);$r = self::parts($reference);
        if (isset($r['scheme'])) $t = $r;
        elseif (isset($r['host'])) $t = $r + ['scheme' => $b['scheme']];
        else {
            $t = ['scheme' => $b['scheme'], 'host' => $b['host']];
            if (isset($b['port'])) $t['port'] = $b['port'];
            $path = $r['path'] ?? '';
            if ($path === '') {
                $t['path'] = $b['path'] ?? '';
                if (isset($r['query'])) $t['query'] = $r['query'];
                elseif (isset($b['query'])) $t['query'] = $b['query'];
            } else {
                $t['path'] = $path[0] === '/' ? $path : substr($b['path'] ?? '/', 0, (int)strrpos($b['path'] ?? '/', '/') + 1).$path;
                if (isset($r['query'])) $t['query'] = $r['query'];
            }
        }
        $scheme = strtolower($t['scheme'] ?? '');
        if (!in_array($scheme, ['http','https'], true) || empty($t['host'])) throw new OperationException('HTTP redirects require an HTTP(S) origin');
        $url = $scheme.'://'.$t['host'].(isset($t['port']) ? ':'.$t['port'] : '').self::path($t['path'] ?? '/');
        if (isset($t['query'])) $url .= '?'.$t['query'];
        if (strlen($url) > 8192) throw new OperationException('HTTP redirect URI limit exceeded');
        return $url;
    }
    private static function path(string $input): string
    {
        $output = '';
        while ($input !== '') {
            if (str_starts_with($input, '../')) $input = substr($input, 3);
            elseif (str_starts_with($input, './')) $input = substr($input, 2);
            elseif (str_starts_with($input, '/./')) $input = substr($input, 2);
            elseif ($input === '/.') $input = '/';
            elseif (str_starts_with($input, '/../') || $input === '/..') {
                $input = $input === '/..' ? '/' : substr($input, 3);
                $output = substr($output, 0, (int)strrpos($output, '/'));
            } elseif ($input === '.' || $input === '..') $input = '';
            else {
                $end = strpos($input, '/', $input[0] === '/' ? 1 : 0);
                if ($end === false) { $output .= $input; $input = ''; }
                else { $output .= substr($input, 0, $end); $input = substr($input, $end); }
            }
        }
        return $output === '' ? '/' : $output;
    }
}
