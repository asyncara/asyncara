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
  Write-Host ((Get-ChildItem "$deps/lib/*.lib").Name -join ', ')
  $machine=if($Arch -eq 'x64') {'x64'} else {'Win32'}
  & cmake -S "$root/scripts/platforms/windows-cmake" -B "$out/cmake" -G 'Visual Studio 17 2022' -A $machine "-DCMAKE_TOOLCHAIN_FILE=$env:VCPKG_INSTALLATION_ROOT/scripts/buildsystems/vcpkg.cmake" "-DVCPKG_TARGET_TRIPLET=$Arch-windows-static-md" -DVCPKG_MANIFEST_MODE=OFF "-DPHP_SDK_INCLUDE=$sdk" "-DPHP_LIBRARY=$phpLib" "-DPHP_RUNTIME_DIR=$out/php"
  if($LASTEXITCODE){throw 'CMake configuration failed'}
  & cmake --build "$out/cmake" --config Release --parallel 2
  if($LASTEXITCODE){throw 'Native Windows build failed'}
  & "$out/php/platform-native.exe"
  if($LASTEXITCODE){throw 'Native platform regression failed'}
  $php="$out/php/php.exe"
  function Invoke-CheckedPhp([string[]]$Arguments,[string]$Label) {
    $start=[System.Diagnostics.ProcessStartInfo]::new()
    $start.FileName=$php; $start.UseShellExecute=$false
    $start.RedirectStandardOutput=$true; $start.RedirectStandardError=$true
    foreach($arg in @('-n','-d',"extension=$out/php/asyncara.dll")+$Arguments){$start.ArgumentList.Add($arg)}
    $process=[System.Diagnostics.Process]::new();$process.StartInfo=$start
    $watch=[System.Diagnostics.Stopwatch]::StartNew()
    if(!$process.Start()){throw "Cannot start $Label"}
    $stdout=$process.StandardOutput.ReadToEndAsync();$stderr=$process.StandardError.ReadToEndAsync()
    if(!$process.WaitForExit(60000)){$process.Kill($true);$process.WaitForExit();throw "Timed out: $Label"}
    $text=$stdout.GetAwaiter().GetResult();$errors=$stderr.GetAwaiter().GetResult()
    Write-Host $text;Write-Host $errors
    if($process.ExitCode){throw "$Label exited $($process.ExitCode)"}
    Write-Host "PASS $Label in $($watch.Elapsed.TotalSeconds)s"
    $process.Dispose()
  }
  Invoke-CheckedPhp -Arguments @('--ri','asyncara') -Label 'module-load'
  foreach($test in @('tests/release/smoke.php','tests/platforms/core.php','tests/platforms/network.php')) {
    Invoke-CheckedPhp -Arguments @("$root/$test") -Label $test
  }
  $identity=@{source=(& git -C $root rev-parse HEAD);arch=$Arch;os='windows';php=(& $php -n -r 'echo PHP_VERSION;');threadSafety='nts';module=(Get-FileHash "$out/php/asyncara.dll").Hash.ToLowerInvariant();status='PASS';hyper=$false;postgresql=$false;mysql=$false}
  $identity | ConvertTo-Json | Set-Content "$out/manifest.json"
  $bundle="$out/asyncara-0.10.0-windows-$Arch-php8.5.12-nts-core-preview"
  New-Item -ItemType Directory $bundle | Out-Null
  Copy-Item "$out/php/asyncara.dll","$out/manifest.json","$root/LICENSE" $bundle
  Copy-Item "$root/src" "$bundle/src" -Recurse
  Copy-Item "$root/autoload.php" $bundle
  Copy-Item "$deps/share" "$bundle/dependency-notices" -Recurse
  Compress-Archive "$bundle/*" "$bundle.zip"
  (Get-FileHash "$bundle.zip").Hash.ToLowerInvariant()+'  '+(Split-Path "$bundle.zip" -Leaf) | Set-Content "$bundle.zip.sha256"
} finally {Stop-Transcript}
