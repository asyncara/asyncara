#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p build
inc="${PHP_INCLUDE_DIR:-$PWD/.local/sysroot/usr/include/php/20250925}"
if [[ ! -f "$inc/main/php.h" ]]; then
  echo 'PHP headers missing. Set PHP_INCLUDE_DIR or run scripts/bootstrap-local.sh.' >&2
  exit 1
fi
pq_flags=()
if [[ -f .local/sysroot/usr/include/postgresql/libpq-fe.h ]]; then
  pq_flags=(-DHAVE_ASYNCARA_PQ=1 -I.local/sysroot/usr/include/postgresql -l:libpq.so.5)
fi
if [[ -f .local/sysroot/usr/include/mariadb/mysql.h ]]; then
  pq_flags+=(-DHAVE_ASYNCARA_MYSQL=1 -I.local/sysroot/usr/include/mariadb -l:libmariadb.so.3)
fi
"${CC:-cc}" -std=c11 -D_GNU_SOURCE -DCOMPILE_DL_ASYNCARA=1 -fPIC -shared \
  -O2 -g -Wall -Wextra -Werror -Wno-unused-parameter ${EXTRA_CFLAGS:-} \
  -I"$inc" -I"$inc/main" -I"$inc/Zend" -I"$inc/TSRM" -I"$inc/ext" \
  -I.local/sysroot/usr/include -I.local/sysroot/usr/include/x86_64-linux-gnu \
  ext/php_asyncara.c ext/workers.c ext/backend.c ext/memory.c -o "${ASYNCARA_OUTPUT:-build/asyncara.so}" -pthread \
  -l:libsqlite3.so.0 -l:libcurl.so.4 "${pq_flags[@]}" ${EXTRA_LDFLAGS:-}
"${PHP_BIN:-php}" -n -d extension="$PWD/${ASYNCARA_OUTPUT:-build/asyncara.so}" --ri asyncara
