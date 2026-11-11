#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
include="${ASYNCARA_MYSQL_INCLUDE:-$PWD/.local/sysroot/usr/include/mariadb}"
"${CC:-cc}" -dM -E -I"$include" -include mysql.h -x c /dev/null > build/connector-features.txt
if rg -q '^#define MARIADB_TLS_VERIFY_ERROR ' build/connector-features.txt; then
  echo 'Connector/C provides TLS callback/status API; verify behavior with the private-CA integration suite.'
else
  "${PHP_BIN:-php}" -n -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/database/connector_gate.php
  "${PHP_BIN:-php}" -n -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/database/client_tls_gate.php
fi
