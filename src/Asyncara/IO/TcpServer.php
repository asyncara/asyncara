<?php
declare(strict_types=1);
namespace Asyncara\IO;
use Asyncara\Scheduler;
use Asyncara\Exception\OperationException;
final class TcpServer
{
    use \Asyncara\Internal\NonCopyable;

    private mixed $stream;
    public function __construct(string $address = '127.0.0.1', int $port = 0, int $backlog = 128)
    {
        if (!filter_var($address, FILTER_VALIDATE_IP) || $port < 0 || $port > 65535 || $backlog < 1) throw new \ValueError('Server requires a numeric IP, valid port and positive backlog');
        $ip = str_contains($address, ':') ? "[$address]" : $address;
        $this->stream = @stream_socket_server("tcp://$ip:$port", $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create(['socket' => ['backlog' => $backlog]]));
        if (!$this->stream) throw new OperationException("TCP listen: $error", $errno);
        stream_set_blocking($this->stream, false);
    }
    public function address(): string { return stream_socket_get_name($this->stream, false); }
    public function accept(): TcpStream
    {
        while (is_resource($this->stream)) {
            Scheduler::current()->currentCancellation()?->throwIfRequested();
            $client = @stream_socket_accept($this->stream, 0);
            if ($client) return new TcpStream($client);
            Scheduler::current()->readable($this->stream);
        }
        throw new OperationException('Server is closed');
    }
    public function close(): void { if (is_resource($this->stream)) { Scheduler::tryCurrent()?->closed($this->stream); fclose($this->stream); } }
    public function __destruct() { $this->close(); }
}
