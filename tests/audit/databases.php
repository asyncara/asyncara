<?php
declare(strict_types=1);
require (getenv('ASYNCARA_AUDIT_SOURCE') ?: dirname(__DIR__,2)).'/autoload.php';
use Asyncara\{Async,Future};
use Asyncara\SQLite\Connection as Sqlite;
use Asyncara\PostgreSQL\Connection as Pg;
use Asyncara\MySQL\Connection as Maria;
use Asyncara\Redis\Connection as Redis;
use Asyncara\Exception\CancelledException;
function requireTrue(bool $value,string $why):void { if(!$value)throw new RuntimeException($why); }
function responsive(Future $operation,string $name):mixed {
    $ticks=0;
    $ticker=Async::spawn(function()use($operation,&$ticks):void{while(!$operation->isCompleted()){Async::sleep(.005);if(!$operation->isCompleted())$ticks++;}});
    try{$result=$operation->await();}finally{$ticker->cancel();try{$ticker->await();}catch(CancelledException){}}
    requireTrue($ticks>=2,"$name did not allow timers while its operation was pending");
    echo json_encode(['driver'=>$name,'ticksWhilePending'=>$ticks]),"\n";return$result;
}
$pg=['host'=>getenv('ASYNCARA_PG_SOCKET'),'port'=>'55499','dbname'=>'postgres','sslmode'=>'disable'];
$my=['socket'=>getenv('ASYNCARA_MY_SOCKET'),'user'=>'root','database'=>'mysql'];
Async::run(function()use($pg,$my):void{
    foreach(['PostgreSQL'=>fn()=>Pg::connect($pg),'MariaDB'=>fn()=>Maria::connect($my)]as$name=>$connect){
        $db=$connect()->await();
        try{
            responsive($db->query($name==='PostgreSQL'?'select pg_sleep(.2)':'select sleep(.2)'),$name);
            $values=$db->transaction(fn($c)=>Async::gather([$c->query('select 1 as n'),$c->query('select 2 as n')]))->await();
            requireTrue($values[0][0]['n']==='1'&&$values[1][0]['n']==='2',"$name transaction ordering");
        }finally{$db->close();}
    }
    $r=Redis::connect('127.0.0.1',(int)getenv('ASYNCARA_REDIS_PORT'))->await();
    try{responsive($r->command(['BLPOP','audit:no-item',.2]),'Redis');}finally{$r->close();}
    $path=tempnam(sys_get_temp_dir(),'asyncara-audit-sqlite-');
    $a=Sqlite::open($path)->await();$b=Sqlite::open($path)->await();
    try{
        $a->query('create table t(n integer)')->await();$a->beginTransaction()->await();$a->query('insert into t values (1)')->await();
        $release=Async::spawn(function()use($a):void{Async::sleep(.2); /* owner task below commits */});
        $pending=$b->query('insert into t values (2)');$ticks=0;
        for($i=0;$i<10;$i++){Async::sleep(.01);if(!$pending->isCompleted())$ticks++;}
        requireTrue($ticks>=2,'SQLite busy wait blocked loop');$a->commit()->await();$pending->await();$release->await();
        requireTrue($a->query('select sum(n) as n from t')->await()[0]['n']===3,'SQLite transaction ordering');
        echo json_encode(['driver'=>'SQLite','ticksWhileContended'=>$ticks]),"\n";
    }finally{$a->close()->await();$b->close()->await();unlink($path);}
});
if(getenv('ASYNCARA_AUDIT_FATAL_PG')){
    $root=dirname(__DIR__,2);$audit=trim(file_get_contents($root.'/build/audit-path'));
    $cmd=[$root.'/.local/sysroot/usr/bin/valgrind','--extra-debuginfo-path='.$root.'/.local/debug','--keep-debuginfo=yes','--leak-check=full','--show-leak-kinds=definite','--errors-for-leak-kinds=definite','--error-exitcode=99','--log-file='.$audit.'/fatal-pg-valgrind.log',PHP_BINARY,'-n','-d','memory_limit=8M','-d','extension='.getenv('ASYNCARA_AUDIT_EXTENSION'),__DIR__.'/fatal_pg.php'];
    $env=getenv();$env['USE_ZEND_ALLOC']='1';$env['VALGRIND_LIB']=$root.'/.local/sysroot/usr/libexec/valgrind';
    $process=proc_open($cmd,[0=>['pipe','r'],1=>['file',$audit.'/fatal-pg-output.log','w'],2=>['file',$audit.'/fatal-pg-output.log','a']],$pipes,null,$env);fclose($pipes[0]);$status=proc_close($process);
    echo 'PostgreSQL fatal child exit: ',$status,"\n";
    requireTrue(str_contains(file_get_contents($audit.'/fatal-pg-output.log'),'Allowed memory size'),'Missing deliberate PHP memory exhaustion');
    requireTrue($status===255,'Expected PHP fatal shutdown with no Valgrind errors');
    foreach(['sqlite','mariadb']as$driver){
        $command=$cmd;
        $command[7]='--log-file='.$audit.'/fatal-'.$driver.'-valgrind.log';
        $command[count($command)-1]=__DIR__.'/fatal_database.php';$command[]=$driver;
        $output=$audit.'/fatal-'.$driver.'-output.log';
        $process=proc_open($command,[0=>['pipe','r'],1=>['file',$output,'w'],2=>['file',$output,'a']],$pipes,null,$env);fclose($pipes[0]);$status=proc_close($process);
        echo $driver,' fatal child exit: ',$status,"\n";
        requireTrue(str_contains(file_get_contents($output),'Allowed memory size'),'Missing '.$driver.' PHP memory exhaustion');
        requireTrue($status===255,'Expected '.$driver.' fatal shutdown with no Valgrind errors');
    }
}
echo "Independent database audit: PASS\n";
