#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p build
cc -std=c11 -D_GNU_SOURCE -Wall -Wextra -Werror -O1 -g \
  -fsanitize=address,undefined -fno-omit-frame-pointer \
  -I.local/sysroot/usr/include -I.local/sysroot/usr/include/x86_64-linux-gnu \
  tests/audit/mappings.c ext/memory.c -pthread -Wl,--wrap=mmap -o build/native-mappings
export ASAN_OPTIONS=detect_leaks=1:halt_on_error=1 UBSAN_OPTIONS=halt_on_error=1
build/native-mappings
set +e
build/native-mappings invalid-tail > build/native-mapping-negative.log 2>&1
status=$?
set -e
if [[ "$status" != 1 ]] || ! grep -q 'ERROR: AddressSanitizer: use-after-poison' build/native-mapping-negative.log; then
  cat build/native-mapping-negative.log
  echo 'Mapped tail overrun was not diagnosed by ASan' >&2
  exit 1
fi
echo 'ASan mapped-buffer overrun negative control: PASS'
