<?php
declare(strict_types=1);
// Embedded into install.sh by render-installer.php. Uses the target PHP itself.
const MAX_ARCHIVE = 134217728;
const MAX_CONTENT = 268435456;
const MAX_FILES = 6000;
const REPOSITORY = 'asyncara/asyncara';
function fail(string $message): never { throw new RuntimeException($message); }
function executable(string $name): ?string {
    if (str_contains($name, '/')) return is_executable($name) ? realpath($name) : null;
    foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $dir) {
        if ($dir !== '' && is_file($dir.'/'.$name) && is_executable($dir.'/'.$name)) return realpath($dir.'/'.$name);
    }
    return null;
}
function command(array $argv, int $timeout = 60): string {
    $p = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    if (!is_resource($p)) fail('Cannot start '.basename($argv[0]));
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $output = ''; $start = hrtime(true); $exit = -1;
    try {
        do {
            foreach ([1,2] as $fd) $output .= stream_get_contents($pipes[$fd],65536);
            if (strlen($output) > 1048576) fail('Installation probe output exceeds limit');
            $state = proc_get_status($p);
            if (!$state['running']) { $exit = $state['exitcode']; break; }
            if ((hrtime(true)-$start)/1e9 > $timeout) fail('Command timed out: '.basename($argv[0]));
            usleep(10000);
        } while (true);
        foreach ([1,2] as $fd) $output .= stream_get_contents($pipes[$fd],65536);
    } finally {
        if (proc_get_status($p)['running']) { proc_terminate($p); usleep(20000); if (proc_get_status($p)['running']) proc_terminate($p,9); }
        fclose($pipes[1]); fclose($pipes[2]); $closed = proc_close($p);
    }
    if ($exit < 0) $exit = $closed;
    if ($exit !== 0) fail(basename($argv[0]).' failed ('.$exit.'): '.substr($output,-4000));
    return $output;
}
function identity(): array {
    $arch = match (strtolower(php_uname('m'))) {
        'x86_64','amd64','x64' => PHP_INT_SIZE === 4 ? 'i686' : 'x86_64',
        'aarch64','arm64' => PHP_INT_SIZE === 4 ? 'arm' : 'aarch64',
        'i386','i486','i586','i686','x86','386' => 'i686',
        'arm','armv7','armv7l','armv8l' => 'arm',
        default => strtolower(php_uname('m')),
    };
    $os = strtolower(PHP_OS_FAMILY); $version = php_uname('r');
    if (PHP_OS_FAMILY === 'Linux') {
        $release = is_readable('/etc/os-release') ? parse_ini_file('/etc/os-release', false, INI_SCANNER_RAW) : [];
        if (isset($release['ID'], $release['VERSION_ID']) && !in_array($release['ID'], ['android','termux'], true)) {
            $os = $release['ID']; $version = $release['VERSION_ID'];
        } elseif (getenv('TERMUX_VERSION') || getenv('ANDROID_ROOT')) {
            $os = 'android'; $getprop = executable('getprop');
            $version = $getprop ? trim(command([$getprop,'ro.build.version.sdk'])) : 'unknown';
        }
    } elseif (PHP_OS_FAMILY === 'Darwin') {
        $os = 'macos'; $version = trim(command(['/usr/bin/sw_vers','-productVersion']));
    }
    foreach ([$os,$version,$arch] as $part) if (!preg_match('/\A[a-zA-Z0-9_.-]+\z/', $part)) fail('Unrecognized platform identity');
    return ['php'=>['version'=>PHP_VERSION,'zts'=>(bool)PHP_ZTS,'debug'=>(bool)PHP_DEBUG,'bits'=>PHP_INT_SIZE*8],
            'platform'=>['os'=>$os,'osVersion'=>$version,'arch'=>$arch]];
}
function assetName(string $version, array $identity): string {
    $p=$identity['php']; $h=$identity['platform'];
    return 'asyncara-'.$version.'-php'.$p['version'].'-'.($p['zts']?'zts':'nts').($p['debug']?'-debug':'').'-'.$h['os'].$h['osVersion'].'-'.$h['arch'].'.tar.gz';
}
function readSmall(string $path, int $max = 2097152): string {
    if (!is_file($path) || is_link($path) || filesize($path)>$max) fail('Missing, linked or oversized file: '.$path);
    $data=file_get_contents($path); if ($data===false) fail('Cannot read '.$path); return $data;
}
function jsonFile(string $path): array {
    $value=json_decode(readSmall($path),true,64,JSON_THROW_ON_ERROR);
    if (!is_array($value)) fail('Expected JSON object: '.$path); return $value;
}
function ensureDir(string $path): void {
    if (!is_dir($path) && !mkdir($path,0700,true)) fail('Cannot create '.$path);
}
function within(string $path,string $root): bool { return str_starts_with($path,$root.'/'); }
function removeOwned(string $path): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (new FilesystemIterator($path,FilesystemIterator::SKIP_DOTS) as $entry) removeOwned($entry->getPathname());
    rmdir($path);
}
function download(string $url,string $target,string $tag,string $name,bool $github): void {
    $curl=executable('curl'); if (!$curl) fail('curl is required to download release assets');
    try {
        command([$curl,'--fail','--silent','--show-error','--location','--proto','=https','--proto-redir','=https',
            '--connect-timeout','15','--max-time','180','--max-filesize',(string)MAX_ARCHIVE,'--output',$target,$url],190);
    } catch (RuntimeException $e) {
        if (is_file($target)) unlink($target);
        $gh=$github ? executable('gh') : null;
        if (!$gh) fail('No accessible matching release asset: '.$name.'. Private GitHub releases require gh auth login or --bundle; no source build fallback. '.$e->getMessage());
        command([$gh,'release','download',$tag,'--repo',REPOSITORY,'--pattern',$name,'--dir',dirname($target)],190);
    }
    if (!is_file($target) || filesize($target)>MAX_ARCHIVE) fail('Invalid or oversized download');
}
function extractBundle(string $archive,string $root): array {
    if (!extension_loaded('zlib')) fail('The target PHP needs zlib to read the binary bundle');
    $stream=gzopen($archive,'rb'); if (!$stream) fail('Cannot open bundle');
    $total=0; $bytes=0; $members=0; $names=[]; $pax=[];
    $read=static function(int $n) use ($stream,&$bytes): string {
        if ($n<0 || $n>MAX_CONTENT || $bytes+$n>MAX_CONTENT+MAX_FILES*2048) fail('Bundle exceeds decompression limit');
        $data=''; while (strlen($data)<$n) {
            $chunk=gzread($stream,min(65536,$n-strlen($data)));
            if ($chunk===false || $chunk==='') fail('Truncated archive');
            $data.=$chunk;
        }
        $bytes+=$n; return $data;
    };
    try {
        while (true) {
            $h=$read(512);
            if ($h===str_repeat("\0",512)) { if ($read(512)!==$h || $pax!==[]) fail('Invalid tar ending'); break; }
            if (++$members>MAX_FILES*2) fail('Too many archive headers');
            $checksum=trim(substr($h,148,8)," \0");
            if (!preg_match('/\A[0-7]+\z/',$checksum)) fail('Invalid tar checksum');
            $checked=substr_replace($h,str_repeat(' ',8),148,8);
            if (array_sum(unpack('C*',$checked))!==octdec($checksum)) fail('Tar header checksum mismatch');
            $size=trim(substr($h,124,12)," \0");
            if (!preg_match('/\A[0-7]{1,11}\z/',$size)) fail('Unsupported tar size');
            $size=octdec($size); if ($size>MAX_CONTENT) fail('Oversized archive member');
            $type=$h[156]; $name=rtrim(substr($h,0,100),"\0"); $prefix=rtrim(substr($h,345,155),"\0");
            if ($prefix!=='') $name=$prefix.'/'.$name;
            if ($type==='x') {
                if ($pax!==[] || $size>16384) fail('Invalid PAX metadata');
                $data=$read($size); $read((512-$size%512)%512);
                while ($data!=='') {
                    if (!preg_match('/\A([1-9][0-9]*) /',$data,$m)) fail('Invalid PAX entry');
                    $length=(int)$m[1]; if ($length<=strlen($m[0])+2 || $length>strlen($data)) fail('Invalid PAX length');
                    $record=substr($data,strlen($m[0]),$length-strlen($m[0]));$data=substr($data,$length);
                    if (!str_ends_with($record,"\n") || !str_contains($record,'=')) fail('Invalid PAX record');
                    [$key,$value]=explode('=',substr($record,0,-1),2);
                    if ($key!=='path' || isset($pax[$key])) fail('Unsupported PAX key');$pax[$key]=$value;
                }
                continue;
            }
            if ($type!=="\0" && $type!=='0') fail('Only regular files are allowed in binary bundles');
            $name=$pax['path']??$name;$pax=[];
            if (!str_starts_with($name,'asyncara/') || strlen($name)>1024 || preg_match('/[\x00-\x1f\x7f\\\\]/',$name)) fail('Unsafe archive path');
            $relative=substr($name,9); $parts=explode('/',$relative);
            foreach ($parts as $part) if ($part==='' || $part==='.' || $part==='..') fail('Unsafe archive path');
            if (isset($names[$relative])) fail('Duplicate archive path');
            $names[$relative]=true;$total+=$size;
            if (count($names)>MAX_FILES || $total>MAX_CONTENT) fail('Bundle exceeds content limit');
            $target=$root.'/'.$relative;ensureDir(dirname($target));$out=fopen($target,'xb');if (!$out) fail('Cannot extract '.$relative);
            try { for ($left=$size;$left>0;) { $chunk=$read(min(65536,$left));$length=strlen($chunk);if(fwrite($out,$chunk)!==$length)fail('Short archive write');$left-=$length; } }
            finally { fclose($out); }
            chmod($target,0600);$read((512-$size%512)%512);
        }
    } finally { gzclose($stream); }
    return $names;
}
function validateBundle(string $root,array $names,string $version,array $id): array {
    $m=jsonFile($root.'/manifest.json');
    $same=static function(mixed $a,array $b):bool { if(!is_array($a))return false;ksort($a);ksort($b);return $a===$b; };
    if (($m['schema']??null)!==1 || ($m['project']??null)!=='asyncara/runtime' || ($m['version']??null)!==$version || !$same($m['php']??null,$id['php']) || !$same($m['platform']??null,$id['platform'])) fail('Bundle does not match PHP version, NTS/ZTS, debug mode, architecture or operating system');
    if (!is_string($m['sourceCommit']??null) || !preg_match('/\A[0-9a-f]{40}\z/',$m['sourceCommit'])) fail('Missing source commit');
    $files=$m['files']??null;if(!is_array($files)||count($names)!==count($files)+1)fail('Manifest inventory mismatch');
    foreach (['extension/asyncara.so','lib/libasyncara-mariadb.so.3','runtime/autoload.php','runtime/vendor/autoload.php','runtime/scripts/check-install.php','runtime/tests/release/smoke.php','runtime/LICENSE'] as $required) if(!isset($files[$required]))fail('Missing bundle component: '.$required);
    foreach ($files as $name=>$hash) {
        if (!isset($names[$name]) || !is_string($hash) || !preg_match('/\A[0-9a-f]{64}\z/',$hash) || is_link($root.'/'.$name) || !hash_equals($hash,hash_file('sha256',$root.'/'.$name))) fail('Bundle file checksum mismatch: '.$name);
    }
    return $m;
}
function launcher(string $php,string $dest): string {
    return "#!/bin/sh\nset -eu\nphp=".escapeshellarg($php)."\nmodule=".escapeshellarg($dest.'/extension/asyncara.so')."\nruntime=".escapeshellarg($dest.'/runtime')."\n". <<<'SH'
case "${1:-}" in
  --check) shift; exec "$php" -n -d "extension=$module" "$runtime/scripts/check-install.php" --require-hyper --require-sql "$@" ;;
  --example) shift; exec "$php" -n -d "extension=$module" "$runtime/examples/server.php" "$@" ;;
  --version) exec "$php" -n -d "extension=$module" -r 'echo "Asyncara ", phpversion("asyncara"), " / PHP ", PHP_VERSION, "\n";' ;;
