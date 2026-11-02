#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p build
cc -std=c11 -D_GNU_SOURCE -O1 -g -Wall -Wextra -Werror \
  -fsanitize=address,undefined -fno-omit-frame-pointer \
  -I.local/sysroot/usr/include -I.local/sysroot/usr/include/x86_64-linux-gnu \
  tests/audit/worker_signals.c ext/workers.c ext/backend.c ext/memory.c \
  -pthread -l:libsqlite3.so.0 -l:libcurl.so.4 -o build/worker-signals
ASAN_OPTIONS=detect_leaks=1:halt_on_error=1 UBSAN_OPTIONS=halt_on_error=1 build/worker-signals
