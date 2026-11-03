# API semantics

All public classes live under `Asyncara`. See source signatures and API_REFERENCE.md for the complete method listing. This library uses synchronous-looking Fiber suspension; it introduces no language keywords.

## Runtime, tasks and futures

`Runtime::configure(['taskLimit'=>20000,'slowTaskSeconds'=>0.05,'nativeMemoryLimit'=>268435456])` must be called before `Async::run()`; the native memory limit must also precede the first native submission. See BACKENDS.md for the budget's precise coverage. The task limit bounds live tasks, not bytes; configure it below the PHP memory limit. A 10,000-Fiber stress run requires substantially more than the default 128 MiB. `Runtime::information()` reports actual compiled/running backends; `Runtime::diagnostics()` returns current or last-run metrics. `strictBlockingDetection(true)` enables a 50 ms **measured task-step warning**, not interception of unknown blocking functions.

`Async::run(callable): mixed` creates one scheduler, runs a root task, and joins outstanding scheduled work. Nested runs are rejected. Root failure cancels remaining tasks. Unobserved task failures propagate at the runtime boundary. A task that never yields can hold the thread indefinitely. Returning while a repeating task or timer is active keeps the runtime alive; cancel it explicitly.

`Async::spawn(callable, bool $inheritContext=true): Task` queues eager work for the next scheduling turn. PHP execution is cooperative, not parallel. `Task::await()` can be called repeatedly, and completed Futures can be read outside a runtime. A pending await requires a scheduled task. Result values and exceptions are retained by the Future until it is collected; objects in results are shared references. `result()` throws if unfinished or rejected; `exception()` observes its stored exception; state queries do not consume results.

`Future::await(?CancellationToken)` cancels only that wait. It does not implicitly cancel shared work. `Async::await(Future)` is for an **owned** operation: cancellation cancels and joins unfinished work. `Task::cancel()` requests cooperative cancellation; `Future::cancel()` requests its driver-specific cancellation if available. Native operation Futures are eagerly submitted and retained by the scheduler until native completion, including abandoned Futures. Their buffers are freed when completion is drained; abandoned success/error results are discarded. Abandoned native Future errors are not currently reported as unobserved task failures.

Public `resolve`, `reject`, `subscribe`, native submission methods, and Task bookkeeping fields are infrastructure hooks marked internal. Consumers should use Tasks/driver Futures rather than mutate those fields. Resolving a Future twice throws. Completion subscribers must not throw or suspend.

## Cancellation, time and scopes

`CancellationSource` owns a `token`; `cancel()` marks it once. `throwIfRequested()` observes it. Default waits use the current task's token. Cancellation is checked at awaits, timers, I/O waits and synchronization acquisition; arbitrary PHP CPU loops are not preempted.

`Async::sleep(float)` uses monotonic time. `callLater(float, callable)` returns a cancellable one-shot Timer. `repeat(float, callable)` returns a cancellable Task with fixed-delay repetition after each callback. Callback failures propagate as task failures. Delays must be finite, nonnegative and at most one year.

`Async::timeout(seconds, callable)` owns a child task, requests cancellation on deadline and joins its cleanup. It throws TimeoutException. Actual completion can exceed the deadline when cleanup or a noninterruptible native operation must finish. `Async::shield(callable)` defers the current task's default cancellation inside the callback; it is for bounded cleanup, not hiding cancellation indefinitely.

`gather(array)` preserves keys and waits in input order. It throws on the first awaited failure; it does not automatically cancel other entries. `wait(array, ?timeout, all:true)` returns WaitResult with completed and pending entries; timeout leaves work alive. `asCompleted(array)` yields Future objects in observed completion order. Await each yielded Future to obtain or throw its result. TaskResult::from() captures a value or error explicitly.

`taskGroup(callable)` joins every spawned child, propagates failure and cancels siblings when a child fails. Parent body failure also cancels and joins children. Nested groups retain their own scope. `using(acquire, body, release)` shields acquisition through completion, checks cancellation before entering the body, and releases in a cancellation shield on success, exception or cancellation. Acquisitions and releases may return Awaitables. Cancellation during acquisition can delay the deadline, but a successfully acquired resource is still released.

## Synchronization

All primitives coordinate Fibers within one interpreter. They are not cross-process locks.

- Lock: non-reentrant, task-owned; `acquire`, `release`, `synchronized`. Only its owner can release.
- Semaphore: positive capacity, FIFO waiting, `acquire`, `release`, `withPermit`; over-release throws. Permit release is guaranteed by withPermit's finally block.
- Event: manual-reset; set releases existing waiters and future waiters until reset. Reset does not retract an already delivered notification.
- Queue: capacity defaults to 100; capacity 0 explicitly means unbounded. `put`/`get` suspend only their caller. `tryPut` never suspends. Accepted items increment unfinished work, `taskDone` decrements it and `join` waits for zero. `close` rejects pending puts/gets, preserves buffered items for draining, and rejects get once drained. Closing does not forgive unfinished accepted items. Cancellation removes a waiter synchronously so it cannot steal an item or permit later.

## Context and iteration

Context get/set/all operate on the current task's explicit map. A child receives a shallow copy at spawn time; object values remain shared. `Context::isolated` temporarily replaces the current map and restores it in finally. Globals, static properties, output buffers, sessions and extension-owned state are not automatically isolated. Trace/logging IDs are ordinary map values.

`AsyncStream::fromProducer(callable, capacity:16)` supplies an Emitter with blocking `emit`. The stream permits one consumer and provides bounded backpressure. Producer failure reaches the consumer. Generator finalization only requests producer cancellation and closes the queue; it never suspends in a destructor. `consume(callable)` and explicit `close()` join producer cleanup. Use these or `Async::using` when cleanup must complete at an early loop exit. The enclosing runtime also joins the scheduled producer.

## Native and process operations

See DRIVERS.md for IO/database methods. `ProcessPool(workers, bootstrap, queueLimit:256)` requires a trusted bootstrap returning a registry mapping names to callables. Submit a registry name or `[ClassName::class,'method']` matching a registry key, arguments and timeout. Closures are not transferred. JSON-compatible inputs are copied; PHP objects/resources and invalid encodings are rejected. Jobs run in independent interpreters, not native worker threads. A crashed/cancelled/timed-out worker is discarded and replaced; the failed job is never silently replayed. `close()` cancels pending jobs, joins them, sends EOF to idle workers, and terminates workers that fail to exit within the shutdown grace period.

`ConnectionPool(factory, closer, size:8)` lends exclusive connections through withConnection. Factory results may be Futures. A borrower exception discards its connection, allowing the next borrower to create a replacement. Close the pool after borrowers finish. No transaction or command is automatically retried.
