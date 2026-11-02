<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
require __DIR__.'/resp_harness.php';
use Asyncara\Async;
use Asyncara\Exception\OperationException;
$cases=json_decode(file_get_contents(__DIR__.'/corpus/resp/manifest.json'),true,512,JSON_THROW_ON_ERROR);
$failures=[];$executions=0;
Async::run(function()use($cases,&$failures,&$executions):void{
    foreach($cases as$name=>$case){
        $wire=file_get_contents(__DIR__.'/corpus/resp/'.$name.'.resp');
        foreach(array_unique([0,1,intdiv(strlen($wire),2),strlen($wire)])as$prefix){
            $executions++;
            try{
                $value=parseResp($wire,$prefix);
                if(isset($case['error']))throw new RuntimeException('Malformed input accepted');
                if($value!==$case['value'])throw new RuntimeException('Wrong value: '.var_export($value,true));
            }catch(OperationException $e){if(!isset($case['error']))$failures[]="$name/$prefix: ".$e->getMessage();}
            catch(Throwable $e){$failures[]="$name/$prefix: ".$e->getMessage();}
        }
    }
});
echo json_encode(['executions'=>$executions,'failures'=>$failures],JSON_PRETTY_PRINT),"\n";
exit($failures?1:0);
