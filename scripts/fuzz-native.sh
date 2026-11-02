#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
seconds=${1:-10}
output=${2:-build/fuzz-native-$(date -u +%Y%m%dT%H%M%SZ)}
mkdir -p "$output/corpus" "$output/artifacts"
cp tests/fuzz/corpus/native/* "$output/corpus/"
"${FUZZ_CC:-clang}" -std=c11 -D_GNU_SOURCE -O1 -g -Wall -Wextra -Werror \
  -fno-omit-frame-pointer -fsanitize=fuzzer,address,undefined \
  -I.local/sysroot/usr/include -I.local/sysroot/usr/include/x86_64-linux-gnu \
  tests/fuzz/native_allocations.c ext/workers.c ext/backend.c ext/memory.c \
  -pthread -l:libsqlite3.so.0 -l:libcurl.so.4 \
  -Wl,--wrap=malloc,--wrap=calloc,--wrap=realloc -o "$output/target"
ASAN_OPTIONS=detect_leaks=1:halt_on_error=1 UBSAN_OPTIONS=halt_on_error=1 \
  "$output/target" "$output/corpus" -max_total_time="$seconds" -max_len=4096 \
  -timeout=5 -rss_limit_mb=512 -print_final_stats=1 -seed=20261102 \
  -artifact_prefix="$output/artifacts/" 2>&1 | tee "$output/run.log"