esac
exec "$php" -n -d "extension=$module" -d "auto_prepend_file=$runtime/vendor/autoload.php" "$@"
SH
    ."\n";
}
function ownedCommand(string $path,string $prefix): bool {
    if(!is_link($path))return false;$target=realpath($path);
    return $target!==false && within($target,$prefix.'/releases') && basename($target)==='asyncara' && is_file(dirname($target,2).'/installation.json');
}
function absoluteDirectory(string $path): string {
    if($path==='' || $path[0]!=='/' || preg_match('/[\x00-\x1f\x7f]/',$path))fail('Use an absolute directory without control characters');
    foreach(explode('/',$path) as $part)if($part==='.'||$part==='..')fail('Use a canonical installation directory');
    return rtrim($path,'/');
}
function binDirectory(string $home): string {
    $paths=explode(PATH_SEPARATOR,getenv('PATH')?:'');
    foreach(array_unique(array_merge([$home.'/.local/bin',$home.'/bin','/usr/local/bin'],$paths))as $path) {
        if(!in_array($path,$paths,true)||(!within($path,$home)&&$path!=='/usr/local/bin'))continue;
        if((is_dir($path)&&is_writable($path))||(!file_exists($path)&&is_writable(dirname($path))))return $path;
    }
    return $home.'/.local/bin';
}
function main(array $argv): void {
    $options=['version'=>'0.10.0','prefix'=>null,'bin-dir'=>null,'bundle'=>null];$detect=false;
    for($n=1;$n<count($argv);$n++) {
        if($argv[$n]==='--help') { echo "Usage: sh install.sh [--version 0.10.0] [--bundle FILE] [--prefix DIR] [--bin-dir DIR] [--check-platform]\nSelect PHP with ASYNCARA_PHP=/path/to/php. No local compilation or php.ini editing.\n"; return; }
        if($argv[$n]==='--check-platform'){$detect=true;continue;}
        $key=substr($argv[$n],2);if(!str_starts_with($argv[$n],'--')||!array_key_exists($key,$options)||!isset($argv[$n+1]))fail('Invalid installer argument: '.$argv[$n]);$options[$key]=$argv[++$n];
    }
    $id=identity();if($detect){echo json_encode($id,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";return;}
    if(PHP_VERSION_ID<80300 || PHP_VERSION_ID>=80600)fail('Asyncara 0.10 requires PHP 8.3–8.5');
    if(PHP_OS_FAMILY==='Windows')fail('No Windows runtime bundle has been certified; a POSIX shell does not make a Linux module Windows-compatible');
    $version=$options['version'];if(!preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+\z/',$version))fail('Invalid release version');
    $home=getenv('HOME');if(!$home || $home[0]!=='/')fail('HOME must identify the current user');
    $prefix=absoluteDirectory($options['prefix']??($home.'/.local/share/asyncara'));
    $bin=absoluteDirectory($options['bin-dir']??binDirectory($home));$entry=$bin.'/asyncara';
    echo 'Detected PHP '.PHP_VERSION.' '.(PHP_ZTS?'ZTS':'NTS').' '.(PHP_DEBUG?'DEBUG':'non-debug').' / '.$id['platform']['os'].' '.$id['platform']['osVersion'].' / '.$id['platform']['arch'],"\n";
    $temp=dirname(__FILE__); // The bootstrap's private mktemp directory, never an arbitrary source directory.
    if(!str_starts_with(basename($temp),'asyncara-install.'))fail('Run the generated install.sh, not this embedded helper directly');
    $name=assetName($version,$id);
    if($options['bundle']!==null) {
        $archive=realpath($options['bundle']);if($archive===false)fail('Bundle not found');$name=basename($archive);$sidecar=$archive.'.sha256';
    } else {
        $base=getenv('ASYNCARA_RELEASE_BASE_URL')?:'https://github.com/'.REPOSITORY.'/releases/download/v'.$version;
        $url=parse_url($base);if(!$url || ($url['scheme']??null)!=='https' || !isset($url['host']) || isset($url['user'])||isset($url['pass'])||isset($url['query'])||isset($url['fragment']))fail('Release base URL must be HTTPS without credentials/query/fragment');
        $github=$base==='https://github.com/'.REPOSITORY.'/releases/download/v'.$version;
        $archive=$temp.'/'.$name;$sidecar=$archive.'.sha256';
        download(rtrim($base,'/').'/'.$name,$archive,'v'.$version,$name,$github);
        download(rtrim($base,'/').'/'.$name.'.sha256',$sidecar,'v'.$version,$name.'.sha256',$github);
    }
    $checksum=readSmall($sidecar,4096);
    if(!preg_match('/\A([0-9a-f]{64})[ \t]+\*?'.preg_quote($name,'/').'\n?\z/',$checksum,$matches))fail('Invalid SHA256 sidecar');
    if(filesize($archive)>MAX_ARCHIVE || !hash_equals($matches[1],hash_file('sha256',$archive)))fail('Archive SHA256 mismatch');
    echo "SHA256 verified\n";
    ensureDir($prefix);$prefix=realpath($prefix);$lock=fopen($prefix.'/.install.lock','c');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))fail('Another installer owns this prefix');
    $staging=$prefix.'/.staging-'.bin2hex(random_bytes(12));$link=null;
    try {
        if((file_exists($entry)||is_link($entry))&&!ownedCommand($entry,$prefix))fail('Refusing to replace unrelated command: '.$entry);
        mkdir($staging,0700);$names=extractBundle($archive,$staging);$manifest=validateBundle($staging,$names,$version,$id);
        $probe=[PHP_BINARY,'-n','-d','extension='.$staging.'/extension/asyncara.so'];
        foreach([[$staging.'/runtime/scripts/check-install.php','--require-hyper','--require-sql'],[$staging.'/runtime/tests/release/smoke.php']]as $test) {
            $r=json_decode(command(array_merge($probe,$test)),true,64,JSON_THROW_ON_ERROR);if(($r['status']??null)!=='PASS')fail('Installation probe failed');
        }
        $destination=$prefix.'/releases/'.substr(assetName($version,$id),0,-7).'-shell1';
        $receipt=['schema'=>2,'archiveSha256'=>$matches[1],'php'=>PHP_BINARY,'version'=>$version,'sourceCommit'=>$manifest['sourceCommit']];
        $wrapper=launcher(PHP_BINARY,$destination);
        if(file_exists($destination)||is_link($destination)) {
            if(is_link($destination)||jsonFile($destination.'/installation.json')!==$receipt||validateBundle($destination,$names,$version,$id)!==$manifest||readSmall($destination.'/bin/asyncara')!==$wrapper)fail('Existing installation was modified; use a fresh --prefix');
        } else {
            ensureDir($staging.'/bin');file_put_contents($staging.'/bin/asyncara',$wrapper);chmod($staging.'/bin/asyncara',0755);
            file_put_contents($staging.'/installation.json',json_encode($receipt,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");ensureDir(dirname($destination));
            if(!rename($staging,$destination))fail('Could not activate version directory');
        }
        $r=json_decode(command([$destination.'/bin/asyncara','--check']),true,64,JSON_THROW_ON_ERROR);if(($r['status']??null)!=='PASS')fail('Installed launcher check failed');
        ensureDir($bin);
        if((file_exists($entry)||is_link($entry))&&!ownedCommand($entry,$prefix))fail('Command changed during installation');
        $link=$bin.'/.asyncara-'.bin2hex(random_bytes(8));if(!symlink($destination.'/bin/asyncara',$link)||!rename($link,$entry))fail('Cannot activate command');
        echo "Installed Asyncara $version: $entry\nInstallation check: PASS\n";
        if(!in_array($bin,explode(PATH_SEPARATOR,getenv('PATH')?:''),true))echo 'This shell has no writable suitable PATH directory. To use the bare command now: export PATH='.escapeshellarg($bin).':"$PATH"',"\n";
        echo 'Run: '.escapeshellarg($entry)." --example 127.0.0.1:8080\n";
    } finally {
        removeOwned($staging);if($link!==null&&is_link($link))unlink($link);flock($lock,LOCK_UN);fclose($lock);
    }
}
if (!defined('ASYNCARA_INSTALLER_TEST')) {
    umask(0077);
    set_error_handler(static function(int $severity,string $message,string $file,int $line): never { throw new ErrorException($message,0,$severity,$file,$line); });
    try { main($argv); }
    catch(Throwable $e) { fwrite(STDERR,'Asyncara installation failed: '.$e->getMessage()."\nNo local build or php.ini modification was performed.\n");exit(1); }
}
