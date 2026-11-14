#!/usr/bin/env bash
# Maintainer/CI only: build a matching historical PHP CLI + SDK in a private prefix.
# The end-user installer never invokes this script or compiles anything.
set -euo pipefail
sdk_version="${1:?PHP patch version required}"
sdk_root="${2:?New absolute SDK directory required}"
[[ "$sdk_version" =~ ^8\.[345]\.[0-9]+$ && "$sdk_root" = /* && ! -e "$sdk_root" ]] || {
  echo 'Use a released PHP 8.3/8.4/8.5 patch version and a new absolute directory' >&2; exit 1;
}
mkdir -p "$sdk_root"
exec > >(tee "$sdk_root/build.log") 2>&1
python3 - "$sdk_version" "$sdk_root" <<'PY'
import hashlib, json, pathlib, sys, urllib.request
version, root = sys.argv[1], pathlib.Path(sys.argv[2])
with urllib.request.urlopen('https://www.php.net/releases/index.php?json&version='+version, timeout=60) as response:
    raw=response.read(1024*1024)
info=json.loads(raw)
if info.get('version') != version: raise SystemExit('Official PHP release identity mismatch')
name='php-'+version+'.tar.xz'
source=next(item for item in info['source'] if item['filename']==name)
digest=source['sha256']
if len(digest)!=64 or any(c not in '0123456789abcdef' for c in digest): raise SystemExit('Invalid official SHA256')
(root/'release.json').write_bytes(raw)
url=('https://museum.php.net/php8/' if info.get('museum') else 'https://www.php.net/distributions/')+name
with urllib.request.urlopen(url, timeout=120) as response, (root/name).open('xb') as output:
    total=0
    while data:=response.read(65536):
        total+=len(data)
        if total>128*1024*1024: raise SystemExit('Oversized PHP source download')
        output.write(data)
actual=hashlib.sha256((root/name).read_bytes()).hexdigest()
if actual!=digest: raise SystemExit('PHP source SHA256 mismatch')
(root/'source.sha256').write_text(digest+'  '+name+'\n')
print('Verified official PHP source',version,digest)
PY
tar -xf "$sdk_root/php-$sdk_version.tar.xz" -C "$sdk_root"
cd "$sdk_root/php-$sdk_version"
./configure --prefix="$sdk_root/install" --disable-all --enable-cli --disable-cgi --disable-phpdbg \
  --enable-filter --enable-tokenizer --enable-ctype --enable-phar --enable-session \
  --enable-posix --enable-pcntl --enable-sockets --with-openssl --with-zlib --with-iconv
make -j2
make install
[[ "$("$sdk_root/install/bin/php" -n -r 'echo PHP_VERSION;')" = "$sdk_version" ]]
[[ "$("$sdk_root/install/bin/php-config" --version)" = "$sdk_version" ]]
# Build/module identity only: do not persist runner environment or PHP variables.
"$sdk_root/install/bin/php" -n -r 'phpinfo(INFO_GENERAL | INFO_MODULES);' > "$sdk_root/php-info.txt"
sha256sum "$sdk_root/install/bin/php" > "$sdk_root/php.sha256"
