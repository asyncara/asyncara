#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p build
php_api="$("${PHP_BIN:-php}" -n -i | awk '/^PHP API => / { print $4 }')"
inc="${PHP_INCLUDE_DIR:-$PWD/.local/sysroot/usr/include/php/$php_api}"
if [[ ! -f "$inc/main/php.h" ]]; then
  echo 'PHP headers missing. Set PHP_INCLUDE_DIR or run scripts/bootstrap-local.sh.' >&2
  exit 1
fi
python3 scripts/check-php-sdk.py "$inc" "${PHP_BIN:-php}"
curl_inc=()
curl_lib=(-l:libcurl.so.4)
# Explicit isolated dependency builds only. Never install over the host library.
if [[ -n "${CURL_PREFIX:-}" ]]; then
  if [[ "$CURL_PREFIX" != /* || ! -f "$CURL_PREFIX/include/curl/curl.h" || ! -f "$CURL_PREFIX/lib/libcurl.so.4" ]]; then
    echo 'CURL_PREFIX must be an absolute prefix containing curl headers and lib/libcurl.so.4.' >&2
    exit 1
  fi
  curl_inc=("-I$CURL_PREFIX/include")
  curl_lib=("-L$CURL_PREFIX/lib" "-Wl,-rpath,$CURL_PREFIX/lib" -l:libcurl.so.4)
fi
pq_flags=()
if [[ -f .local/sysroot/usr/include/postgresql/libpq-fe.h ]]; then
  pq_flags=(-DHAVE_ASYNCARA_PQ=1 -I.local/sysroot/usr/include/postgresql -l:libpq.so.5)
fi
if [[ -n "${MARIADB_PREFIX:-}" ]]; then
  pq_flags+=(-DHAVE_ASYNCARA_MYSQL=1 "-I$MARIADB_PREFIX/include/mariadb" -Iext/compat/mariadb "-L$MARIADB_PREFIX/lib/mariadb" "-Wl,-rpath,$MARIADB_PREFIX/lib/mariadb" -lmariadb -lssl -lcrypto)
elif [[ -f .local/sysroot/usr/include/mariadb/mysql.h ]]; then
  pq_flags+=(-DHAVE_ASYNCARA_MYSQL=1 -I.local/sysroot/usr/include/mariadb -Iext/compat/mariadb -l:libmariadb.so.3 -lssl -lcrypto)
fi
"${CC:-cc}" -std=c11 -D_GNU_SOURCE -DCOMPILE_DL_ASYNCARA=1 -fPIC -shared \
  -O2 -g -Wall -Wextra -Werror -Wno-unused-parameter ${EXTRA_CFLAGS:-} \
  -I"$inc" -I"$inc/main" -I"$inc/Zend" -I"$inc/TSRM" -I"$inc/ext" \
  "${curl_inc[@]}" -I.local/sysroot/usr/include -I.local/sysroot/usr/include/x86_64-linux-gnu \
  ext/php_asyncara.c ext/workers.c ext/backend.c ext/memory.c ext/hyper_decode.c -o "${ASYNCARA_OUTPUT:-build/asyncara.so}" -pthread \
  -l:libsqlite3.so.0 "${curl_lib[@]}" "${pq_flags[@]}" ${EXTRA_LDFLAGS:-}
"${PHP_BIN:-php}" -n -d extension="$PWD/${ASYNCARA_OUTPUT:-build/asyncara.so}" --ri asyncara
