#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if [[ ! -f .local/ws-peer-17.4/.verified-sha256 ]]; then
  echo 'Install the isolated pinned peer: python3 scripts/install-test-websockets.py' >&2
  exit 1
fi
./scripts/prepare-test-certs.sh
"${PHP_BIN:-php}" -n tests/websocket_protocol.php
"${PHP_BIN:-php}" -n -d memory_limit=128M -d extension="$PWD/${ASYNCARA_EXTENSION:-build/asyncara.so}" tests/websocket.php
