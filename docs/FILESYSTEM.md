# Filesystem behavior

## Filename capture at admission (2026-11-11)

Native `141174a` captures relative filenames before the Tasks used by
File open/stage, Files exists/readStream/writeStream, SQLite open and HTTP file
transfers can run. The shared bounded C helper retains PHP virtual cwd and
open_basedir rules, preserves symlink/.. traversal and ordinary filename bytes,
and does not open a file or create a native operation. Worker admission still
checks current policy. SQLite :memory: remains special. A rejected writeStream
path closes an already-owned ReadableStream producer. Invalid path input can
now throw before a Future is created. This captures a name, not an inode or
file contents; hostile filesystem replacement is a separate boundary.

State: IMPLEMENTED_BUT_NOT_FULLY_TESTED. Matched controls reproduce28 failures
before the fix and pass afterward. PHP83/84/85, DEBUG and GCC ASan/UBSan on
epoll/poll pass45 commands/220 PHP executions in170.174s. The user requested
a stop and push at this point. Final memory/full/FPM gates and independent
packaging for this source remain unexecuted. [Exact checkpoint and pending work]
(phase7/filesystem-admission-checkpoint.json). The previous stream-trust-r1
archive does not contain this correction. Release remains EXPERIMENTAL.


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
