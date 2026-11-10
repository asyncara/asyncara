<?php
declare(strict_types=1);
namespace Asyncara\PostgreSQL;
use Asyncara\{Async,Future,Scheduler,Task};
use Asyncara\Concurrency\Semaphore;
use Asyncara\IO\Dns;
use Asyncara\Exception\OperationException;
final class Connection
{
    use \Asyncara\Internal\NonCopyable;

    private Semaphore $gate;
    private Semaphore $operations;
    private bool $closed = false;
    private array $notifications = [];
    private int $notificationBytes = 0;
    private int $fd = -1;
    private ?Task $transactionOwner = null;
    /** In insertion order from least to most recently used. Owned by this connection. */
    private array $prepared = [];
    private int $preparedBytes = 0;
    private int $preparedSequence = 0;
    private int $preparedHits = 0;
    private int $preparedMisses = 0;
    private int $preparedEvictions = 0;
    private function __construct(private mixed $handle, private float $timeout, private int $notificationLimit, private int $notificationByteLimit, private int $preparedCacheLimit, private int $preparedCacheByteLimit) { $this->gate = new Semaphore(1); $this->operations = new Semaphore(1); }
    public static function connect(array $options, float $timeout = 30, int $notificationLimit = 1024, int $notificationByteLimit = 1048576, int $preparedCacheLimit = 0, int $preparedCacheByteLimit = 262144): Future
    {
        if ($notificationLimit < 1 || $notificationLimit > 1024 || $notificationByteLimit < 1 || $notificationByteLimit > 1048576) throw new \ValueError('PostgreSQL notification limits must be 1..1024 messages and 1..1048576 payload bytes');
        if ($preparedCacheLimit < 0 || $preparedCacheLimit > 256 || $preparedCacheByteLimit < 1 || $preparedCacheByteLimit > 1048576) throw new \ValueError('PostgreSQL prepared cache limits must be 0..256 entries and 1..1048576 SQL bytes');
        if (!function_exists('asyncara_pg_open')) throw new \LogicException('Build Asyncara with libpq support');
        if ($preparedCacheLimit && !function_exists('asyncara_pg_prepare')) throw new \LogicException('Rebuild Asyncara with prepared PostgreSQL support');
        return Async::spawn(static fn() => Async::timeout($timeout, static function () use ($options,$timeout,$notificationLimit,$notificationByteLimit,$preparedCacheLimit,$preparedCacheByteLimit): self {
            $host = $options['host'] ?? 'localhost';
            if (!str_starts_with($host,'/') && !isset($options['hostaddr'])) $options['hostaddr'] = filter_var($host,FILTER_VALIDATE_IP) ? $host : (Dns::resolve($host)->await()[0] ?? throw new OperationException('DNS returned no PostgreSQL address'));
            $options['host']=$host;
            $options=array_map(static fn($value)=>(string)$value,$options);
            $connection = new self(asyncara_pg_open($options),$timeout,$notificationLimit,$notificationByteLimit,$preparedCacheLimit,$preparedCacheByteLimit);
            try {
                while(true) {
                    $result=asyncara_pg_poll($connection->handle);$connection->fd=$result['fd'];
                    if($result['status']===3)return $connection;
                    if($result['status']===1)Scheduler::current()->readable($connection->fd);
                    elseif($result['status']===2)Scheduler::current()->writable($connection->fd);
                    else Async::sleep(0);
                }
            } catch(\Throwable $e) { $connection->close();throw $e; }
        }));
    }
    public function query(string $sql,array $parameters=[]): Future
    {
        return $this->submitQuery($sql, $parameters, false);
    }
    /** @internal Statement execution; retains the connection's serialization/transaction gates. */
    public function executePrepared(string $sql, array $parameters = []): Future
    {
        return $this->submitQuery($sql, $parameters, true);
    }
    private function submitQuery(string $sql, array $parameters, bool $prepared): Future
    {
        $caller=Scheduler::current()->task;
        return Async::spawn(fn()=>Async::timeout($this->timeout,function()use($sql,$parameters,$caller,$prepared):array {
            $owns=$this->transactionOwner!==null && $this->transactionOwner===$caller;
            if(!$owns)$this->gate->acquire();
            try { return $this->operations->withPermit(function () use ($sql,$parameters,$prepared): array {
                $this->assertOpen();
                $name = $prepared ? $this->preparedName($sql, count($parameters)) : null;
                if ($name === null) asyncara_pg_query($this->handle,$sql,$parameters);
                else asyncara_pg_execute($this->handle,$name,$parameters);
                return $this->collectResult();
            }); }catch(\Throwable $e){$this->close();throw $e;}
            finally{if(!$owns)$this->gate->release();}
        }));
    }
    /** Called only while the operation gate is held. Every protocol result is drained. */
    private function preparedName(string $sql, int $parameters): ?string
    {
        $bytes = strlen($sql);
        if (!$this->preparedCacheLimit || $bytes > $this->preparedCacheByteLimit) return null;
        // Hash avoids a second full SQL copy in the array key; equality is still checked.
        $key = $parameters.':'.hash('sha256', $sql);
        if (isset($this->prepared[$key])) {
            $entry = $this->prepared[$key];
            if ($entry['sql'] !== $sql) throw new OperationException('PostgreSQL prepared cache identity mismatch');
            unset($this->prepared[$key]);
            $this->prepared[$key] = $entry;
            $this->preparedHits++;
            return $entry['name'];
        }
        while (count($this->prepared) >= $this->preparedCacheLimit || $bytes > $this->preparedCacheByteLimit - $this->preparedBytes) {
            $oldest = array_key_first($this->prepared);
            $entry = $this->prepared[$oldest];
            // Names are generated here from a fixed ASCII prefix and integer only.
            asyncara_pg_query($this->handle, 'DEALLOCATE '.$entry['name'], []);
            $this->collectResult();
            unset($this->prepared[$oldest]);
            $this->preparedBytes -= strlen($entry['sql']);
            $this->preparedEvictions++;
        }
        if ($this->preparedSequence === PHP_INT_MAX) throw new OperationException('PostgreSQL prepared statement identity exhausted');
        $name = 'asyncara_ps_'.(++$this->preparedSequence);
        asyncara_pg_prepare($this->handle, $name, $sql, $parameters);
        $this->collectResult();
        $this->prepared[$key] = ['sql'=>$sql, 'name'=>$name];
        $this->preparedBytes += $bytes;
        $this->preparedMisses++;
        return $name;
    }
    private function collectResult(): array
    {
        $rows=[];$bytes=0;
        do {
            $state=asyncara_pg_read($this->handle);$this->fd=$state['fd'];
            $this->acceptNotifications($state['notifications']);
            if(isset($state['error']))throw new OperationException($state['error']);
            foreach($state['rows']as$row){$bytes+=32;foreach($row as$key=>$value)$bytes+=64+strlen((string)$key)+strlen($value??'');if($bytes>16777216 || count($rows)>=100000)throw new OperationException('PostgreSQL buffered result limit exceeded');$rows[]=$row;}
            if($state['write']||$state['read'])Scheduler::current()->waitDescriptor($this->fd,($state['read']?1:0)|($state['write']?2:0));
            elseif($state['pending'])Async::sleep(0);
        }while($state['pending']);
        return $rows;
    }
    public function execute(string $sql,array $parameters=[]): Future { return $this->query($sql,$parameters); }
    public function prepare(string $sql): Statement { return new Statement($this,$sql); }
    public function transaction(callable $body): Future
    {
        return Async::spawn(function()use($body):mixed {
            $this->gate->acquire();$this->transactionOwner=Scheduler::current()->task;
            try {
                $this->query('BEGIN')->await();
                try { $value=$body($this);Async::shield(fn()=>$this->query('COMMIT')->await());return $value; }
                catch(\Throwable $e){if(!$this->closed)Async::shield(fn()=>$this->query('ROLLBACK')->await());throw $e;}
            }finally{$this->transactionOwner=null;$this->gate->release();}
        });
    }
    public function nextNotification(): array
    {
        return $this->gate->withPermit(function():array {
            $this->assertOpen();
            try {
                while(!$this->notifications){
                    // Consume already buffered libpq messages before waiting for new bytes.
                    $state=asyncara_pg_read($this->handle);$this->acceptNotifications($state['notifications']);
                    if(!$this->notifications)Scheduler::current()->readable($this->fd);
                }
                $notification=array_shift($this->notifications);
                $this->notificationBytes-=strlen($notification['channel'])+strlen($notification['payload']);
                return $notification;
            }catch(\Throwable $e){$this->close();throw $e;}
        });
    }
    private function acceptNotifications(array $notifications): void
    {
        foreach($notifications as $notification){
            $bytes=strlen($notification['channel'])+strlen($notification['payload']);
            if(count($this->notifications)>=$this->notificationLimit || $bytes>$this->notificationByteLimit-$this->notificationBytes) throw new OperationException('PostgreSQL notification queue limit exceeded; connection closed');
            $this->notifications[]=$notification;$this->notificationBytes+=$bytes;
        }
    }
    public function statistics(): array { return ['notifications'=>count($this->notifications),'notificationBytes'=>$this->notificationBytes,'notificationLimit'=>$this->notificationLimit,'notificationByteLimit'=>$this->notificationByteLimit,'closed'=>$this->closed,
        'preparedStatements'=>count($this->prepared),'preparedBytes'=>$this->preparedBytes,
        'preparedCacheLimit'=>$this->preparedCacheLimit,'preparedCacheByteLimit'=>$this->preparedCacheByteLimit,
        'preparedHits'=>$this->preparedHits,'preparedMisses'=>$this->preparedMisses,'preparedEvictions'=>$this->preparedEvictions]; }
    private function assertOpen():void {if($this->closed)throw new \LogicException('PostgreSQL connection closed');}
    public function close():void {if(!$this->closed){$this->closed=true;$this->notifications=[];$this->notificationBytes=0;$this->prepared=[];$this->preparedBytes=0;if($this->fd>=0)Scheduler::tryCurrent()?->closed($this->fd);asyncara_pg_close($this->handle);}}
    public function __destruct(){$this->close();}
}
