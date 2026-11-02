#!/usr/bin/env bash
# Explicit opt-in local package extraction; never installs packages system-wide.
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p .local/debs .local/sysroot .local/bin
php_tool_version="${PHP_TOOL_VERSION:-$("${PHP_BIN:-php}" -n -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')}"
(
  cd .local/debs
  apt download "${PHP_DEV_PACKAGE:-php${php_tool_version}-dev}" libsqlite3-dev libcurl4-openssl-dev \
    autoconf automake autotools-dev libtool m4 shtool libpq-dev libmariadb-dev libssl-dev
)
for package in .local/debs/*.deb; do dpkg-deb -x "$package" .local/sysroot; done
sha256sum .local/debs/*.deb > .local/dependencies.sha256
python3 scripts/relocate-tools.py
printf 'Local toolchain extracted. Run make build. No system configuration changed.\n'
