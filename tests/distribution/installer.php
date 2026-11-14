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
function downloadFixture(int $status,int $exit,callable $test):void {
 global $root;
 $dir=$root.'/download-'.bin2hex(random_bytes(4));mkdir($dir);
 $curl= <<<'SH'
#!/bin/sh
target=; fail=0
while [ "$#" -gt 0 ]; do
 case "$1" in --output) shift; target=$1;; --fail) fail=1;; esac
 shift
done
printf fixture > "$target"
printf '%s' "$FAKE_HTTP_STATUS"
if [ "$fail" = 1 ] && [ "$FAKE_HTTP_STATUS" -ge 400 ]; then exit 22; fi
exit "$FAKE_CURL_EXIT"
SH;
 file_put_contents($dir.'/curl',$curl);chmod($dir.'/curl',0700);
 file_put_contents($dir.'/gh',"#!/bin/sh\nprintf called > ".escapeshellarg($dir.'/gh-called')."\nexit 4\n");chmod($dir.'/gh',0700);
 $saved=[];foreach(['PATH','FAKE_HTTP_STATUS','FAKE_CURL_EXIT']as $key)$saved[$key]=getenv($key);
 putenv('PATH='.$dir);putenv('FAKE_HTTP_STATUS='.$status);putenv('FAKE_CURL_EXIT='.$exit);
 try{$test($dir);}finally{foreach($saved as $key=>$value)putenv($value===false?$key:$key.'='.$value);}
}
$id=['php'=>['version'=>'8.5.12','zts'=>false,'debug'=>false,'bits'=>64],'platform'=>['os'=>'ubuntu','osVersion'=>'24.04','arch'=>'x86_64']];
try {
 test('exact PHP/NTS selection',fn()=>eq(assetName('0.10.0',$id),'asyncara-0.10.0-php8.5.12-nts-ubuntu24.04-x86_64.tar.gz'));
 test('ZTS selects different asset',function()use($id){$id['php']['zts']=true;eq(str_contains(assetName('0.10.0',$id),'-zts-'),true);});
 test('debug selects different asset',function()use($id){$id['php']['debug']=true;eq(str_contains(assetName('0.10.0',$id),'-nts-debug-'),true);});
 foreach(['aarch64','arm','i686']as $arch)test('architecture '.$arch,function()use($id,$arch){$id['platform']['arch']=$arch;eq(str_ends_with(assetName('0.10.0',$id),'-'.$arch.'.tar.gz'),true);});
 foreach([
  ['8.4.11','ubuntu','25.10'],['8.5.12','kali','2025.2'],
 ]as [$php,$os,$version])test('missing '.$os.' ARM64 package never invokes gh',function()use($id,$php,$os,$version){
  $id['php']['version']=$php;$id['platform']=['os'=>$os,'osVersion'=>$version,'arch'=>'aarch64'];$name=assetName('0.10.0',$id);
  downloadFixture(404,0,function($dir)use($name){
   $error=null;try{download('https://github.com/asyncara/asyncara/releases/download/v0.10.0/'.$name,$dir.'/'.$name,$name);}catch(RuntimeException $e){$error=$e;}
   eq(file_exists($dir.'/gh-called'),false);eq(file_exists($dir.'/'.$name),false);
   eq($error!==null,true);eq(str_contains($error->getMessage(),'No prebuilt package'),true);eq(str_contains($error->getMessage(),$name),true);
  });
 });
 test('public download succeeds without gh',fn()=>downloadFixture(200,0,function($dir){
  download('https://example.invalid/package.tar.gz',$dir.'/package.tar.gz','package.tar.gz');
  eq(file_get_contents($dir.'/package.tar.gz'),'fixture');eq(file_exists($dir.'/gh-called'),false);
 }));
 test('missing checksum has a distinct error',fn()=>downloadFixture(404,0,function($dir){
  $error=null;try{download('https://example.invalid/package.sha256',$dir.'/package.sha256','package.sha256');}catch(RuntimeException $e){$error=$e;}
  eq($error!==null,true);eq(str_contains($error->getMessage(),'Checksum file is not published'),true);
  eq(file_exists($dir.'/package.sha256'),false);eq(file_exists($dir.'/gh-called'),false);
 }));
 foreach([[403,0],[500,0],[206,0],[0,6],[0,28],[0,60]]as [$status,$exit])test('HTTP/transport failure '.$status.'/'.$exit.' never invokes gh',fn()=>downloadFixture($status,$exit,function($dir)use($status,$exit){
  $error=null;try{download('https://example.invalid/package.tar.gz',$dir.'/package.tar.gz','package.tar.gz');}catch(RuntimeException $e){$error=$e;}
  eq($error!==null,true);eq(str_contains($error->getMessage(),$exit?'Check network access':'HTTP '.$status),true);
  eq(str_contains($error->getMessage(),'No prebuilt package'),false);eq(file_exists($dir.'/package.tar.gz'),false);eq(file_exists($dir.'/gh-called'),false);
 }));
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
