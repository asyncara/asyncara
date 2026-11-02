#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
python3 scripts/check-build.py "${ASYNCARA_EXTENSION:-build/asyncara.so}"
./scripts/prepare-test-certs.sh
"${PHP_BIN:-php}" -n -d memory_limit=1G -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" "$@" tests/run.php
"${PHP_BIN:-php}" -n -d memory_limit=256M -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/native_limits.php
"${PHP_BIN:-php}" -n -d memory_limit=256M -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/native_memory.php
python3 scripts/test-fatal-memory.py
"${PHP_BIN:-php}" -n -d memory_limit=256M -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/file_handles.php
"${PHP_BIN:-php}" -n -d memory_limit=256M -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/resource_bounds.php
"${PHP_BIN:-php}" -n -d memory_limit=256M -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/fuzz/resp_regressions.php
"${PHP_BIN:-php}" -n -d memory_limit=256M -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/fuzz/ipc_regressions.php
