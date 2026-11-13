<?php
declare(strict_types=1);
// Actual upstream PIE classes; this is a contract probe, NOT a published install.
// Usage: php pie_contract.php UPSTREAM_SOURCE NEW_OUTPUT PHP_BINARY REAL_MODULE
[$self,$upstream,$out,$php,$module]=$argv;
require $upstream.'/vendor/autoload.php';
use Composer\IO\BufferIO;
use Composer\Package\Loader\ArrayLoader;
use Php\Pie\DependencyResolver\Package;
use Php\Pie\Downloading\{DownloadedPackage,DownloadUrlMethod};
use Php\Pie\Building\UnixBuild;
use Php\Pie\Installing\{UnixInstall,SetupIniFile};
use Php\Pie\Installing\Ini\SetupIniApproach;
use Php\Pie\Platform\{TargetPlatform,PrePackagedBinaryAssetName};
use Php\Pie\Platform\TargetPhp\PhpBinaryPath;
function check(bool $test,string $message):void{if(!$test)throw new RuntimeException($message);}
check(!file_exists($out),'Use new output');mkdir($out,0700,true);$out=realpath($out);
mkdir($out.'/extensions');mkdir($out.'/package');mkdir($out.'/package/runtime');mkdir($out.'/package/lib');
$wrapper=$out.'/php';file_put_contents($wrapper,"#!/bin/sh\nexec ".escapeshellarg($php)." -n -d ".escapeshellarg('extension_dir='.$out.'/extensions')." \"\$@\"\n");chmod($wrapper,0755);
$identity=TargetPlatform::fromPhpBinaryPath(PhpBinaryPath::fromPhpBinaryPath($wrapper),1,null);
$meta=json_decode(file_get_contents(dirname(__DIR__,2).'/packaging/pie/composer.json'),true,512,JSON_THROW_ON_ERROR);
$meta['version']='0.10.0';$composer=(new ArrayLoader())->load($meta);
DownloadUrlMethod::PrePackagedBinary->writeToComposerPackage($composer);
$package=Package::fromComposerCompletePackage($composer);
check($package->name()==='asyncara/asyncara','Exact PIE package name');
check($package->supportedDownloadUrlMethods()===[DownloadUrlMethod::PrePackagedBinary],'Source fallback must be absent');
check($package->supportNts()&&!$package->supportZts(),'Do not invent ZTS certification');
$names=PrePackagedBinaryAssetName::packageNames($identity,$package);
copy($module,$out.'/package/asyncara.so');
file_put_contents($out.'/package/runtime/marker','userland files must survive for a full installation');
file_put_contents($out.'/package/lib/marker','private shared dependencies must survive');
file_put_contents($out.'/package/asyncara.so.sha256',str_repeat('0',64)."  asyncara.so\n");
$download=DownloadedPackage::fromPackageAndExtractedPath($package,$out.'/package');$io=new BufferIO();
$binary=(new UnixBuild())($download,$identity,[],$io);
$ini=new class implements SetupIniApproach {
 public function canBeUsed(TargetPlatform $p):bool{throw new RuntimeException('Must not inspect global ini');}
 public function setup(TargetPlatform $p,DownloadedPackage $d,\Php\Pie\File\BinaryFile $b,\Composer\IO\IOInterface $i):bool{throw new RuntimeException('Must not edit ini');}
};
$installed=(new UnixInstall(new SetupIniFile($ini)))($download,$identity,$binary,$io,false);
check(is_file($out.'/extensions/asyncara.so'),'PIE installs the extension');
check(hash_file('sha256',$out.'/extensions/asyncara.so')===hash_file('sha256',$module),'Copied actual module unchanged');
check(!file_exists($out.'/extensions/runtime')&&!file_exists($out.'/extensions/lib'),'Ancillary runtime/library files are not installed');
$log=$io->getOutput();check(!str_contains($log,'Configure complete')&&!str_contains($log,'phpize complete'),'No source compilation');
file_put_contents($out.'/upstream.log',$log);
$result=['status'=>'PASS','scope'=>'Upstream PIE 1.5.5 contract probe; not end-to-end publication or full Asyncara installation',
 'package'=>'asyncara/asyncara','sourceFallback'=>false,'assetNames'=>$names,
 'extensionCopied'=>true,'ancillaryFilesCopied'=>false,'suppliedWrongSha256SidecarRejected'=>false,
 'iniModified'=>false,'iniNote'=>'Probe explicitly disables ini setup; the ordinary PIE command may change PHP configuration',
 'moduleSha256'=>hash_file('sha256',$module),'upstreamSources'=>[]];
foreach(['src/Installing/UnixInstall.php','src/ComposerIntegration/VendorCleanup.php','src/ComposerIntegration/Listeners/OverrideDownloadUrlInstallListener.php']as $path)$result['upstreamSources'][$path]=hash_file('sha256',$upstream.'/'.$path);
file_put_contents($out.'/report.json',json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");echo json_encode($result,JSON_THROW_ON_ERROR),"\n";
