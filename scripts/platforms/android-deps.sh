#!/usr/bin/env bash
set -euo pipefail
# Run inside the native Bionic source tree. Retain the static zstd allocator API.
# Configure only this container's PHP SDK script; do not edit system PHP elsewhere.
sed -i "s@/usr/bin/sed@$PREFIX/bin/sed@g" "$PREFIX/bin/php-config"
mkdir -p build/deps/pkgconfig
cd build/deps
curl -fL --retry 3 https://github.com/facebook/zstd/releases/download/v1.5.7/zstd-1.5.7.tar.gz -o zstd-1.5.7.tar.gz
printf '%s\n' 'eb33e51f49a15e023950cd7825ca74a4a2b43db8354825ac24fc1b7ee09e6fa3  zstd-1.5.7.tar.gz' | sha256sum -c -
tar xzf zstd-1.5.7.tar.gz
make -C zstd-1.5.7/lib -j2 libzstd.a CFLAGS='-O2 -fPIC'
cp zstd-1.5.7/lib/libzstd.a .
sed "s@^libdir=.*@libdir=$PWD@" "$PREFIX/lib/pkgconfig/libzstd.pc" > pkgconfig/libzstd.pc
