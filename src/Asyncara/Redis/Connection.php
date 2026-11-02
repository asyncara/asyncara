<?php
declare(strict_types=1);
namespace Asyncara\Redis;
use Asyncara\{Async,Future};
use Asyncara\IO\TcpStream;
use Asyncara\Concurrency\Semaphore;
use Asyncara\Exception\OperationException;
final class Connection
{
    private string $buffer = '';
    private int $bytes = 0, $elements = 0;
    private Semaphore $gate;
    private bool $subscribed = false;
    private function __construct(private TcpStream $stream, private float $timeout) { $this->gate = new Semaphore(1); }
    public static function connect(string $host = '127.0.0.1', int $port = 6379, ?string $password = null, ?string $user = null, bool $resp3 = false, float $timeout = 30, bool $tls = false, ?string $caFile = null): Future
    {
        return Async::spawn(static function () use ($host,$port,$password,$user,$resp3,$timeout,$tls,$caFile): self {
            $connection = new self(TcpStream::connect($host,$port,$timeout,$tls,$caFile)->await(),$timeout);
            try {
                if ($password !== null) $connection->command($user !== null ? ['AUTH',$user,$password] : ['AUTH',$password])->await();
                if ($resp3) $connection->command(['HELLO',3])->await();
                return $connection;
            } catch (\Throwable $e) { $connection->close(); throw $e; }
        });
    }
    private static function encode(array $command): string
    {
        if (!$command || count($command) > 100000) throw new \ValueError('Invalid Redis command size');
        $data = '*' . count($command) . "\r\n";
        foreach ($command as $arg) {
            if (!is_string($arg) && !is_int($arg) && !is_float($arg)) throw new \TypeError('Redis arguments must be strings or numbers');
            $arg = (string)$arg;
            if (strlen($arg) + strlen($data) > 16777216) throw new \OverflowException('Redis request limit exceeded');
            $data .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
        }
        return $data;
    }
    public function command(array $command): Future
    {
        return Async::spawn(function () use ($command): mixed { $results = Async::await($this->pipeline([$command])); if ($results[0] instanceof ServerException) throw $results[0]; return $results[0]; });
    }
    /** Pipeline returns server errors as values so every response can be inspected. */
    public function pipeline(array $commands): Future
    {
        if ($this->subscribed) throw new \LogicException('Subscribed connections are dedicated to Pub/Sub');
        if (count($commands) > 10000) throw new \OverflowException('Redis pipeline limit exceeded');
        $request = '';
        foreach ($commands as $command) { $request .= self::encode($command); if (strlen($request) > 16777216) throw new \OverflowException('Redis pipeline byte limit exceeded'); }
        return Async::spawn(fn() => Async::timeout($this->timeout, fn() => $this->gate->withPermit(function () use ($request,$commands): array {
            try {
                $this->stream->write($request); $results = [];
                foreach ($commands as $_) $results[] = $this->readReply();
                return $results;
            } catch (\Throwable $e) { $this->close(); throw $e; }
        })));
    }
    public function transaction(array $commands): Future
    {
        return Async::spawn(function () use ($commands): mixed {
            $replies = Async::await($this->pipeline([['MULTI'], ...$commands, ['EXEC']]));
            foreach ($replies as $reply) if ($reply instanceof ServerException) throw $reply;
            return $replies[array_key_last($replies)];
        });
    }
    public function subscribe(array $channels): Future
    {
        if (!$channels || $this->subscribed) throw new \LogicException('Provide channels on an unused connection');
        $this->subscribed = true;
        return Async::spawn(fn() => Async::timeout($this->timeout, fn() => $this->gate->withPermit(function () use ($channels): self {
            try { $this->stream->write(self::encode(['SUBSCRIBE', ...$channels])); foreach ($channels as $_) { $reply = $this->readReply(); if ($reply instanceof ServerException) throw $reply; } return $this; }
            catch (\Throwable $e) { $this->close(); throw $e; }
        })));
    }
    public function nextMessage(): array
    {
        if (!$this->subscribed) throw new \LogicException('Connection is not subscribed');
        return $this->gate->withPermit(function (): array {
            try { $reply = $this->readReply(); if (!is_array($reply)) throw new OperationException('Unexpected Redis Pub/Sub reply'); return $reply; }
            catch (\Throwable $e) { $this->close(); throw $e; }
        });
    }
    private function take(int $n): string
    {
        if ($n < 0 || $this->bytes + $n > 16777216) throw new OperationException('Redis response byte limit exceeded');
        while (strlen($this->buffer) < $n) {
            $data = $this->stream->read(min(65536,max(1,$n-strlen($this->buffer))));
            if ($data === '') throw new OperationException('Redis connection closed'); $this->buffer .= $data;
        }
        $value = substr($this->buffer,0,$n); $this->buffer = substr($this->buffer,$n); $this->bytes += $n; return $value;
    }
    private function line(): string
    {
        while (($end = strpos($this->buffer,"\r\n")) === false) {
            if (strlen($this->buffer) > 65536) throw new OperationException('Redis line limit exceeded');
            $data = $this->stream->read(4096); if ($data === '') throw new OperationException('Redis connection closed'); $this->buffer .= $data;
        }
        return substr($this->take($end+2),0,-2);
    }
    private function readReply(): mixed { $this->bytes = $this->elements = 0; return $this->parse(0); }
    private function parse(int $depth): mixed
    {
        if ($depth > 32 || ++$this->elements > 100000) throw new OperationException('Redis response nesting/element limit exceeded');
        $type = $this->take(1); $line = $this->line();
        if ($type === '+') return $line;
        if ($type === '-') return new ServerException($line);
        if ($type === '_') { if ($line !== '') throw new OperationException('Malformed Redis null'); return null; }
        if ($type === '#') { if (!in_array($line,['t','f'],true)) throw new OperationException('Malformed Redis bool'); return $line === 't'; }
        if ($type === ',') return match($line) { 'inf' => INF, '-inf' => -INF, 'nan' => NAN, default => is_numeric($line) ? (float)$line : throw new OperationException('Malformed Redis double') };
        if ($type === '(') { if (!preg_match('/^-?[0-9]+$/D',$line)) throw new OperationException('Malformed Redis bigint'); return $line; }
        if (!preg_match('/^-?[0-9]+$/D',$line) || strlen($line)>19) throw new OperationException('Malformed Redis length/integer');
        $n = (int)$line;
        if ($type === ':') return $n;
        if (in_array($type,['$','!','='],true)) {
            if ($n === -1 && $type === '$') return null;
            if ($n < 0 || $n > 16777216) throw new OperationException('Invalid Redis bulk length');
            $value = $this->take($n); if ($this->take(2) !== "\r\n") throw new OperationException('Malformed Redis terminator');
            return $type === '!' ? new ServerException($value) : ($type === '=' ? substr($value,4) : $value);
        }
        if (in_array($type,['*','~','>','%'],true)) {
            if ($n === -1 && $type === '*') return null;
            if ($n < 0 || $n > 100000) throw new OperationException('Invalid Redis collection size');
            $values=[];
            for($i=0;$i<$n;$i++) {
                $value=$this->parse($depth+1);
                if($type==='%') { if(!is_string($value)&&!is_int($value))throw new OperationException('Non-scalar Redis map keys are not supported'); $values[$value]=$this->parse($depth+1); }
                else $values[]=$value;
            }
            return $values;
        }
        throw new OperationException('Unsupported RESP type');
    }
    public function close(): void { $this->stream->close(); }
}
