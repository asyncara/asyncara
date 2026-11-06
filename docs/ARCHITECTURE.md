# Architecture

The official direction is [Native Async API First — Optional Transparent
Compatibility](NATIVE_API_FIRST.md). Existing explicit APIs are the primary
product; hooks are optional adapters and are not prerequisites for core I/O.

Asyncara has one native extension and a strict-typed Composer library. Native code owns OS readiness, native work queues, curl handles, SQLite connections and native buffers. PHP owns Fiber scheduling, Tasks/Futures, cancellation, structured scopes and public APIs. This keeps Zend object lifecycle on its interpreter thread and permits testing scheduling independently from native drivers.

The native boundary uses operation IDs and copied scalar inputs. Worker threads never call Zend, access request zvals, or execute PHP callbacks. Completed native results are converted to PHP values only on the loop thread. Native request shutdown joins workers before freeing their queues or database connections. Limits apply before submission and while accumulating responses. An atomic allocation ledger bounds retained native payloads/metadata independently of PHP memory_limit. Job lifetime tracking extends through result conversion so Zend bailout leaves resources reachable by request shutdown.

The scheduler uses a FIFO ready queue, monotonic timer heap, removable wait subscriptions and one readiness wait per turn. Ready work is processed in bounded batches so timers and I/O get turns. Native epoll waits on sockets and the worker notification pipe. A poll backend supports a fallback. curl multi integrates its sockets and nearest deadline with the same wait. There is no secondary busy-polling HTTP loop.

Tasks run in ordinary PHP Fibers. A Future stores one terminal result and permits repeated awaits. Tasks inherit a shallow copy of explicit Context values. PHP globals and objects referenced by those values are not isolated. Cancellation is observed at suspension boundaries. Native operations may finish after their waiter stops waiting; results and resources still require draining. Structured groups join all children and cancel siblings on failure.

SQLite uses one native connection per connection ID and rejects overlapping native operations. The PHP connection queue retains ownership through native completion, including cancellation. Transactions require an exclusive connection scope. Process workers use proc_open with argument arrays, explicit bootstrap/registry and bounded JSON frames. They do not fork a live threaded interpreter or deserialize PHP objects.

Transparent hooks are opt-in and limited to individually verified operations. An engine execute-internal dispatch hook chains the pre-existing handler; no internal function handler is patched. No proposed PHP RFC or new grammar is required. Unsupported operations are reported honestly.

Stable regular-file descriptors have a serialized per-handle work gate, worker-backed seek/sync and request-shutdown ownership. File producer streams keep the descriptor until completion or cancellation and join cleanup in their scheduled producer.

PostgreSQL uses libpq nonblocking connection/query polling; MariaDB uses Connector/C start/cont state machines. Redis uses a bounded RESP parser over nonblocking TCP. Their wire protocols retain independent driver policies.

Backend replacement is confined to the native wait boundary. io_uring, kqueue, IOCP, additional DB protocol modes and framework lifecycle integrations must earn capability flags through implementations and tests.
