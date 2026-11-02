<?php
declare(strict_types=1);
namespace Asyncara\PostgreSQL;
use Asyncara\{Async,Future,Scheduler,Task};
use Asyncara\Concurrency\Semaphore;
use Asyncara\IO\Dns;
use Asyncara\Exception\OperationException;
final class Connection
{
    private Semaphore $gate;
    private Semaphore $operations;
    private bool $closed = false;
    private array $notifications = [];
    private int $fd = -1;
    private ?Task $transactionOwner = null;
    private function __construct(private mixed $handle, private float $timeout) { $this->gate = new Semaphore(1); $this->operations = new Semaphore(1); }
    public static function connect(array $options, float $timeout = 30): Future
    {
        if (!function_exists('asyncara_pg_open')) throw new \LogicException('Build Asyncara with libpq support');
        return Async::spawn(static fn() => Async::timeout($timeout, static function () use ($options,$timeout): self {
            $host = $options['host'] ?? 'localhost';
            if (!str_starts_with($host,'/') && !isset($options['hostaddr'])) $options['hostaddr'] = filter_var($host,FILTER_VALIDATE_IP) ? $host : (Dns::resolve($host)->await()[0] ?? throw new OperationException('DNS returned no PostgreSQL address'));
            $options['host']=$host;
            $options=array_map(static fn($value)=>(string)$value,$options);
            $connection = new self(asyncara_pg_open($options),$timeout);
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
        $caller=Scheduler::current()->task;
        return Async::spawn(fn()=>Async::timeout($this->timeout,function()use($sql,$parameters,$caller):array {
            $owns=$this->transactionOwner!==null && $this->transactionOwner===$caller;
            if(!$owns)$this->gate->acquire();
            try { return $this->operations->withPermit(function () use ($sql,$parameters): array {
                $this->assertOpen();asyncara_pg_query($this->handle,$sql,$parameters);$rows=[];$bytes=0;
                do {
                    $state=asyncara_pg_read($this->handle);$this->fd=$state['fd'];
                    foreach($state['notifications']as$notification)$this->notifications[]=$notification;
                    if(isset($state['error']))throw new OperationException($state['error']);
                    foreach($state['rows']as$row){foreach($row as$value)$bytes+=strlen($value??'');if($bytes>16777216 || count($rows)>=100000)throw new OperationException('PostgreSQL buffered result limit exceeded');$rows[]=$row;}
                    if($state['write']||$state['read'])Scheduler::current()->waitDescriptor($this->fd,($state['read']?1:0)|($state['write']?2:0));
                    elseif($state['pending'])Async::sleep(0);
                }while($state['pending']);
                return $rows;
            }); }catch(\Throwable $e){$this->close();throw $e;}
            finally{if(!$owns)$this->gate->release();}
        }));
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
                while(!$this->notifications){Scheduler::current()->readable($this->fd);$state=asyncara_pg_read($this->handle);$this->notifications=$state['notifications'];}
                return array_shift($this->notifications);
            }catch(\Throwable $e){$this->close();throw $e;}
        });
    }
    private function assertOpen():void {if($this->closed)throw new \LogicException('PostgreSQL connection closed');}
    public function close():void {if(!$this->closed){$this->closed=true;if($this->fd>=0)Scheduler::tryCurrent()?->closed($this->fd);asyncara_pg_close($this->handle);}}
    public function __destruct(){$this->close();}
}
