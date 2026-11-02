#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p build
cc -std=c11 -D_GNU_SOURCE -g -O1 -Wall -Wextra -Werror -fno-omit-frame-pointer \
  -fsanitize=address,undefined -I.local/sysroot/usr/include -I.local/sysroot/usr/include/x86_64-linux-gnu \
  tests/audit/atomic_write.c ext/workers.c ext/backend.c ext/memory.c -pthread \
  -l:libsqlite3.so.0 -l:libcurl.so.4 -Wl,--wrap=fsync,--wrap=renameat,--wrap=malloc -o build/atomic-failures
ASAN_OPTIONS=detect_leaks=1:halt_on_error=1 UBSAN_OPTIONS=halt_on_error=1 build/atomic-failures
