<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Asyncara\HTTP\Redirect;
use Asyncara\Exception\OperationException;
$base='http://a/b/c/d;p?q';
$cases=['g'=>'http://a/b/c/g','./g'=>'http://a/b/c/g','g/'=>'http://a/b/c/g/','/g'=>'http://a/g','//g'=>'http://g/','?y'=>'http://a/b/c/d;p?y','g?y'=>'http://a/b/c/g?y','#s'=>'http://a/b/c/d;p?q','g#s'=>'http://a/b/c/g','g?y#s'=>'http://a/b/c/g?y',';x'=>'http://a/b/c/;x','g;x'=>'http://a/b/c/g;x','g;x?y#s'=>'http://a/b/c/g;x?y',''=>'http://a/b/c/d;p?q','.'=>'http://a/b/c/','./'=>'http://a/b/c/','..'=>'http://a/b/','../'=>'http://a/b/','../g'=>'http://a/b/g','../..'=>'http://a/','../../'=>'http://a/','../../g'=>'http://a/g','../../../g'=>'http://a/g','../../../../g'=>'http://a/g','/./g'=>'http://a/g','/../g'=>'http://a/g','g.'=>'http://a/b/c/g.','.g'=>'http://a/b/c/.g','g..'=>'http://a/b/c/g..','..g'=>'http://a/b/c/..g','./../g'=>'http://a/b/g','./g/.'=>'http://a/b/c/g/','g/./h'=>'http://a/b/c/g/h','g/../h'=>'http://a/b/c/h','g;x=1/./y'=>'http://a/b/c/g;x=1/y','g;x=1/../y'=>'http://a/b/c/y','g?y/./x'=>'http://a/b/c/g?y/./x','g?y/../x'=>'http://a/b/c/g?y/../x','g#s/../x'=>'http://a/b/c/g','/a//b/../'=>'http://a/a//','/a/%2e%2e/b'=>'http://a/a/%2e%2e/b','?'=>'http://a/b/c/d;p?'];
foreach($cases as$ref=>$expected){$got=Redirect::resolve($base,$ref);if($got!==$expected)throw new RuntimeException("URI $ref: $got != $expected");}
foreach(['file:///etc/passwd','g:h','http:g','//user:password@example.com','http://a:999999','/bad%ZZ',"/x\r\ny",'/space here','//a\\b','/b'.str_repeat('x',8192)]as$ref){try{Redirect::resolve($base,$ref);}catch(OperationException){continue;}throw new RuntimeException('Invalid URI accepted');}
if(Redirect::origin('HTTPS://Example.com/x')!==Redirect::origin('https://example.com:443/y'))throw new RuntimeException('Origin normalization');
echo json_encode(['status'=>'PASS','relativeReferences'=>count($cases),'invalidReferences'=>10]),"\n";
