<?php
declare(strict_types=1);
[$script,$metadata,$destination]=$argv;
$data=json_decode(file_get_contents($metadata),true,flags:JSON_THROW_ON_ERROR);
$resolved=array_fill_keys(array_column($data['resolve']['nodes'],'id'),true);
$records=[];
foreach($data['packages'] as $p){
    if($p['name']==='asyncara-hyper-bridge'||!isset($resolved[$p['id']]))continue;
    $dir=dirname($p['manifest_path']);$files=[];
    foreach(['LICENSE*','COPYING*','NOTICE*']as$pattern)foreach(glob($dir.'/'.$pattern) as$file)if(is_file($file))$files[$file]=true;
    if($p['license_file']??null)$files[$dir.'/'.$p['license_file']]=true;
    if(!$files)throw new RuntimeException('Missing notice: '.$p['name']);
    $to=$destination.'/'.$p['name'].'-'.$p['version'];
    if(!mkdir($to,0777,true)&&!is_dir($to))throw new RuntimeException('Cannot create notices');
    foreach(array_keys($files) as$file){
        $real=realpath($file);
        if($real===false||!str_starts_with($real,realpath($dir).DIRECTORY_SEPARATOR)||!copy($real,$to.'/'.basename($real)))throw new RuntimeException('Cannot preserve notice: '.$file);
    }
    $records[]=['name'=>$p['name'],'version'=>$p['version'],'license'=>$p['license'],'repository'=>$p['repository']];
}
file_put_contents($destination.'/packages.json',json_encode($records,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
