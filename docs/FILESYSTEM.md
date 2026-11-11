# Filesystem behavior

## Filename capture at admission (2026-11-11)

Status: **IMPLEMENTED_AND_TESTED** within the filename ownership and cleanup
scope below. Native source `141174a`; final FPM test source `1d08d90`.

`File::open/stage`, `Files::exists/readStream/writeStream`, SQLite `Connection::open`
and HTTP file transfers capture the caller's filename before a Task, producer or
client permit can defer native submission. Previously, a later `chdir()` could
redirect the operation to another file. The matched control preserves 28 failures
before the fix and 30 passes afterward, including two already-correct immediate
native read controls.

`ext/php_asyncara.c::capture_php_path()` uses a bounded stack buffer and PHP's
virtual cwd; `asyncara_file_path()` returns a PHP-owned absolute filename. It
preserves lexical symlink/`..` semantics and ordinary POSIX filename bytes. It
does not create a worker job or open a file. Filename capture and open_basedir
validation are synchronous and may perform filesystem metadata checks; actual
file I/O remains on bounded pthread workers. Worker admission rechecks current
path policy. SQLite `:memory:` retains its existing semantics.

Invalid path input may now throw before returning a Future. Rejected
`Files::writeStream()` admission closes an already-owned ReadableStream producer.
The operation owns a **filename**, not an inode, contents or certificate bytes.
Symlink mutation and file replacement remain possible; this is not an atomic
sandbox against a hostile filesystem. Stream chunks, backpressure, cancellation,
file handle lifetimes and atomic-publication semantics are unchanged.

| Validation | Actual result |
|---|---|
| PHP 8.3.36, 8.4.27, 8.5.12 NTS, DEBUG 8.5.12, GCC ASan/UBSan 8.5.12; epoll/poll | PASS: 45 commands, 220 PHP executions, 170.174s |
| Ordinary-unload DEBUG Valgrind, both backends | PASS: 44 processes, zero errors/definitely/indirectly/possibly lost bytes, 334.439s; 3,888 reachable bytes per process, 4,066 for Hyper file transfers |
| Gate A | PASS: 51 commands, 222.338s |
| Complete PHP 8.5 suites, both backends | PASS; allocation audit, database bounds and isolated plain database regression also PASS |
| Complete HTTP gate, both backends/transports | PASS: 97 commands, 173.206s |
| Original FPM A/B/C/D plus deferred-path cases, both backends | PASS: 388 main responses, 752 HTTP operations, 720 path checks; minimum 12 pending-I/O ticks in applicable I/O requests |
| C-only build and filesystem/curl path controls, both backends | PASS; no Rust backend dependency |

FPM independently checked raw request records, peer overlap, source/module
fingerprints, request owner reset, fatal-memory staged-file cleanup and idle
six descriptors/one socket/one thread. The original sequential RSS threshold
remains a strict 4,096 KiB range after warmup where enough samples exist; shorter
and parallel cases provide snapshots, not long-run trend certification. Fatal
same-worker recovery is asserted by the original runner; recovery responses are
not separately retained in its JSON. Original lifecycle requests have no HTTP
ticker requirement; their zero ticks are intentional. No assertion was relaxed.

Evidence: [final attestation](phase7/filesystem-admission.json) contains exact
commands, results, hashes and source manifests. The earlier
[stop checkpoint](phase7/filesystem-admission-checkpoint.json) and all failures
remain historical evidence. The stream-trust-r1 archive predates this fix.
The first supplemental FPM reports are **not accepted** as fatal-memory evidence:
their staged-file glob required a hyphen absent from the actual native name, and
the generic runner accepted the resulting HTTP 500 precondition exception. The
separate rejected review is preserved. Test-only `1d08d90` corrects the observer,
proves the atomic-cleanup oracle sees a live stage, rejects wrong fatal types and
requires the actual memory-exhaustion log without warnings. Five parser controls
and repeated complete PHP85/FPM runs pass; only r2 supplies final fatal evidence.
Original fixtures, runtime, workload and thresholds were unchanged.
Independent packaging is a separate checkpoint, not implied by these results.

Reproduce against an explicitly selected module and fresh output directories:

```sh
python3 tests/filesystem_paths.py --php "$PHP_BIN" --module "$MODULE" --backend epoll --out "$OUT/fs-epoll"
python3 tests/http/file_paths.py --php "$PHP_BIN" --module "$MODULE" --backend epoll --transport curl-multi --out "$OUT/http-curl"
python3 tests/http/file_paths.py --php "$PHP_BIN" --module "$MODULE" --backend poll --transport hyper-prototype --out "$OUT/http-hyper"
python3 tests/filesystem_paths_fpm.py --module "$MODULE" --fpm "$FPM_BIN" --backend epoll --out "$OUT/fpm-epoll"
```

