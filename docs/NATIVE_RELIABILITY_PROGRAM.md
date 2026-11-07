# Native reliability program

Release: **EXPERIMENTAL**. Native Async API First; no hook/backend expansion.
This continuation starts at `f2c0b83be64597db994e2a9b3df6383c197fd540`.
Evidence root: `/home/coder/Asyncara-native-program-20261107T173550Z`.
`initial.json` verifies the previous 399-file archive byte-for-byte against
`7c0fbda`, its published checksum, the current module and twelve protected files.
Phase 4 remains unchanged. `state.json` and `journal.md` record execution.

## Historical zero-tick investigation

The original one-hour PASS, six-hour FAIL (196,536 persisted main responses),
24-hour NOT_STARTED and old 7,980-response INCOMPLETE gate retain their statuses.
The failed request has four overlapping approximately 200ms peer intervals,
430.299ms elapsed, 125.596ms scheduled CPU, 97.937ms runnable wait, three schedule
slices, 138.280ms maximum task step and 276.595ms maximum timer lag. These aggregate
measurements do not locate the missing dispatch interval. No original per-wait
requested/actual timeout and surrounding execution trace exists.

| Hypothesis | Evidence and control | Conclusion |
|---|---|---|
| Worker wake notification lost after EINTR | Existing wrapped-write negative controls fail before 8263fcd, pass after; workers mask asynchronous signals; finite timer still bounds a missed pipe wake | Separate proven injected-error defect, not historical attribution |
| Cancelled close skips cleanup | 6ee73d8 negative controls; modifies worker cleanup only | Separate resource correction, does not change HTTP/timer dispatch |
| Broken timer heap or pending-read dispatch | Existing virtual scheduler and real-kernel two-tick barriers, multiple seeds and PHP versions | Tested paths pass; cannot exclude every interleaving |
| Interpreter unavailable while HTTP completes | `dispatch_control.py` r2 holds four HTTP operations behind a peer-arrival handshake, then injects 300ms interpreter delay before the real native wait: zero ticks on both backends; normal/recovery controls pass | Demonstrates the exact symptom without changing production code. Diagnostic counterexample, not proof of the historical cause |
| Host descheduling | Original scheduled/runnable totals and separate owned-child stop control (one tick in eight trials) | Plausible contributor; source of original delay remains unresolved |
| Native wait preparation restarts timer budget | `wait_budget.c` advances a test-only monotonic clock during allocation/epoll registration, still executes real kernel waits. Original requests another 5ms after budget expired | Confirmed additional delay defect; corrected here. No evidence that this accounts for the original 276ms lag |

The PHP scheduler processes readiness/completions before the next timer dispatch.
If interpreter execution resumes after both deadlines and HTTP completion,
producer Tasks can complete before the ticker checks its pending predicate.
Moving timer priority solely to force a tick would change semantics and would
not recover real progress during the missed interval. No such change is made.

## Native correction

`ext/backend.c`: `ar_wait`, `wait_fds`, new private deadline helpers preserve an
absolute monotonic deadline from native entry through preparation. Remaining
milliseconds round upward at the actual syscall boundary. Zero/infinite waits,
completed-worker and buffered-stream immediate returns remain unchanged. Curl's
own shorter deadline is bounded separately. Clock failures propagate with errno;
transient buffers and epoll descriptors are released. Large timeout conversion
is clamped, arithmetic overflow rejected. No polling loop, sleep or worker added.

`tests/fast/wait_budget.c`: original negative control exits 2 (expected 0ms,
actual 5ms); corrected 10,000 iterations per backend pass, covering elapsed
allocation and epoll preparation, rounding, zero/infinite and INT_MAX budgets.
Test-only clock/link wrappers are absent from the extension. Existing real-time
pending-I/O tests separately validate actual kernel waits. Gate A initially
passes in 169.777s; subsequent complete gate results will be appended.

## Revised gates (execution evidence pending below)

`tests/fast/pipeline.py A|B1|B2|B|C --php PATH --extension PATH --output NEW_DIR`.
Set `FPM_BIN` to the isolated PHP-FPM binary for B/B2/C. Every output directory
is unique; active process command/PID, source manifest, binary checksum, command
exit, wall/CPU time and log hashes are recorded. C requires `--prior-a` and
`--prior-b` directories with matching source/module and completed PASS results.

B1 counts actual kernel wait calls at test linker boundaries, changing real
completion queues, readiness, partial reads, descriptor reuse and HUP. Injected
EINTR returns and virtual clock calls are excluded. It also runs three seeded
PHP kernel workloads/backend and file/SQLite cancellation/cleanup cycles.
PHP timer/Fiber transitions are reported separately from native waits.

B2 uses the historical A/B/C/D fixture unchanged, then a derived fixture with
additional real socket/timer/cancellation work inside each HTTP request. All
original statements and assertions remain, including four HTTP operations,
200ms peer delay, two ticks while pending, overlapping intervals, fatal/pending
shutdown, request isolation and resource thresholds. Added density contributes
64 pending reads, 48 completions, 16 cancellations, 8 timer expirations and 128
endpoint closes per I/O request. These are not additional FPM responses.
Arrival order cycles three deterministic seeds; resource summaries are
independently recalculated from raw samples. Default duration is 600 seconds.

C repeats larger kernel/resource workloads and 1,800 seconds of real FPM after
A/B pass, to observe residual growth/scheduling behavior. It is not 24-hour
endurance certification. A short smoke pass does not complete B2 or C.
