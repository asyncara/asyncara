# Development

Read docs/ARCHITECTURE.md, RESEARCH.md, SECURITY.md and WORKLOG.md before changing native boundaries. Native ownership is the central invariant: workers own copied C data and never enter Zend; request-thread code alone owns PHP resources and callbacks.

Use `make build`, `make test`, `make databases`, `make stress`, `make sanitize`, `make thread-sanitize` and `make fpm`. `make valgrind` requires locally extracted Valgrind/glibc debug packages. Tests fail with nonzero exit status and are not assertion-configuration dependent. Local HTTP/TLS servers and isolated DB fixtures avoid public-service dependencies. All fixture data stays in build/ or randomized temporary directories. Preserve logs for failures. The 10,000-Fiber test explicitly needs more than PHP's default memory budget.

For version checks, use matching PHP_INCLUDE_DIR/PHP_BIN and a distinct ASYNCARA_OUTPUT. Run both suites with that interpreter; compilation alone is not compatibility evidence. Run poll fallback separately. Do not update a tested status without recording the command, version and result.

C formatting follows .clang-format. Keep native state machines readable and specify which thread can read/write each field. Retained native allocations use ar_alloc/ar_calloc/ar_realloc/ar_free and a live engine owner. Only the bounded control-plane buffers deliberately bypass this budget. Review input lengths, integer conversions, error cleanup, close while waiting, cancellation before/after handoff and request shutdown. Exercise meaningful failure paths, not tests that only mirror implementation.

The test harness uses no PHPUnit dependency. Composer dev dependencies are limited to PSR adapter fixtures and AMP/Revolt benchmark comparisons. Additional dependencies need a concrete capability and license rationale.

Benchmark equivalent workloads, preserve all samples and disclose setup/runtime limits. Do not infer production readiness from a successful microbenchmark. Status vocabulary in ROADMAP.md is mandatory. Add public behavior and limitations to the API/compatibility docs together with code changes. Do not modify global host settings or install dependencies with sudo from project scripts.
