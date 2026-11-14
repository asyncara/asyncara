<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$root=dirname(__DIR__).'/src/Asyncara/';$files=[];
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS))as$file)if($file->getExtension()==='php')$files[]=$file->getPathname();sort($files);
$text="# Public API reference\n\nGenerated from source signatures by `scripts/api-reference.php`. Usage and lifecycle semantics: https://asyncara.github.io/docs/guide/reference/overview/. Inherited methods are documented under their declaring class. Low-level `asyncara_*` functions are internal transport APIs.\n";
foreach($files as$file){
 $class='Asyncara\\'.str_replace('/','\\',substr($file,strlen($root),-4));
 if(!class_exists($class)&&!interface_exists($class))continue;
 $reflection=new ReflectionClass($class);
 if(str_contains($reflection->getDocComment()?:'','@internal'))continue;
 $text.="\n## `$class`\n\n";
 $lines=[];
 foreach($reflection->getMethods(ReflectionMethod::IS_PUBLIC)as$method){
  if($method->getDeclaringClass()->getName()!==$class||str_contains($method->getDocComment()?:'','@internal'))continue;
  $parameters=[];
  foreach($method->getParameters()as$p){
   $part=($p->hasType()?(string)$p->getType().' ':'').($p->isPassedByReference()?'&':'').($p->isVariadic()?'...':'').'$'.$p->getName();
   if($p->isDefaultValueAvailable()){$value=$p->getDefaultValue();$part.=' = '.(is_object($value)?'new '.get_class($value).'()':($value===[]?'[]':var_export($value,true)));}
   $parameters[]=$part;
  }
  $lines[]=($method->isStatic()?'static ':'').$method->getName().'('.implode(', ',$parameters).')'.($method->hasReturnType()?': '.$method->getReturnType():'');
 }
 if($lines)$text.="```php\n".implode("\n",$lines)."\n```\n";else$text.="Exception or interface type; inherited standard behavior.\n";
}
if (isset($argv[1])) {
 $output = fopen($argv[1], 'xb');
 if ($output === false) throw new RuntimeException('Use a new output path');
 try { fwrite($output, $text); } finally { fclose($output); }
} else { echo $text; }
