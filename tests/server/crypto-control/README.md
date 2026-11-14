# TLS memory controls

These isolated programs use the public cryptographic library APIs without PHP,
Asyncara, Hyper or socket adapters. No suppressions are used. Their result is
not a substitute for testing the server adapter.

Build exactly one provider into a new output directory:

```sh
CARGO_TARGET_DIR=/tmp/new-ring-controls cargo build --release --locked \
  --manifest-path tests/server/crypto-control/Cargo.toml
# Independent alternative:
CARGO_TARGET_DIR=/tmp/new-aws-controls cargo build --release --locked \
  --manifest-path tests/server/crypto-control/Cargo.toml --no-default-features --features aws
```

The dependency runner generates a private test CA, compiles the three direct
controls (two for AWS-LC), and records ordinary and unsuppressed Valgrind exits.
It refuses an existing result directory. Set RUSTUP_HOME/CARGO_HOME and
VALGRIND_LIB only when using an isolated toolchain that requires them.

```sh
python3 tests/server/dependency_memory.py --provider ring \
  --deps /tmp/new-ring-controls/release/deps --out /tmp/new-ring-results
python3 tests/server/dependency_memory.py --provider aws \
  --deps /tmp/new-aws-controls/release/deps --out /tmp/new-aws-results
```

Pass --valgrind, --rustc and --extra-debuginfo-path for local tools. The locked
bridge's release/deps directory can also be used for the AWS provider. AES128,
AES256 and ChaCha20 handshake controls verify exact plaintext in both directions.
The AEAD-only control has fixed inputs and does not perform a handshake.

The retained ring/AES findings remain FAIL; retain the raw control reports separately; see https://asyncara.github.io/docs/guide/operations/diagnostics/.
Do not classify those warnings as harmless or suppress them to obtain PASS.
