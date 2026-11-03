<?php
declare(strict_types=1);

use Asyncara\Async;
use Asyncara\Exception\CancelledException;
use Asyncara\HTTP\HttpClient;
use Asyncara\IO\{Dns,TcpStream,UdpSocket};
use Asyncara\WebSocket\Client;

/** Test-only peers; no changes to runtime code or capability reporting. */
final class MixedNetwork
{
    private array $clients = [];
    private HttpClient $http;
    private int $cycles = 0, $reconnections = 0, $cancellations = 0;
    private array $operations = [];

    private function stage(string $operation, string $stage): void
    {
        $this->operations[$operation] = ['stage' => $stage, 'atNs' => hrtime(true)];
    }

    public function __construct(private array $config)
    {
        $this->http = new HttpClient(4, timeout: 3, caFile: $config['ca']);
        try {
            foreach (['ws', 'wss'] as $scheme) $this->clients[$scheme] = $this->connect($scheme);
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    private function connect(string $scheme): Asyncara\WebSocket\Connection
    {
        $host = $scheme === 'wss' ? 'localhost' : '127.0.0.1';
        return Client::connect("$scheme://$host:".$this->config['websocket'][$scheme],
            caFile: $this->config['ca'], timeout: 3)->await();
    }

    public function cycle(): void
    {
        $this->operations = [];
        $started = hrtime(true);
        try { Async::timeout(5, function (): void {
            Async::taskGroup(function ($group): void {
                $group->spawn(function (): void {
                    $this->stage('dns', 'resolve');
                    $addresses = Dns::resolve('localhost')->await();
                    check(in_array('127.0.0.1', $addresses, true) && in_array('::1', $addresses, true), 'Dual-stack localhost DNS');
                    $this->stage('dns', 'done');
                });
                foreach (['127.0.0.1', '::1'] as $address) $group->spawn(function () use ($address): void {
                    $this->stage('udp:'.$address, 'bind');
                    $receiver = new UdpSocket($address);
                    $sender = null;
                    try {
                        $sender = new UdpSocket($address);
                        $this->stage('udp:'.$address, 'send');
                        check($sender->send('datagram', $receiver->address()) === 8, 'UDP send');
                        $this->stage('udp:'.$address, 'receive');
                        check($receiver->receive()['data'] === 'datagram', 'UDP receive');
                        $this->stage('udp:'.$address, 'done');
                    } finally { $sender?->close(); $receiver->close(); }
                });
                foreach (['tls12' => 'TLSv1.2', 'tls13' => 'TLSv1.3'] as $peer => $version) $group->spawn(function () use ($peer, $version): void {
                    $this->stage($peer, 'connect');
                    $stream = TcpStream::connect('localhost', $this->config['network'][$peer], timeout: 3,
                        tls: true, caFile: $this->config['ca'])->await();
                    try {
                        $this->stage($peer, 'write');
                        $stream->write("info\n");
                        $data = '';
                        $this->stage($peer, 'read');
                        while (!str_ends_with($data, "\n")) {
                            $chunk = $stream->read(1024);
                            check($chunk !== '' && strlen($data) + strlen($chunk) <= 4096, 'Bounded TLS peer response');
                            $data .= $chunk;
                        }
                        $info = json_decode($data, true, 16, JSON_THROW_ON_ERROR);
                        check($info['version'] === $version && $info['sni'] === 'localhost' && $info['alpn'] === 'http/1.1', 'TLS version/SNI/ALPN');
                        $this->stage($peer, 'done');
                    } finally { $stream->close(); }
                });
                $group->spawn(function (): void {
                    $this->stage('https', 'buffered');
                    $response = $this->http->get($this->config['https'])->await();
                    check($response->status === 200 && strlen($response->body) === 8192, 'Verified HTTPS response');
                    $stream = $this->http->getStream($this->config['https']);
                    try {
                        $this->stage('https', 'stream-headers');
                        check($stream->headers()->await()->status === 200, 'Verified HTTPS stream status');
                        $bytes = 0;
                        $this->stage('https', 'stream-body');
                        foreach ($stream as $chunk) { $bytes += strlen($chunk); Async::sleep(.001); }
                        check($bytes === 8192, 'Verified HTTPS stream body');
                        $this->stage('https', 'done');
                    } finally { $stream->close(); }
                });
                foreach ($this->clients as $scheme => $client) $group->spawn(function () use ($scheme, $client): void {
                    $payload = $scheme.':'.$this->cycles.':'.str_repeat('x', 4096);
                    $this->stage($scheme, 'send');
                    $client->send($payload);
                    $this->stage($scheme, 'receive');
                    check($client->receive()?->data === $payload, 'Persistent WebSocket echo');
                    if ($this->cycles % 10 === 0) { $this->stage($scheme, 'ping'); $client->ping()->await(); }
                    $this->stage($scheme, 'done');
                });
            });
            if (++$this->cycles % 100 === 0) {
                $scheme = $this->reconnections % 2 === 0 ? 'ws' : 'wss';
                $this->stage($scheme, 'close-for-reconnect');
                $this->clients[$scheme]->close();
                $this->stage($scheme, 'reconnect');
                $client = $this->clients[$scheme] = $this->connect($scheme);
                $this->reconnections++;
                $wait = Async::spawn($client->receive(...)); Async::sleep(0); $wait->cancel();
                $this->stage($scheme, 'cancel-receive');
                try { $wait->await(); throw new RuntimeException('WebSocket cancellation ignored'); }
                catch (CancelledException) { $this->cancellations++; }
                $client->send('after-cancellation');
                $this->stage($scheme, 'receive-after-cancellation');
                check($client->receive()?->data === 'after-cancellation', 'WebSocket reuse after cancellation');
                $this->stage($scheme, 'done');
            }
        }); } catch (Throwable $error) {
            // Preserve the originating exception and deadline. A cancellation
            // trace points to the timer callback, so retain bounded stage data.
            fwrite(STDERR, json_encode(['type' => 'network-failure', 'cycle' => $this->cycles,
                'elapsedNs' => hrtime(true) - $started, 'error' => get_class($error),
                'operations' => $this->operations,
                'connections' => array_map(fn($client) => $client->statistics(), $this->clients),
                'scheduler' => Asyncara\Runtime::diagnostics()], JSON_THROW_ON_ERROR)."\n");
            throw $error;
        }
        foreach ($this->clients as $client) check(!$client->statistics()['closed'] && $client->statistics()['queuedBytes'] === 0, 'WebSocket cycle boundary');
    }

    public function statistics(): array
    {
        return ['cycles' => $this->cycles, 'websocketConnections' => count($this->clients),
            'reconnections' => $this->reconnections, 'cancellations' => $this->cancellations,
            'dnsLookups' => $this->cycles, 'udpDatagrams' => 2 * $this->cycles,
            'verifiedTlsConnections' => 2 * $this->cycles, 'httpsResponses' => 2 * $this->cycles];
    }

    public function close(): void
    {
        try { foreach ($this->clients as $client) $client->close(); }
        finally { $this->clients = []; }
    }
}
