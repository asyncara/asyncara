#!/usr/bin/env bash
# Run on a disposable Ubuntu 24.04 builder with matching PHP CLI/development SDK.
# Produces assets; never uploads, installs globally, or changes an existing tag.
set -euo pipefail
tools_root="$(cd "$(dirname "$0")/../.." && pwd)"
repo="${1:?Usage: build.sh GIT_REPO SOURCE_COMMIT NEW_OUTPUT_DIRECTORY}"
source_commit="${2:?Exact source commit required}"
build_root="${3:?New absolute output directory required}"
[[ "$source_commit" =~ ^[0-9a-f]{40}$ && "$build_root" = /* && ! -e "$build_root" ]] || { echo 'Use an exact commit and a new absolute directory' >&2; exit 1; }
[[ "$(git -C "$repo" rev-parse "$source_commit^{commit}")" = "$source_commit" ]]
mkdir -p "$build_root/source" "$build_root/connector"
# Extract immutable runtime source; packaging tools may be newer than the release.
git -C "$repo" archive "$source_commit" | tar -x -C "$build_root/source"
exec > >(tee "$build_root/build.log") 2>&1
printf 'source=%s\n' "$source_commit"
php --version
phpize --version
rustc --version
cmake --version
connector="$build_root/connector"
curl --fail --location --retry 2 https://codeload.github.com/mariadb-corporation/mariadb-connector-c/tar.gz/refs/tags/v3.4.10 -o "$connector/source.tar.gz"
printf '%s  %s\n' 28c38b597a51e8eddd685c675420dd15e290f72d89834a97063e50cdc386f500 "$connector/source.tar.gz" | sha256sum -c -
tar -xf "$connector/source.tar.gz" -C "$connector"
cmake -S "$connector/mariadb-connector-c-3.4.10" -B "$connector/build" \
  -DCMAKE_INSTALL_PREFIX="$connector/install" -DWITH_UNIT_TESTS=OFF -DWITH_SSL=OPENSSL -DCMAKE_BUILD_TYPE=RelWithDebInfo \
  -DCLIENT_PLUGIN_CACHING_SHA2_PASSWORD=STATIC -DCLIENT_PLUGIN_SHA256_PASSWORD=STATIC \
  -DCLIENT_PLUGIN_CLIENT_ED25519=STATIC -DCLIENT_PLUGIN_MYSQL_CLEAR_PASSWORD=STATIC -DCLIENT_PLUGIN_DIALOG=STATIC \
  -DCLIENT_PLUGIN_AUTH_GSSAPI_CLIENT=OFF -DCLIENT_PLUGIN_PARSEC=OFF
cmake --build "$connector/build" --parallel 2
cmake --install "$connector/build"
export CARGO_TARGET_DIR="$build_root/cargo" CARGO_BUILD_JOBS=2
cargo build --release --locked --manifest-path "$build_root/source/ext/hyper/Cargo.toml"
export PKG_CONFIG_PATH="$connector/install/lib/pkgconfig${PKG_CONFIG_PATH:+:$PKG_CONFIG_PATH}"
export LD_LIBRARY_PATH="$connector/install/lib/mariadb${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
cd "$build_root/source/ext"
phpize
./configure --enable-asyncara --with-asyncara-pq --with-asyncara-mysql --with-asyncara-hyper="$CARGO_TARGET_DIR/release"
make -j2
module="$PWD/modules/asyncara.so"
cd ..
# Composer's Xdebug restart drops a CLI-only extension setting on some images.
# Keep this process (the extension is verified independently below).
COMPOSER_ALLOW_XDEBUG=1 php -d "extension=$module" "$(command -v composer)" install --no-dev --no-interaction --prefer-dist --no-scripts
php -n -d "extension=$module" scripts/check-install.php --require-hyper --require-sql
for backend in epoll poll; do
  ASYNCARA_BACKEND="$backend" php -n -d "extension=$module" tests/release/smoke.php
done
# The relocatable package must load without the build-time library search path.
unset LD_LIBRARY_PATH
python3 "$tools_root/scripts/distribution/package.py" --source "$PWD" --source-commit "$source_commit" \
  --module "$module" --connector "$connector" --php "$(command -v php)" --cargo "$(command -v cargo)" --output "$build_root/assets"
for bundle in "$build_root/assets/"*.tar.gz; do
  python3 "$tools_root/tests/distribution/integration.py" --php "$(command -v php)" --bundle "$bundle" --output "$build_root/installed-test"
done
