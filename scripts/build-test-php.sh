#!/usr/bin/env bash
# Isolated debug CLI with real Fiber-stack annotations for Valgrind.
set -euo pipefail
cd "$(dirname "$0")/.."
destination="${1:?Pass a new absolute build directory}"
[[ "$destination" = /* && ! -e "$destination" ]] || { echo 'Use a new absolute directory' >&2; exit 1; }
root="$PWD"
[[ -f "$root/.local/sysroot/usr/include/valgrind/valgrind.h" ]] || { echo 'Extract Valgrind development headers locally first' >&2; exit 1; }
mkdir -p "$destination"
curl -fL --retry 2 https://www.php.net/distributions/php-8.5.12.tar.xz -o "$destination/source.tar.xz"
printf '%s  %s\n' 08bc2eba2a588654ade1357174aa646b761556c862dda0678345ee29ed481209 "$destination/source.tar.xz" | sha256sum -c -
tar -xf "$destination/source.tar.xz" -C "$destination"
cd "$destination/php-8.5.12"
VALGRIND_CFLAGS="-I$root/.local/sysroot/usr/include" VALGRIND_LIBS=' ' \
  ./configure --prefix="$destination/install" --disable-all --enable-cli \
  --disable-cgi --disable-phpdbg --disable-opcache-jit --enable-debug \
  --enable-filter --with-openssl --with-valgrind
make -j2
make install
"$destination/install/bin/php" -n -v
