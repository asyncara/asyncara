#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
extension="${ASYNCARA_EXTENSION:-build/asyncara.so}"
python3 scripts/check-build.py "$extension"
[[ "$extension" = /* ]] || extension="$PWD/$extension"
export USE_ZEND_ALLOC=0
export VALGRIND_LIB="$PWD/.local/sysroot/usr/libexec/valgrind"
vg="$PWD/.local/sysroot/usr/bin/valgrind"
if [[ ! -x "$vg" ]]; then echo 'Extract Valgrind and matching libc6-dbg packages locally first; see WORKLOG.md.' >&2; exit 1; fi
# Valgrind's extra-path lookup uses the loader's debuglink basename, not .build-id.
loader="$(readlink -f /lib64/ld-linux-x86-64.so.2)"
identity="$(readelf -n "$loader" | awk '/Build ID:/{print $3; exit}')"
source="$PWD/.local/sysroot/usr/lib/debug/.build-id/${identity:0:2}/${identity:2}.debug"
mkdir -p ".local/debug$(dirname "$loader")"
cp "$source" ".local/debug$(dirname "$loader")/${identity:2}.debug"
args=(--extra-debuginfo-path="$PWD/.local/debug" --keep-debuginfo=yes --leak-check=full --show-leak-kinds=definite --errors-for-leak-kinds=definite --error-exitcode=99)
"$vg" "${args[@]}" --log-file=build/valgrind.log "${PHP_BIN:-php}" -n -d extension="$extension" tests/stress.php 3 > build/valgrind-stress.json
"$vg" "${args[@]}" --log-file=build/valgrind-shutdown.log "${PHP_BIN:-php}" -n -d extension="$extension" tests/request_shutdown.php
"$vg" "${args[@]}" --log-file=build/valgrind-network.log "${PHP_BIN:-php}" -n -d extension="$extension" tests/network_memory.php
"$vg" "${args[@]}" --log-file=build/valgrind-memory.log "${PHP_BIN:-php}" -n -d extension="$extension" tests/native_memory.php
"$vg" "${args[@]}" --log-file=build/valgrind-files.log "${PHP_BIN:-php}" -n -d extension="$extension" tests/file_handles.php
./scripts/test-databases.py --valgrind
