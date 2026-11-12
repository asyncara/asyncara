#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
python3 scripts/check-build.py "${ASYNCARA_EXTENSION:-build/asyncara.so}"
./scripts/prepare-test-certs.sh
python3 tests/http/peer_shutdown.py tests/http/capacity_server.py
"${PHP_BIN:-php}" -n -d memory_limit=1G -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" "$@" tests/run.php
"${PHP_BIN:-php}" -n -d memory_limit=256M -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/native_limits.php
"${PHP_BIN:-php}" -n -d memory_limit=256M -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/native_memory.php
python3 scripts/test-fatal-memory.py
"${PHP_BIN:-php}" -n -d memory_limit=256M -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/file_handles.php
"${PHP_BIN:-php}" -n -d memory_limit=256M -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/filesystem_paths.php
"${PHP_BIN:-php}" -n -d memory_limit=256M -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/io_lifecycle.php
"${PHP_BIN:-php}" -n -d memory_limit=256M -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/resource_copy.php
"${PHP_BIN:-php}" -n -d memory_limit=256M -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/resource_bounds.php
"${PHP_BIN:-php}" -n -d memory_limit=256M -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/connection_limits.php
"${PHP_BIN:-php}" -n -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/database/credential_traces.php
"${PHP_BIN:-php}" -n -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/database/redis_push_protocol.php
"${PHP_BIN:-php}" -n -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/stream_readiness.php
"${PHP_BIN:-php}" -n -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/stream_close.php
"${PHP_BIN:-php}" -n -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/udp_ownership.php
"${PHP_BIN:-php}" -n -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/udp_destinations.php
python3 scripts/test-udp-limits.py
"${PHP_BIN:-php}" -n -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/filesystem_atomic.php
"${PHP_BIN:-php}" -n -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/filesystem_buffer_ownership.php
"${PHP_BIN:-php}" -n -d memory_limit=64M -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/http_streaming.php
"${PHP_BIN:-php}" -n -d memory_limit=128M -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/http/completion.php
"${PHP_BIN:-php}" -n -d memory_limit=128M -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/http/acquire_cancel.php
"${PHP_BIN:-php}" -n -d memory_limit=128M -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/http/buffered_owner.php
"${PHP_BIN:-php}" -n -d memory_limit=128M -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/http/options_lifetime.php
"${PHP_BIN:-php}" -n -d memory_limit=128M -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/http/headers.php
"${PHP_BIN:-php}" -n -d memory_limit=128M -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/http/capacity.php
"${PHP_BIN:-php}" -n -d memory_limit=128M -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/http/gate_protocol.php
"${PHP_BIN:-php}" -n -d memory_limit=128M -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/http/wait_observer.php
"${PHP_BIN:-php}" -n -d memory_limit=128M -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/http/proxy.php
"${PHP_BIN:-php}" -n -d memory_limit=128M -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/http/pooling.php
"${PHP_BIN:-php}" -n -d memory_limit=128M -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/http/buffer_budget.php
"${PHP_BIN:-php}" -n tests/websocket_protocol.php
"${PHP_BIN:-php}" -n -d memory_limit=256M -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/fuzz/resp_regressions.php
"${PHP_BIN:-php}" -n -d memory_limit=256M -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/fuzz/ipc_regressions.php
