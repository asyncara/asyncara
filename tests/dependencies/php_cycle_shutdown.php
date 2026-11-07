<?php
declare(strict_types=1);
// Independent PHP control: no Asyncara loading, autoloader or native I/O.
$mode=$argv[1]??'plain';$collect=($argv[2]??'')==='collect';$refs=[];
for($i=0;$i<20;$i++){
    $create=static function()use(&$refs):void {
        $object=new stdClass();$object->self=$object;
        $object->closure=static fn()=>$object;
        $refs[]=WeakReference::create($object);
    };
    if($mode==='fiber'){$fiber=new Fiber($create);$fiber->start();unset($fiber);}
    else $create();
    unset($create);
}
$before=gc_status();$collected=$collect?gc_collect_cycles():null;$live=0;
foreach($refs as $ref)$live+=($ref->get()!==null);
echo json_encode(['php'=>PHP_VERSION,'debug'=>PHP_DEBUG,'mode'=>$mode,'explicitCollection'=>$collect,'gcBefore'=>$before,'collected'=>$collected,'liveCycles'=>$live,'gcAfter'=>gc_status()])."\n";
if($collect && $live!==0)exit(1);
