# Backends and limits

`Runtime::information()` obtains runtime facts from the extension. It reports eventLoopBackend, filesystemBackend, networkBackend, dnsBackend, sqliteVersion, curlVersion, nativeWorkers, pendingOperations, queue/buffer caps, nativeMemoryBytes/nativeMemoryPeak/nativeMemoryRejections/nativeMemoryLimit, openFileHandles and optional SQL compilation flags. Compile flags are not a claim that a remote deployment's authentication/TLS combinations were tested.

Linux epoll is the default. Set `ASYNCARA_BACKEND=poll` before the first capability call or operation to choose poll. Both wait on socket readiness and a nonblocking worker completion pipe; native curl socket interests and its next timeout join that wait. Interrupted waits return to scheduler processing. Rebuilding epoll registrations on each wait is deliberately simple but has overhead; persistent registration is future performance work. Stream descriptors in one wait are deduplicated so concurrent read/write interests can coexist.

The PHP scheduler processes at most 256 ready tasks per turn, then checks timers and readiness. Timers use hrtime's monotonic clock and a priority heap. Cancelled heap entries are pruned and periodically compacted. Deadlock detection recognizes suspended tasks with no ready work, timers, native work or registered I/O; it cannot prove all circular dependencies or detect a peer that never responds.

The native pool creates up to four pthreads lazily per PHP request. The bounded job queue is request-owned. Filesystem and SQLite operations use those threads; getaddrinfo is a worker fallback. Worker inputs/results contain malloc-owned data, not PHP pointers. The loop thread marshals results. Shutdown requests cancellation, joins workers, then frees jobs, database handles and buffers. libc DNS and blocked storage calls can delay that join; no unsafe thread termination is attempted.

Current limits:

| Resource | Limit |
|---|---|
| live Tasks | configurable, default 20,000; memory must be budgeted separately |
| active timers | 100,000 |
| native pending operations | 1,024 |
| retained Asyncara native allocations | default 256 MiB; configurable 1 MiB–1 GiB before submission |
| stable native file handles | 128 |
| native wait descriptors | 16,384 including curl/notification descriptors |
| file/HTTP/IPC payload | generally 16 MiB per buffered operation/frame |
| HTTP response headers | 64 KiB |
| native HTTP connections | 64 total, eight per host |
| HttpClient in-flight admission | configurable 1–256, default 32 |
| native SQLite connections | 128 active |
| DB buffered rows | 100,000 and driver-specific byte cap |
| process workers | 1–64 |
| submitted process jobs | configurable, default 256 |
| Redis pipeline | 10,000 commands / 16 MiB request |
| Redis parse | depth 32 / 100,000 elements / 16 MiB reply |

`Runtime::configure(['nativeMemoryLimit' => 67108864])` sets a 64 MiB aggregate budget before the first native operation. Atomic accounting covers retained Asyncara-owned jobs, input/result trees, file/HTTP buffers and native connection metadata, including allocator headers. Growth reservations fail before exceeding the budget; completion/error cleanup releases them. The limit becomes immutable on first submission. `nativeMemoryPeak` measures peak reserved bytes; `nativeMemoryRejections` counts budget rejections.

This is **not a whole-process memory limit**. Third-party curl/SQLite/OpenSSL/SQL-connector internals, thread stacks, the small engine bootstrap and transient bounded readiness buffers are excluded. Readiness control buffers are separately bounded to under 1 MiB at the descriptor cap, so budget exhaustion cannot prevent draining/cancellation. Zend allocations remain subject to PHP's memory_limit. Applications still need conservative concurrency, database/server limits and OS process/container quotas for hostile or dense multi-tenant workloads.

io_uring, kqueue and IOCP remain absent. The initialization host's liburing NOP test completed successfully; implementing and validating actual file operations, cancellation and kernel feature fallbacks is still required. No released PHP scheduler RFC is assumed. PHP threading capability remains false even on an untested ZTS build until an interpreter-thread executor is implemented and validated.
