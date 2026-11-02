#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
python3 scripts/relocate-tools.py
root="$PWD"
export PATH="$root/.local/bin:$root/.local/sysroot/usr/bin:$PATH"
export AUTOM4TE_PERLLIB="$root/.local/sysroot/usr/share/autoconf"
export AUTOM4TE_CFG="$root/.local/bin/autom4te.cfg"
export autom4te_perllibdir="$root/.local/sysroot/usr/share/autoconf"
export AUTOM4TE="$root/.local/sysroot/usr/bin/autom4te"
export trailer_m4="$root/.local/sysroot/usr/share/autoconf/autoconf/trailer.m4"
export AC_MACRODIR="$root/.local/sysroot/usr/share/autoconf"
export M4="$root/.local/sysroot/usr/bin/m4"
export AUTOCONF="$root/.local/sysroot/usr/bin/autoconf"
export AUTOHEADER="$root/.local/sysroot/usr/bin/autoheader"
# libtool's pkg.m4 is supplied by host pkg-config; copy only into the local sysroot.
cp /usr/share/aclocal/pkg.m4 .local/sysroot/usr/share/aclocal/pkg.m4
cd ext
phpize
CPPFLAGS="-I$root/.local/sysroot/usr/include -I$root/.local/sysroot/usr/include/x86_64-linux-gnu -I$root/.local/sysroot/usr/include/postgresql -I$root/.local/sysroot/usr/include/mariadb" \
LDFLAGS="-L$root/.local/lib" \
PKG_CONFIG_PATH="$root/.local/sysroot/usr/lib/x86_64-linux-gnu/pkgconfig" \
./configure --enable-asyncara --with-asyncara-pq --with-asyncara-mysql --with-php-config="$root/.local/bin/php-config"
make -j2
php -n -d extension="$root/ext/modules/asyncara.so" --ri asyncara
