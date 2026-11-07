#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
# Run only after the protected local endurance campaign is terminal.
# All outputs and headers are local to this checkout; never install this binary.
mkdir -p build
output="$(mktemp -d "$PWD/build/native-close-XXXXXXXX")"
"${CC:-cc}" -std=c11 -D_GNU_SOURCE -g -O1 -Wall -Wextra -Werror -fno-omit-frame-pointer \
  -fsanitize=address,undefined -I.local/sysroot/usr/include -I.local/sysroot/usr/include/x86_64-linux-gnu \
  tests/audit/close_cancellation.c ext/workers.c ext/backend.c ext/memory.c -pthread \
  -Wl,--wrap=pthread_create -l:libsqlite3.so.0 -l:libcurl.so.4 -o "$output/close-cancellation"
ASAN_OPTIONS=detect_leaks=1:halt_on_error=1 UBSAN_OPTIONS=halt_on_error=1 \
  "$output/close-cancellation"
