<?php
declare(strict_types=1);
namespace Asyncara\Redis;
use Asyncara\{Async,Future};
use Asyncara\IO\TcpStream;
use Asyncara\Concurrency\Semaphore;
use Asyncara\Exception\OperationException;
final class Connection
{
    use \Asyncara\Internal\NonCopyable;

    private string $buffer = '';
    private int $bytes = 0, $elements = 0;
    private Semaphore $gate;
    private bool $subscribed = false;
    private bool $closed = false;
    private array $notifications = [];
    private int $notificationBytes = 0;
    private function __construct(private TcpStream $stream, private float $timeout, private int $notificationLimit = 1024, private int $notificationByteLimit = 1048576) { $this->gate = new Semaphore(1); }
    public static function connect(string $host = '127.0.0.1', int $port = 6379, #[\SensitiveParameter] ?string $password = null, ?string $user = null, bool $resp3 = false, float $timeout = 30, bool $tls = false, ?string $caFile = null, int $notificationLimit = 1024, int $notificationByteLimit = 1048576): Future
    {
        if ($notificationLimit < 1 || $notificationLimit > 1024 || $notificationByteLimit < 1 || $notificationByteLimit > 1048576) throw new \ValueError('Redis notification limits must be 1..1024 messages and 1..1048576 wire bytes');
        if ($tls && $caFile !== null) $caFile = \asyncara_tls_ca_path($caFile);
        return Async::spawn(static function () use ($host,$port,$password,$user,$resp3,$timeout,$tls,$caFile,$notificationLimit,$notificationByteLimit): self {
            $connection = new self(TcpStream::connect($host,$port,$timeout,$tls,$caFile)->await(),$timeout,$notificationLimit,$notificationByteLimit);
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
            $prefix = '$' . strlen($arg) . "\r\n";
            if (strlen($arg) + strlen($data) + strlen($prefix) + 2 > 16777216) throw new \OverflowException('Redis request limit exceeded');
            $data .= $prefix . $arg . "\r\n";
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
        foreach ($commands as $command) { $encoded = self::encode($command); if (strlen($request) + strlen($encoded) > 16777216) throw new \OverflowException('Redis pipeline byte limit exceeded'); $request .= $encoded; }
        return Async::spawn(fn() => Async::timeout($this->timeout, fn() => $this->gate->withPermit(function () use ($request,$commands): array {
            try {
                $this->stream->write($request); $results = [];
                $this->bytes = $this->elements = 0;
                foreach ($commands as $_) {
                    do {
                        $before = $this->bytes; $push = false;
                        $reply = $this->parse(0,$push);
                        if ($push) $this->acceptNotification($reply,$this->bytes-$before);
                    } while ($push);
                    $results[] = $reply;
                }
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
            try {
                $this->stream->write(self::encode(['SUBSCRIBE', ...$channels]));
                foreach ($channels as $channel) {
                    $reply = $this->readReply(); if ($reply instanceof ServerException) throw $reply;
                    if (!is_array($reply) || !array_is_list($reply) || count($reply)!==3 || $reply[0]!=='subscribe' || $reply[1]!==(string)$channel || !is_int($reply[2]) || $reply[2]<1) throw new OperationException('Unexpected Redis subscription acknowledgement');
                }
                return $this;
            }
            catch (\Throwable $e) { $this->close(); throw $e; }
        })));
    }
    public function nextMessage(): array
    {
        if (!$this->subscribed) throw new \LogicException('Connection is not subscribed');
        return $this->gate->withPermit(function (): array {
            try { $reply = $this->readReply(); if (!is_array($reply) || !array_is_list($reply) || count($reply)!==3 || $reply[0]!=='message' || !is_string($reply[1]) || !is_string($reply[2])) throw new OperationException('Unexpected Redis Pub/Sub reply'); return $reply; }
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
        if ($end > 65536) throw new OperationException('Redis line limit exceeded');
        $line = substr($this->take($end+2),0,-2);
        if (strpbrk($line,"\r\n") !== false) throw new OperationException('Malformed Redis line');
        return $line;
    }
    private function readReply(): mixed { $this->bytes = $this->elements = 0; return $this->parse(0); }
    private static function integer(string $line): int
    {
        if (!preg_match('/^[+-]?[0-9]+$/D',$line)) throw new OperationException('Malformed Redis length/integer');
        $negative = $line[0] === '-';
        $digits = ltrim(ltrim($line,'+-'),'0');
        $limit = $negative ? substr((string)PHP_INT_MIN,1) : (string)PHP_INT_MAX;
        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits,$limit) > 0)) throw new OperationException('Redis integer outside platform range');
        return (int)$line;
    }
    private function parse(int $depth, ?bool &$push = null): mixed
    {
        if ($depth > 32 || ++$this->elements > 100000) throw new OperationException('Redis response nesting/element limit exceeded');
        $type = $this->take(1); $line = $this->line();
        $push = $type === '>';
        if ($push && $depth !== 0) throw new OperationException('Redis push cannot be nested in a reply');
        if ($type === '+') return $line;
        if ($type === '-') return new ServerException($line);
        if ($type === '_') { if ($line !== '') throw new OperationException('Malformed Redis null'); return null; }
        if ($type === '#') { if (!in_array($line,['t','f'],true)) throw new OperationException('Malformed Redis bool'); return $line === 't'; }
        if ($type === ',') {
            if (in_array($line,['inf','-inf','nan'],true)) return match($line) {'inf'=>INF,'-inf'=>-INF,'nan'=>NAN};
            if (!preg_match('/^[+-]?[0-9]+(?:\\.[0-9]+)?(?:[eE][+-]?[0-9]+)?$/D',$line) || !is_finite($value=(float)$line)) throw new OperationException('Malformed Redis double');
            return $value;
        }
        if ($type === '(') { if (!preg_match('/^[+-]?[0-9]+$/D',$line)) throw new OperationException('Malformed Redis bigint'); return $line; }
        $n = self::integer($line);
        if ($type === ':') return $n;
        if (in_array($type,['$','!','='],true)) {
            if ($n === -1 && $type === '$') return null;
            if ($n < 0 || $n > 16777216) throw new OperationException('Invalid Redis bulk length');
            $value = $this->take($n); if ($this->take(2) !== "\r\n") throw new OperationException('Malformed Redis terminator');
            if ($type === '=' && (strlen($value)<4 || $value[3]!==':')) throw new OperationException('Malformed Redis verbatim string');
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
    private function acceptNotification(array $notification, int $bytes): void
    {
        if (count($this->notifications) >= $this->notificationLimit || $bytes > $this->notificationByteLimit-$this->notificationBytes) throw new OperationException('Redis notification queue limit exceeded; connection closed');
        $this->notifications[] = $notification; $this->notificationBytes += $bytes;
    }
    /** Drain pushes already read with command replies. Does not read or wait for idle pushes. */
    public function drainNotifications(): array
    {
        $notifications = $this->notifications; $this->notifications = []; $this->notificationBytes = 0;
        return $notifications;
    }
    public function statistics(): array
    {
        return ['notifications'=>count($this->notifications),'notificationBytes'=>$this->notificationBytes,'notificationLimit'=>$this->notificationLimit,'notificationByteLimit'=>$this->notificationByteLimit,'closed'=>$this->closed];
    }
    public function close(): void
    {
        $this->closed = true; $this->notifications = []; $this->notificationBytes = 0; $this->buffer = '';
        $this->stream->close();
    }
}
