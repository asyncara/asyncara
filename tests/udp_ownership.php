<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';

use Asyncara\{Async, Future, Runtime};
use Asyncara\Exception\{CancelledException, OperationException};
use Asyncara\IO\UdpSocket;

function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function rejected(string $class, callable $body): Throwable {
    try { $body(); } catch (Throwable $error) {
        if ($error instanceof $class) return $error;
        throw $error;
    }
    throw new RuntimeException('Expected ' . $class);
}

Async::run(function (): void {
    Async::sleep(0); // Initialize the native wakeup descriptors before counting.
    $before = count(glob('/proc/self/fd/*'));
    foreach (['127.0.0.1', '::1'] as $address) {
        $receiver = new UdpSocket($address);
        $port = (int)substr(strrchr($receiver->address(), ':'), 1);
        try {
            // The pre-fix implementation accepts this duplicate binding. Linux
            // then delivers datagrams to the newer socket, starving the first.
            for ($i = 0; $i < 32; $i++) {
                $error = rejected(OperationException::class, fn() => new UdpSocket($address, $port));
                check($error->getCode() !== 0, 'Bind error must retain errno');
            }
            for ($i = 0; $i < 256; $i++) {
                $sender = new UdpSocket($address);
                try {
                    check($sender->address() !== $receiver->address(), 'Live UDP endpoints collided');
                    foreach (['', 'packet-' . $i] as $payload) {
                        Async::timeout(2, function () use ($sender, $receiver, $payload): void {
                            check($sender->send($payload, $receiver->address()) === strlen($payload), 'Send length');
                            $packet = $receiver->receive();
                            check($packet['data'] === $payload, 'Datagram content');
                            check($packet['peer'] === $sender->address(), 'Datagram source');
                        });
                    }
                } finally { $sender->close(); }
            }
            $waiting = Async::spawn($receiver->receive(...));
            Async::sleep(0);
            $waiting->cancel();
            rejected(CancelledException::class, $waiting->await(...));
        } finally { $receiver->close(); }
        $replacement = new UdpSocket($address, $port);
        $replacement->close();
    }
    check(count(glob('/proc/self/fd/*')) === $before, 'UDP success/failure descriptor leak');
});
if (function_exists('asyncara_udp_bind')) {
    $stream = asyncara_udp_bind('127.0.0.1', 0);
    check(stream_get_meta_data($stream)['blocked'] === false, 'Native UDP must be nonblocking');
    fclose($stream);
    foreach (['localhost', "127.0.0.1\0ignored", 'not-an-ip'] as $bad) {
        rejected(ValueError::class, fn() => asyncara_udp_bind($bad, 0));
    }
    foreach ([-1, 65536] as $bad) rejected(ValueError::class, fn() => asyncara_udp_bind('127.0.0.1', $bad));
}
gc_collect_cycles();
check(Future::pendingCount() === 0 && Runtime::information()->pendingOperations === 0, 'Pending UDP work');
echo "UDP exclusive binding, IPv4/IPv6 datagrams, zero-length, cancellation and cleanup: PASS\n";
