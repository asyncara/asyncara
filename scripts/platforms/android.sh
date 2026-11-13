#!/data/data/com.termux/files/usr/bin/bash
set -euo pipefail
umask 022
export CONFIG_SHELL="$PREFIX/bin/sh" SHELL="$PREFIX/bin/sh"
# Native Bionic toolchain only. Do not generate a glibc binary labelled Android.
test -n "${PREFIX:-}" && { test -e /system/bin/linker64 || test -e /system/bin/linker; }
: "${PLATFORM_OUT:=/out}"
mkdir -p "$PLATFORM_OUT"
cd "$PLATFORM_OUT"
exec > >(tee build.log) 2>&1
sed -i "s@/usr/bin/sed@$PREFIX/bin/sed@g" "$PREFIX/bin/php-config"
php -n -v
php-config --includes
clang --version
if command -v rustc >/dev/null; then rustc --version; fi
mkdir source
(cd /work && tar --exclude=.git --exclude=.local --exclude=build --exclude=vendor -cf - .) | (cd source && tar -xf -)
cd source
find ext src -type f -print0 | sort -z | xargs -0 sha256sum > ../source-files.sha256
mkdir -p build
# PHP's own SDK selects ABI; Android links pthread functions through libc.
clang -shared -fPIC -O2 -std=c11 -D_GNU_SOURCE -DCOMPILE_DL_ASYNCARA \
  -Wall -Wextra -Werror -Wno-unused-parameter $(php-config --includes) \
  ext/php_asyncara.c ext/workers.c ext/backend.c ext/memory.c ext/hyper_decode.c \
  -lcurl -lsqlite3 -o build/asyncara-core.so
php -n -d extension="$PWD/build/asyncara-core.so" --ri asyncara
for backend in epoll poll; do
  for test in tests/release/smoke.php tests/platforms/core.php tests/platforms/network.php tests/file_handles.php tests/filesystem_paths.php tests/stream_readiness.php tests/udp_ownership.php; do
    ASYNCARA_BACKEND="$backend" php -n -d extension="$PWD/build/asyncara-core.so" "$test"
  done
done
sha256sum build/asyncara-core.so > ../core-module.sha256
if [ "${ASYNCARA_BUILD_HYPER:-1}" = 0 ]; then
  cp build/asyncara-core.so build/asyncara.so
  bash scripts/platforms/package-android.sh
  printf 'PASS: core profile\n' > ../result.txt
  exit 0
fi
# Build the actual Rust bridge for the same Bionic ABI.
export CARGO_BUILD_JOBS=2
cargo build --manifest-path ext/hyper/Cargo.toml --release --locked
bash scripts/platforms/android-deps.sh
maria_pc=$(find "$PREFIX/lib" -name libmariadb.pc -print -quit)
export PKG_CONFIG_PATH="$PWD/build/deps/pkgconfig:$(dirname "$maria_pc"):${PKG_CONFIG_PATH:-}"
export LDFLAGS="-Wl,-rpath,$(pkg-config --variable=libdir libmariadb)"
cd ext
phpize
"$CONFIG_SHELL" ./configure --enable-asyncara --with-asyncara-pq --with-asyncara-mysql --with-asyncara-hyper="$PWD/hyper/target/release" --with-php-config="$(command -v php-config)"
make -j2 SHELL="$CONFIG_SHELL"
cd ..
cp ext/modules/asyncara.so build/asyncara.so
for backend in epoll poll; do
  ASYNCARA_BACKEND="$backend" php -n -d extension="$PWD/build/asyncara.so" tests/release/smoke.php
  ASYNCARA_BACKEND="$backend" php -n -d extension="$PWD/build/asyncara.so" tests/platforms/core.php
done
sha256sum build/asyncara.so > ../module.sha256
php -n -d extension="$PWD/build/asyncara.so" --ri asyncara > ../extension-info.txt
bash scripts/platforms/package-android.sh
printf 'PASS\n' > ../result.txt
