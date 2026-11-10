#!/usr/bin/env bash
# Opt-in experimental build. Cargo/toolchain must already be installed locally.
set -euo pipefail
cd "$(dirname "$0")/.."
: "${CARGO_TARGET_DIR:?Set an isolated Cargo output directory}"
: "${ASYNCARA_OUTPUT:?Set a distinct extension output path}"
cargo build --release --locked --manifest-path ext/hyper/Cargo.toml
export EXTRA_CFLAGS="${EXTRA_CFLAGS:-} -DHAVE_ASYNCARA_HYPER=1"
export EXTRA_LDFLAGS="${EXTRA_LDFLAGS:-} $CARGO_TARGET_DIR/release/libasyncara_hyper_bridge.a -ldl -lm -lz"
exec bash scripts/build-local.sh
