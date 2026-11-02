# Result provenance

`latest.json` is the current verified-source run. It contains all three samples per workload, the extension artifact hash, native and userland source hashes, runtime capabilities and compiler/platform metadata. Regenerate with `./benchmarks/run.py` after a successful build.

`before-small-read-allocation.json` is an early development measurement before later allocation and lifetime changes. `stale-artifact-run.json` is deliberately retained as an invalid source-validation experiment: a failed compiler command was followed by a benchmark of an older binary. Do not use it as evidence about current source or claim a before/after optimization from it. Build freshness checks and fail-fast execution now prevent this error in the runners.

See `docs/PERFORMANCE.md` for workload comparability and measurement limits. These are local development measurements, not a production benchmark ranking.
