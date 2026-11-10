<?php
declare(strict_types=1);
namespace Asyncara\MySQL;
use Asyncara\{Async,Future,Scheduler,Task};
use Asyncara\Concurrency\Semaphore;
use Asyncara\IO\Dns;
use Asyncara\Exception\OperationException;
final class Connection
{
    use \Asyncara\Internal\NonCopyable;

    private Semaphore $gate;
    private Semaphore $operations;
    private bool $closed=false;
    private int $fd=-1;
    private ?Task $transactionOwner=null;
    private function __construct(private mixed $handle,private float $timeout){$this->gate=new Semaphore(1);$this->operations=new Semaphore(1);}
    public static function connect(#[\SensitiveParameter] array $options,float $timeout=30):Future
    {
        if(!function_exists('asyncara_mysql_open'))throw new \LogicException('Build Asyncara with MariaDB Connector/C');
        return Async::spawn(static fn()=>Async::timeout($timeout,static function()use($options,$timeout):self {
            if(!isset($options['socket'])){
                $host=$options['host']??'127.0.0.1';
                if(!filter_var($host,FILTER_VALIDATE_IP)){
                    // Connector/C verifies the supplied host. Resolving to an IP would lose hostname validation.
                    if($options['tls']??true)throw new \ValueError('TLS MariaDB connections currently require a numeric IP with a matching certificate SAN');
                    $host=Dns::resolve($host)->await()[0]??throw new OperationException('DNS returned no MariaDB address');
                }
                $options['host']=$host;
            }
            $connection=new self(asyncara_mysql_open($options),$timeout);
            try{$connection->drain();return $connection;}catch(\Throwable $e){$connection->close();throw $e;}
        }));
    }
    private function drain():array
    {
        $events=0;$rows=[];$bytes=0;
        do{
            $state=asyncara_mysql_step($this->handle,$events);$this->fd=$state['fd'];
            foreach($state['rows']as$row){foreach($row as$value)$bytes+=strlen($value??'');if($bytes>16777216||count($rows)>=100000)throw new OperationException('MariaDB result limit exceeded');$rows[]=$row;}
            if($state['pending']){
                if($state['events'])$events=Scheduler::current()->waitDescriptor($this->fd,$state['events'],($state['events']&8)?max(.001,$state['timeoutMs']/1000):null);
                else{Async::sleep(0);$events=0;}
            }
        }while($state['pending']);
        return $rows;
    }
    public function query(string $sql,array $parameters=[]):Future
    {
        $caller=Scheduler::current()->task;
        return Async::spawn(fn()=>Async::timeout($this->timeout,function()use($sql,$parameters,$caller):array {
            $owns=$this->transactionOwner!==null&&$this->transactionOwner===$caller;
            if(!$owns)$this->gate->acquire();
            try{return $this->operations->withPermit(function()use($sql,$parameters):array{if($this->closed)throw new \LogicException('MariaDB connection closed');asyncara_mysql_query($this->handle,$sql,$parameters);return $this->drain();});}
            catch(\Throwable $e){$this->close();throw $e;}
            finally{if(!$owns)$this->gate->release();}
        }));
    }
    public function execute(string $sql,array $parameters=[]):Future{return $this->query($sql,$parameters);}
    public function prepare(string $sql):Statement{return new Statement($this,$sql);}
    public function transaction(callable $body):Future
    {
        return Async::spawn(function()use($body):mixed {
            $this->gate->acquire();$this->transactionOwner=Scheduler::current()->task;
            try{
                $this->query('START TRANSACTION')->await();
                try{$value=$body($this);Async::shield(fn()=>$this->query('COMMIT')->await());return $value;}
                catch(\Throwable $e){if(!$this->closed)Async::shield(fn()=>$this->query('ROLLBACK')->await());throw $e;}
            }finally{$this->transactionOwner=null;$this->gate->release();}
        });
    }
    public function close():void{if(!$this->closed){$this->closed=true;if($this->fd>=0)Scheduler::tryCurrent()?->closed($this->fd);asyncara_mysql_close($this->handle);}}
    public function __destruct(){$this->close();}
}
