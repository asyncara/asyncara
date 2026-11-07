<?php
declare(strict_types=1);
namespace Asyncara\IO;
use Asyncara\{Async,Future,Scheduler};
use Asyncara\Exception\OperationException;
final class TcpStream
{
    use \Asyncara\Internal\NonCopyable;

    /** Weak registration prevents a second wrapper owning the same resource. */
    private static array $owners = [];
    private int $identity;
    private bool $reading = false, $writing = false;
    /** @param resource $stream */
    public function __construct(private mixed $stream)
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') throw new \ValueError('Expected stream resource');
        $id = (int)$stream;
        if (isset(self::$owners[$id]) && self::$owners[$id]->get() !== null) throw new \LogicException('Stream already has a canonical TcpStream owner');
        stream_set_blocking($stream, false); stream_set_read_buffer($stream, 0); stream_set_write_buffer($stream, 0);
        $this->identity = $id; self::$owners[$id] = \WeakReference::create($this);
    }
    public static function connect(string $host, int $port, float $timeout = 30, bool $tls = false, ?string $caFile = null): Future
    {
        if ($port < 1 || $port > 65535) throw new \ValueError('Invalid TCP port');
        return Async::spawn(static fn() => Async::timeout($timeout, static function () use ($host, $port, $tls, $caFile): self {
            $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : Dns::resolve($host)->await();
            $last = null;
            foreach ($addresses as $address) {
                $ip = str_contains($address, ':') ? "[$address]" : $address;
                $ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true, 'alpn_protocols' => 'http/1.1'];
                if ($caFile !== null) $ssl['cafile'] = $caFile;
                $context = stream_context_create(['ssl' => $ssl]);
                $resource = @stream_socket_client("tcp://$ip:$port", $errno, $error, 0, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT, $context);
                if (!$resource) { $last = new OperationException("TCP connect: $error", $errno); continue; }
                $stream = new self($resource);
                try {
                    Scheduler::current()->writable($resource);
                    if (stream_socket_get_name($resource, true) === false) throw new OperationException('TCP connection refused or failed');
                    if ($tls) {
                        while (true) {
                            $result = @stream_socket_enable_crypto($resource, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                            if ($result === true) break;
                            if ($result === false) throw new OperationException('TLS handshake or certificate verification failed');
                            Scheduler::current()->readable($resource);
                        }
                    }
                    return $stream;
                } catch (OperationException $e) { $stream->close(); $last = $e; }
                catch (\Throwable $e) { $stream->close(); throw $e; }
            }
            throw $last ?? new OperationException('DNS returned no usable addresses');
        }));
    }
    public function read(int $maxBytes = 65536): string
    {
        if ($maxBytes < 1 || $maxBytes > 16777216) throw new \ValueError('Invalid read size');
        if ($this->reading) throw new \LogicException('Concurrent reads on a stream are not allowed');
        $this->reading = true;
        try {
            while (true) {
                $stream = $this->resource(); Scheduler::current()->currentCancellation()?->throwIfRequested();
                $data = @fread($stream, $maxBytes);
                if ($data === false) throw new OperationException('TCP read failed');
                if (\Asyncara\Trace::$mask & \Asyncara\Trace::IO) \asyncara_trace_event(16,$data === '' ? 56 : 55,(int)$stream,$maxBytes,strlen($data),0);
                if ($data !== '' || feof($stream)) return $data;
                Scheduler::current()->readable($stream);
            }
        } finally { $this->reading = false; }
    }
    public function readExactly(int $bytes): string
    {
        if ($bytes < 0 || $bytes > 16777216) throw new \ValueError('Invalid exact read size');
        $result = '';
        while (strlen($result) < $bytes) { $part = $this->read($bytes - strlen($result)); if ($part === '') throw new OperationException('Unexpected EOF'); $result .= $part; }
        return $result;
    }
    public function write(string $data): void
    {
        if ($this->writing) throw new \LogicException('Concurrent writes on a stream are not allowed');
        $this->writing = true;
        try {
            $offset = 0;
            while ($offset < strlen($data)) {
                $stream = $this->resource(); Scheduler::current()->currentCancellation()?->throwIfRequested();
                $n = @fwrite($stream, substr($data, $offset, 65536));
                if ($n === false || ($n === 0 && feof($stream))) throw new OperationException('TCP write failed');
                if (\Asyncara\Trace::$mask & \Asyncara\Trace::IO) \asyncara_trace_event(16,55,(int)$stream,min(65536,strlen($data)-$offset),$n,1);
                $offset += $n;
                if ($offset < strlen($data)) Scheduler::current()->writable($stream);
            }
        } finally { $this->writing = false; }
    }
    public function resource(): mixed { return is_resource($this->stream) ? $this->stream : throw new OperationException('Stream is closed'); }
    public function close(): void
    {
        if (is_resource($this->stream)) { Scheduler::tryCurrent()?->closed($this->stream); fclose($this->stream); }
        if (isset($this->identity) && (self::$owners[$this->identity] ?? null)?->get() === $this) unset(self::$owners[$this->identity]);
    }
    public function __destruct() { $this->close(); }
}
