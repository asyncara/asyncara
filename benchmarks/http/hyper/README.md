# Hyper feasibility experiment

This executable measures Rust Hyper directly, with no PHP bridge. It is **not**
an implemented Asyncara backend and cannot establish PHP throughput claims.
Use it to decide whether the protocol engine is worth a separate integration
project. No production source links to Rust; installation remains C/PHP/curl.

The workload uses one Tokio current-thread runtime, exactly N established
HTTP/1.1 connections, ten warmup requests each, bounded complete bodies, and
validation of every status/body. Numeric loopback avoids hidden DNS workers.
TLS, proxies, redirects, compression, uploads, cancellation and PHP-FPM ownership
are outside this experiment. A future bridge must preserve those existing
capabilities and be independently measured, including allocation/conversion and
Fiber scheduling costs. A blocking Rust `block_on()` inside a PHP request would
not be acceptable as Asyncara's asynchronous HTTP integration.

```sh
CARGO_TARGET_DIR=/isolated/build cargo build --release --locked --manifest-path benchmarks/http/hyper/Cargo.toml
/isolated/build/release/asyncara-hyper-evaluation http://127.0.0.1:PORT/body?size=1024 4000 8 1024
```

Dependencies are recorded in Cargo.lock; their upstream licenses are separate
from Asyncara's project license. Hyper's existing C API is explicitly unstable;
using it would require a separately versioned and tested ownership boundary.
Sources: [Hyper client guide](https://hyper.rs/guides/1/client/basic/),
[Hyper C API](https://docs.rs/hyper/latest/hyper/ffi/index.html),
[Tokio runtime builder](https://docs.rs/tokio/latest/tokio/runtime/struct.Builder.html).
