<?php
declare(strict_types=1);
namespace Asyncara\IO;
use Asyncara\Scheduler;
use Asyncara\Exception\OperationException;
final class UdpSocket
{
    use \Asyncara\Internal\NonCopyable;

    private mixed $stream;
    public function __construct(string $address = '127.0.0.1', int $port = 0)
    {
        if (!filter_var($address, FILTER_VALIDATE_IP) || $port < 0 || $port > 65535) throw new \ValueError('UDP bind requires a numeric IP and valid port');
        // Load error/cleanup dependencies while descriptors remain available.
        // A later EMFILE must not turn exception translation or close() into
        // a failed autoload that prevents releasing an owned socket.
        class_exists(OperationException::class);
        class_exists(Scheduler::class);
        try { $this->stream = \asyncara_udp_bind($address, $port); }
        catch (\Exception $error) { throw new OperationException($error->getMessage(), $error->getCode(), $error); }
    }
    public function address(): string { return stream_socket_get_name($this->stream, false); }
    public function send(string $data, string $address): int
    {
        // PHP's sendto parser resolves invalid numeric-looking hosts and narrows
        // ports to 16 bits. Validate before either that parser or a suspension.
        $ipv6 = str_starts_with($address, '[');
        $pattern = $ipv6 ? '/^\[([0-9a-fA-F:.]+)\]:([0-9]{1,5})$/D' : '/^([0-9.]+):([0-9]{1,5})$/D';
        if (strlen($data) > 65507 || strlen($address) > 53 || !preg_match($pattern, $address, $parts) ||
            !filter_var($parts[1], FILTER_VALIDATE_IP, $ipv6 ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4) ||
            (int)$parts[2] < 1 || (int)$parts[2] > 65535) {
            throw new \ValueError('UDP requires a numeric IP, destination port 1..65535 and bounded datagram');
        }
        while (true) {
            Scheduler::current()->writable($this->stream);
            $n = @stream_socket_sendto($this->stream, $data, 0, $address);
            if ($n === false) throw new OperationException('UDP send failed');
            if ($n !== strlen($data)) throw new OperationException('Partial datagram send');
            return $n;
        }
    }
    public function receive(): array
    {
        Scheduler::current()->readable($this->stream);
        $data = @stream_socket_recvfrom($this->stream, 65535, 0, $peer);
        if ($data === false) throw new OperationException('UDP receive failed');
        return ['data' => $data, 'peer' => $peer];
    }
    public function close(): void { if (is_resource($this->stream)) { Scheduler::tryCurrent()?->closed($this->stream); fclose($this->stream); } }
    public function __destruct() { $this->close(); }
}
