#!/usr/bin/env bash
set -euo pipefail
arch=$(dpkg --print-architecture)
profile=hyper-sql
if [ "${ASYNCARA_BUILD_HYPER:-1}" = 0 ]; then profile=core; fi
bundle="asyncara-0.10.0-android-$arch-php$(php -n -r 'echo PHP_VERSION;')-nts-$profile-preview"
test ! -e "../$bundle"
mkdir "../$bundle"
cp build/asyncara.so LICENSE autoload.php "../$bundle/"
cp -R src "../$bundle/src"
cp scripts/platforms/check.php "../$bundle/check.php"
cat > "../$bundle/asyncara" <<'LAUNCH'
#!/data/data/com.termux/files/usr/bin/sh
set -eu
base=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
case "${1:-}" in
 --check) exec "${ASYNCARA_PHP:-php}" -n -d "extension=$base/asyncara.so" "$base/check.php" ;;
esac
exec "${ASYNCARA_PHP:-php}" -n -d "extension=$base/asyncara.so" -d "auto_prepend_file=$base/autoload.php" "$@"
LAUNCH
chmod +x "../$bundle/asyncara"
if [ "$profile" = hyper-sql ]; then
  cargo metadata --locked --offline --filter-platform "$(rustc -vV | sed -n 's/^host: //p')" --format-version 1 --manifest-path ext/hyper/Cargo.toml > ../cargo-metadata.json
  php -n scripts/platforms/notices.php ../cargo-metadata.json "../$bundle/notices/rust"
  cp build/deps/zstd-1.5.7/LICENSE "../$bundle/notices/zstd-LICENSE"
fi
php -n -d extension="$PWD/build/asyncara.so" -r '
$i=asyncara_info();
echo json_encode(["schema"=>1,"version"=>"0.10.0","profile"=>getenv("ASYNCARA_BUILD_HYPER")==="0"?"core":"hyper-sql","sourceCommit"=>getenv("SOURCE_COMMIT")?:null,"php"=>PHP_VERSION,"zts"=>PHP_ZTS,"bits"=>PHP_INT_SIZE*8,"abi"=>"android-bionic","minApi"=>24,"architecture"=>getenv("DEB_HOST_ARCH")?:trim(shell_exec("dpkg --print-architecture")),"moduleSha256"=>hash_file("sha256","build/asyncara.so"),"capabilities"=>$i,"validation"=>"Termux Bionic container; not Android device certification"],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";
' > "../$bundle/manifest.json"
cp ../source-files.sha256 "../$bundle/"
cat > "../$bundle/INSTALL.txt" <<'TEXT'
Experimental native Termux/Bionic build (Android API 24+), PHP 8.5.12 NTS.
This is not a glibc/Linux binary and is not a Windows binary.
Install runtime dependencies using Termux: pkg install php libcurl libsqlite openssl postgresql mariadb brotli zstd
Select exactly the matching PHP patch version, architecture and NTS mode.
Load without editing php.ini: php -d extension=/absolute/path/asyncara.so your-script.php
Require the bundled autoload.php to use Asyncara classes.
The core profile has no Rust/Hyper server, PostgreSQL or MySQL driver; read manifest.json.
TEXT
tar -C .. -czf "../$bundle.tar.gz" "$bundle"
(cd .. && sha256sum "$bundle.tar.gz" > "$bundle.tar.gz.sha256")
