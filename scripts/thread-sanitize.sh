#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p build
# GCC TSan on this host conflicts with randomized mappings. Non-PIE and a
# process-local personality avoid this; system-wide ASLR remains unchanged.
cc -std=c11 -D_GNU_SOURCE -g -O1 -Wall -Wextra -Werror -fno-omit-frame-pointer \
  -fsanitize=thread -no-pie -I.local/sysroot/usr/include -I.local/sysroot/usr/include/x86_64-linux-gnu \
  tests/native_sanitizer.c ext/workers.c ext/backend.c ext/memory.c -pthread \
  -l:libsqlite3.so.0 -l:libcurl.so.4 -o build/native-tsan
setarch "$(uname -m)" -R env TSAN_OPTIONS=halt_on_error=1 build/native-tsan
