<?php
declare(strict_types=1);

// Test-only observation of the boundary before PHP's parser can resolve names
// or narrow a port to 16 bits. Unexpected targets never reach the real network.
namespace Asyncara\IO {
    final class UdpDestinationProbe {
        public static int $calls = 0;
        public static array $allowed = [];
    }
    function stream_socket_sendto(mixed $socket, string $data, int $flags, string $address): int|false {
        UdpDestinationProbe::$calls++;
        if (!in_array($address, UdpDestinationProbe::$allowed, true)) {
            throw new \RuntimeException('Malformed destination reached PHP transport');
        }
        return \stream_socket_sendto($socket, $data, $flags, $address);
    }
}

namespace {
    require dirname(__DIR__) . '/autoload.php';
    use Asyncara\{Async, Future, Runtime};
    use Asyncara\IO\{UdpSocket, UdpDestinationProbe};

    function check(bool $ok, string $why): void {
        if (!$ok) throw new RuntimeException($why);
    }
    Async::run(function (): void {
        foreach (['127.0.0.1', '::1'] as $ip) {
            $sender = new UdpSocket($ip);
            $receiver = new UdpSocket($ip);
            try {
                $port = (int)substr(strrchr($receiver->address(), ':'), 1);
                $host = $ip === '::1' ? '[::1]' : $ip;
                $invalid = [
                    $host . ':' . ($port + 65536), // Previously delivered to $port.
                    $host . ':0', $host . ':65536', $host . ':' . str_repeat('9', 200),
                    $host . ':0000001', $host . ':-1', $host . ':1e3',
                    $host . ":123\n", $host . ":123\0ignored", '',
                    '[deadbeef]:123', '[127.0.0.1]:123', '[:::1]:123',
                    '127.0.0.999:123', '127.1:123', '0177.0.0.1:123',
                    'localhost:123', '::1:123', str_repeat('a', 100) . ':123',
                ];
                $before = UdpDestinationProbe::$calls;
                foreach ($invalid as $destination) {
                    try { $sender->send('rejected', $destination); }
                    catch (ValueError) { continue; }
                    throw new RuntimeException('Invalid destination was accepted');
                }
                $oversizedRejected = false;
                try { $sender->send(str_repeat('x', 65508), $receiver->address()); }
                catch (ValueError) { $oversizedRejected = true; }
                check($oversizedRejected ?? false, 'Oversized datagram accepted');
                check(UdpDestinationProbe::$calls === $before, 'Invalid input reached PHP parser');
                $destinations = [$receiver->address(), $host . ':' . str_pad((string)$port, 5, '0', STR_PAD_LEFT)];
                UdpDestinationProbe::$allowed = $destinations;
                foreach ($destinations as $destination) {
                    foreach (['', str_repeat('p', 65507)] as $payload) {
                        Async::timeout(2, function () use ($sender, $receiver, $destination, $payload): void {
                            check($sender->send($payload, $destination) === strlen($payload), 'Send length');
                            check($receiver->receive()['data'] === $payload, 'Datagram body');
                        });
                    }
                }
                check(UdpDestinationProbe::$calls === $before + 4, 'Valid sends did not reach transport');
                UdpDestinationProbe::$allowed = [];
            } finally { $sender->close(); $receiver->close(); }
        }
    });
    gc_collect_cycles();
    check(Future::pendingCount() === 0 && Runtime::information()->pendingOperations === 0, 'Pending UDP work');
    echo "UDP destination validation before resolution/narrowing and real IPv4/IPv6 datagrams: PASS\n";
}
