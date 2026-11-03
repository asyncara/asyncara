#!/usr/bin/env bash
# Explicit opt-in local package extraction; never installs packages system-wide.
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p .local/debs .local/sysroot .local/bin
(
  cd .local/debs
  apt download "${PHP_DEV_PACKAGE:-php8.5-dev}" libsqlite3-dev libcurl4-openssl-dev \
    autoconf automake autotools-dev libtool m4 shtool libpq-dev libmariadb-dev
)
for package in .local/debs/*.deb; do dpkg-deb -x "$package" .local/sysroot; done
sha256sum .local/debs/*.deb > .local/dependencies.sha256
python3 scripts/relocate-tools.py
printf 'Local toolchain extracted. Run make build. No system configuration changed.\n'