Run both epoll and poll for each scope. GCC covers PHP/C paths, not the release
Rust library. These scoped passes do not resolve historical distribution-PHP
cyclic-shutdown losses, the libpq TLS unload allocation or the nested-proxy
Valgrind deadline finding. Release remains **EXPERIMENTAL**.

All implemented file operations execute on bounded native pthread workers.
io_uring filesystem operations remain **NOT_IMPLEMENTED**; the old NOP probe is
not a filesystem backend. Stable File handles, chunked streams and backpressure
are preserved.

Native syscall errors now become `FilesystemException extends OperationException`
with integer `errno` (also getCode) and `committed`. `Files::exists()` recognizes
ENOENT / ENOTDIR numerically, independent of message language. Non-syscall limits
remain OperationException; cancellation remains CancelledException. The current
errno mapping is certified on Linux.

```php
Async::run(function () {
    Files::replaceAtomic('/data/config.json', $json, durable: true)->await();
});
```

`replaceAtomic(path, data, durable=false, permissions=0600): Future` eagerly
submits a bounded (16 MiB) worker operation. It pins the parent directory,
creates a random 128-bit exclusive temporary name there, writes complete contents,
sets the requested permissions, closes and renames within the same directory.
An existing symlink at the target is replaced, not followed. Existing inode
ownership/ACLs/mode are not preserved; the new file uses the caller's ownership
and explicit permission bits. Parent path resolution/open_basedir checking is
not a sandbox against hostile symlink mutation.

Successful rename gives **atomic visibility**, not crash durability. `durable`
additionally fsyncs the new file before rename and the directory after rename.
No cross-filesystem copy fallback exists. Files::rename between filesystems can
still fail with EXDEV. Filesystems/storage may provide weaker durability than
these syscalls imply; no power-loss simulation was performed.

If directory fsync fails after publication, FilesystemException::committed is
true: the new file is already visible. A cancellation race can also occur after
publication; cancellation does not roll it back. Before publication, failure or
observed cancellation removes the temporary file. Cleanup failure is reported,
including the temporary name, and cannot be hidden by concurrent cancellation.
Pinned-directory operations continue against that directory if another process
renames it; they do not follow a replacement parent halfway through the operation.

Tests: empty/binary writes; explicit permissions; symlink replacement; 120
concurrent replacements plus 200 reads with no partial contents; 100 cancellation
requests; missing/permission errno; no leftover temporary files. The native
ASan/UBSan fault harness injects file fsync EIO, rename EXDEV, directory fsync EIO
allocation failure, EINTR/one-byte partial writes and ENOSPC, checks publication state and zero managed allocations.
It verifies fsync calls and recovery, not physical crash durability.

```sh
php -n -d extension="$PWD/build/asyncara.so" tests/filesystem_atomic.php
./scripts/test-atomic-failures.sh
```

Run permission tests as an ordinary user. More adversarial mount/ACL/filesystem
and crash-durability testing remains necessary.

## Independent source package (2026-11-11)

`asyncara-0.1.0-dev-filesystem-admission-r1.tar.gz` contains **691 files** from
`45d574c1802311894ca72c32273e25d731106092`. SHA-256:
`a9621c87c19abd523cd55914d1360c36f6357f646c00e88b9d0234eb69418fc0`.
Two independently created archives are byte-identical; every packaged file was
compared with the Git tree. A fresh Cargo/C build in an extracted directory
completed in 152.702s; all 25 build/validation commands passed in
202.659s combined command time. This was a new binary, not a copied module.

Independent module SHA-256:
`08688e337273a8831f890cd84034f915f9c6799b0d5419d67081e93d7beefe19`.
Tests cover filesystem/path policy, atomic cleanup, curl/Hyper file transfers,
TCP/Redis trust paths and Native Server HTTP/1.1 protocol, TLS/mTLS, response and
request streaming, and four TLS workers with streamed input, on epoll and poll.
The server fixture includes HTTP client, file and SQLite calls inside handlers,
timer progress before independent release, disconnect cancellation and cleanup.
FPM compatibility remains retained but no further FPM run was added to this
package verification, following the owner priority decision. Historical FPM
failures remain unresolved; these server passes are not FPM or endurance passes.

[Build and raw server attestations](phase7/filesystem-admission-package.json).
The archive does not include the later documentation-only package attestation.
No GitHub release has been published. Release remains EXPERIMENTAL.
