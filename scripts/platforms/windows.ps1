param([ValidateSet('x64','x86')][string]$Arch='x64')
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$root=(Resolve-Path "$PSScriptRoot/../..").Path
$out=Join-Path $root "build/platforms/windows-$Arch"
New-Item -ItemType Directory -Force $out | Out-Null
Start-Transcript -Path "$out/build.log"
try {
  $hashes=@{
    x64=@('2a36a2edcb7a5b9252f638fd5e4cf84d7f6e066092654220dc0b63e5cf4e9723','c0d9632a542e11578bc1374fb9e30ba4c5589e1977023e7d3b76cc4c1e4f61c6')
    x86=@('3a8af36358704905feaf346d63ee2d3f70474689ff74f028fa9c8c66008ca01d','2f2b3a04003575ad263f09db41b05987b85548277cb1311e2a1a9d51d91142d9')
  }
  $names=@("php-8.5.12-1-nts-Win32-vs17-$Arch.zip","php-devel-pack-8.5.12-1-nts-Win32-vs17-$Arch.zip")
  for($i=0;$i -lt 2;$i++) {
    $file=Join-Path $out $names[$i]
    Invoke-WebRequest "https://downloads.php.net/~windows/releases/$($names[$i])" -OutFile $file
    if((Get-FileHash $file -Algorithm SHA256).Hash.ToLowerInvariant() -ne $hashes[$Arch][$i]) {throw "PHP archive checksum mismatch: $file"}
    Expand-Archive $file "$out/php" -Force
  }
  $sdk=(Get-ChildItem "$out/php" -Recurse -Filter php.h | Where-Object {$_.Directory.Name -eq 'main'} | Select-Object -First 1).Directory.Parent.FullName
  $phpLib=(Get-ChildItem "$out/php" -Recurse -Filter php8.lib | Select-Object -First 1).FullName
  if(!$sdk -or !$phpLib) {throw 'PHP SDK headers/import library absent'}
  $vcpkg=Join-Path $env:VCPKG_INSTALLATION_ROOT 'vcpkg.exe'
  & $vcpkg install "curl[core,ssl]:$Arch-windows-static-md" "sqlite3:$Arch-windows-static-md" --classic --disable-metrics
  if($LASTEXITCODE) {throw "vcpkg failed $LASTEXITCODE"}
  $deps=Join-Path $env:VCPKG_INSTALLATION_ROOT "installed/$Arch-windows-static-md"
  $vswhere="${env:ProgramFiles(x86)}/Microsoft Visual Studio/Installer/vswhere.exe"
  $vs=(& $vswhere -latest -products '*' -requires Microsoft.VisualStudio.Component.VC.Tools.x86.x64 -property installationPath)
  $vcvars=Join-Path $vs "VC/Auxiliary/Build/vcvarsall.bat"
  $target=if($Arch -eq 'x64') {'x86_64-pc-windows-msvc'} else {'i686-pc-windows-msvc'}
  $clang='cl.exe'
  $includes=@($sdk,"$sdk/main","$sdk/Zend","$sdk/TSRM","$root/ext","$deps/include") | ForEach-Object {'/I"'+$_+'"'}
  $sources=@('php_asyncara.c','workers.c','backend.c','memory.c','hyper_decode.c','platform_windows.c','compat/windows/files.c') | ForEach-Object {'"'+"$root/ext/$_"+'"'}
  $line='call "'+$vcvars+'" '+$Arch+"`r`n"+'"'+$clang+'" /nologo /LD /MD /O2 /std:c11 /experimental:c11atomics /W3 /DCURL_STATICLIB /DCOMPILE_DL_ASYNCARA /DENABLE_INTSAFE_SIGNED_FUNCTIONS /DPHP_WIN32 /DZEND_WIN32 /DZEND_DEBUG=0 /DZEND_ENABLE_STATIC_TSRMLS_CACHE=1 /D_CRT_SECURE_NO_WARNINGS /D_WIN32_WINNT=0x0602 '+($includes -join ' ')+' '+($sources -join ' ')+' /link /OUT:"'+$out+'/php/asyncara.dll" "'+$phpLib+'" /LIBPATH:"'+$deps+'/lib" libcurl.lib sqlite3.lib zlib.lib ws2_32.lib bcrypt.lib advapi32.lib crypt32.lib secur32.lib normaliz.lib wldap32.lib' + "`r`nexit /b %errorlevel%`r`n"
  Set-Content "$out/compile.cmd" $line
  Push-Location $out
  try { & cmd.exe /d /c compile.cmd; if($LASTEXITCODE) {throw "Compilation failed $LASTEXITCODE"} } finally {Pop-Location}
  $php="$out/php/php.exe"
  & $php -n -d "extension=$out/php/asyncara.dll" --ri asyncara
  if($LASTEXITCODE){throw 'Extension load failed'}
  foreach($test in @('tests/release/smoke.php','tests/platforms/core.php','tests/platforms/network.php')) {
    & $php -n -d "extension=$out/php/asyncara.dll" "$root/$test"
    if($LASTEXITCODE){throw "Test failed: $test"}
  }
  $identity=@{source=(& git -C $root rev-parse HEAD);arch=$Arch;os='windows';php=(& $php -n -r 'echo PHP_VERSION;');threadSafety='nts';module=(Get-FileHash "$out/php/asyncara.dll").Hash.ToLowerInvariant();status='PASS';hyper=$false;postgresql=$false;mysql=$false}
  $identity | ConvertTo-Json | Set-Content "$out/manifest.json"
  $bundle="$out/asyncara-windows-$Arch-php85-nts-preview"
  New-Item -ItemType Directory $bundle | Out-Null
  Copy-Item "$out/php/asyncara.dll","$out/manifest.json","$root/LICENSE" $bundle
  Copy-Item "$root/src" "$bundle/src" -Recurse
  Copy-Item "$root/autoload.php" $bundle
  Copy-Item "$deps/share" "$bundle/dependency-notices" -Recurse
  Compress-Archive "$bundle/*" "$bundle.zip"
  (Get-FileHash "$bundle.zip").Hash.ToLowerInvariant()+'  '+(Split-Path "$bundle.zip" -Leaf) | Set-Content "$bundle.zip.sha256"
} finally {Stop-Transcript}
