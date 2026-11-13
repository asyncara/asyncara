<?php
declare(strict_types=1);
define('ASYNCARA_INSTALLER_TEST',true);
require dirname(__DIR__,2).'/scripts/distribution/install-runtime.php';
$root=sys_get_temp_dir().'/asyncara-shell-unit-'.bin2hex(random_bytes(8));mkdir($root,0700);$count=0;
function test(string $name,callable $f):void{global $count;$f();$count++;echo "PASS $name\n";}
function eq(mixed $a,mixed $b):void{if($a!==$b)throw new RuntimeException(var_export([$a,$b],true));}
function rejects(callable $f):void{try{$f();}catch(Throwable $e){return;}throw new RuntimeException('Expected rejection');}
function tarFixture(array $entries,string $path):void{
 $tar='';foreach($entries as [$name,$data,$type]){
  $h=str_pad($name,100,"\0")."0000600\0"."0000000\0"."0000000\0".sprintf('%011o',strlen($data))."\0"."00000000000\0".str_repeat(' ',8).$type.str_repeat("\0",100)."ustar\0"."00".str_repeat("\0",247);
  eq(strlen($h),512);$h=substr_replace($h,sprintf('%06o',array_sum(unpack('C*',$h)))."\0 ",148,8);$tar.=$h.$data.str_repeat("\0",(512-strlen($data)%512)%512);
 }file_put_contents($path,gzencode($tar.str_repeat("\0",1024)));
}
function extracted(array $entries,callable $f):void{global $root;$path=$root.'/'.bin2hex(random_bytes(4));mkdir($path);tarFixture($entries,$path.'/a.gz');mkdir($path.'/files');$f($path.'/a.gz',$path.'/files');}
$id=['php'=>['version'=>'8.5.12','zts'=>false,'debug'=>false,'bits'=>64],'platform'=>['os'=>'ubuntu','osVersion'=>'24.04','arch'=>'x86_64']];
try {
 test('exact PHP/NTS selection',fn()=>eq(assetName('0.10.0',$id),'asyncara-0.10.0-php8.5.12-nts-ubuntu24.04-x86_64.tar.gz'));
 test('ZTS selects different asset',function()use($id){$id['php']['zts']=true;eq(str_contains(assetName('0.10.0',$id),'-zts-'),true);});
 test('debug selects different asset',function()use($id){$id['php']['debug']=true;eq(str_contains(assetName('0.10.0',$id),'-nts-debug-'),true);});
 foreach(['aarch64','arm','i686']as $arch)test('architecture '.$arch,function()use($id,$arch){$id['platform']['arch']=$arch;eq(str_ends_with(assetName('0.10.0',$id),'-'.$arch.'.tar.gz'),true);});
 test('ordinary tar member',fn()=>extracted([['asyncara/plain','hello','0']],fn($p,$d)=>eq(extractBundle($p,$d),['plain'=>true])));
 foreach(['../x','/absolute','a/../../x','a//x','a/./x',"a\nx",'a\\x']as $name)test('reject path '.json_encode($name),fn()=>extracted([['asyncara/'.$name,'x','0']],fn($p,$d)=>rejects(fn()=>extractBundle($p,$d))));
 foreach(['1','2','3','5','g']as $type)test('reject nonregular '.$type,fn()=>extracted([['asyncara/x','',''.$type]],fn($p,$d)=>rejects(fn()=>extractBundle($p,$d))));
 test('duplicate member',fn()=>extracted([['asyncara/x','1','0'],['asyncara/x','2','0']],fn($p,$d)=>rejects(fn()=>extractBundle($p,$d))));
 test('truncation',function()use($root){$p=$root.'/truncated';file_put_contents($p,gzencode('short'));rejects(fn()=>extractBundle($p,$root));});
 test('PAX traversal',function(){ $value='path=asyncara/../bad' . "\n";$n=strlen($value)+3;$record=$n.' '.$value;eq(strlen($record),$n);extracted([['pax',$record,'x'],['asyncara/x','x','0']],fn($p,$d)=>rejects(fn()=>extractBundle($p,$d)));});
 test('shell quoting and syntax',function()use($root){$p=$root.'/launcher';file_put_contents($p,launcher("/php path/'php","/prefix path/'name"));command(['/bin/sh','-n',$p]);});
 test('unrelated executable is not owned',function()use($root){file_put_contents($root.'/user','user');eq(ownedCommand($root.'/user',$root),false);});
 test('modified file hash refused',function()use($root,$id){
  $p=$root.'/manifest';mkdir($p);$files=[];foreach(['extension/asyncara.so','lib/libasyncara-mariadb.so.3','runtime/autoload.php','runtime/vendor/autoload.php','runtime/scripts/check-install.php','runtime/tests/release/smoke.php','runtime/LICENSE']as $n){ensureDir(dirname($p.'/'.$n));file_put_contents($p.'/'.$n,'fixture');$files[$n]=hash('sha256','fixture');}
  $m=['schema'=>1,'project'=>'asyncara/runtime','version'=>'0.10.0','sourceCommit'=>str_repeat('a',40),'php'=>$id['php'],'platform'=>$id['platform'],'files'=>$files];file_put_contents($p.'/manifest.json',json_encode($m));$names=array_fill_keys(array_merge(array_keys($files),['manifest.json']),true);
  eq(validateBundle($p,$names,'0.10.0',$id),$m);
  $z=$id;$z['php']['zts']=true;rejects(fn()=>validateBundle($p,$names,'0.10.0',$z));
  file_put_contents($p.'/runtime/LICENSE','changed');rejects(fn()=>validateBundle($p,$names,'0.10.0',$id));
 });
 echo json_encode(['status'=>'PASS','tests'=>$count,'php'=>PHP_VERSION],JSON_THROW_ON_ERROR),"\n";
}finally{removeOwned($root);}
