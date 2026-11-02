#!/usr/bin/env bash
# Pinned, isolated TLS regression dependency. No package installation or sudo.
set -euo pipefail
cd "$(dirname "$0")/.."
destination="${1:?Pass a new absolute build directory}"
[[ "$destination" = /* && ! -e "$destination" ]] || { echo 'Use a new absolute directory' >&2; exit 1; }
mkdir -p "$destination"
curl -fL --retry 2 https://codeload.github.com/mariadb-corporation/mariadb-connector-c/tar.gz/refs/tags/v3.4.10 -o "$destination/source.tar.gz"
printf '%s  %s\n' 28c38b597a51e8eddd685c675420dd15e290f72d89834a97063e50cdc386f500 "$destination/source.tar.gz" | sha256sum -c -
tar -xf "$destination/source.tar.gz" -C "$destination"
cmake -S "$destination/mariadb-connector-c-3.4.10" -B "$destination/build" \
  -DCMAKE_INSTALL_PREFIX="$destination/install" -DWITH_UNIT_TESTS=OFF \
  -DWITH_SSL=OPENSSL -DCMAKE_BUILD_TYPE=RelWithDebInfo
cmake --build "$destination/build" -j2
cmake --install "$destination/build"
printf 'Use MARIADB_PREFIX=%s/install with scripts/build-local.sh\n' "$destination"
