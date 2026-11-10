<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\{Async,Scheduler,Runtime};
use Asyncara\Exception\OperationException;
function check(bool $v,string $why):void {if(!$v)throw new RuntimeException($why);}
$ca=file_get_contents($argv[1]);$url='https://127.0.0.1:'.$argv[2];
check(asyncara_info()['httpBackend']==='hyper-prototype','Expected Hyper');
Async::run(function()use($ca,$url):void{
 $submit=static fn($pem)=>Scheduler::current()->native('http',[$url,null,['caPem'=>$pem],1000]);
 foreach(['',str_repeat('x',1048577),"bad\0data",'not PEM',str_repeat($ca,513)]as$invalid){
  $before=asyncara_info()['hyperSubmitted'];
  try{$submit($invalid)->await();throw new RuntimeException('Invalid root bytes accepted');}catch(OperationException|ValueError){}
  check(asyncara_info()['hyperSubmitted']===$before,'Invalid trust admitted to connection');
 }
 for($i=0;$i<17;$i++){
  $before=asyncara_info()['hyperSubmitted'];
  try{$submit($ca."\n# policy $i\n")->await();throw new RuntimeException('Reserved refusal endpoint answered');}catch(OperationException){}
  check(asyncara_info()['hyperSubmitted']===$before+($i<16?1:0),'Trust-policy capacity not enforced');
 }
 $before=asyncara_info()['hyperSubmitted'];
 try{$submit($ca."\n# policy 0\n")->await();}catch(OperationException){}
 check(asyncara_info()['hyperSubmitted']===$before+1,'Existing policy rejected after capacity failure');
});
$n=asyncara_info();check($n['hyperSubmitted']===$n['hyperCompleted']&&!$n['pendingOperations']&&!$n['nativeMemoryBytes'],'Admission cleanup');
echo json_encode(['status'=>'PASS','policies'=>16,'rejectedNewPolicy'=>1,'native'=>$n,'runtime'=>Runtime::diagnostics()]),"\n";
