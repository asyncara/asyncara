# Platform and binary installation evidence

These are distinct facts: a recognized platform name, a successful source build,
a runtime test, an owner-reported result, and a published binary. None implies
all the others. `scripts/install.py --list-platforms` reports all 20 requested
OS/architecture combinations without trying to install a different platform's
binary. `--check-platform` only detects the current userspace.

| OS | amd64 | arm64 | arm (32-bit) | 386 (32-bit) |
|---|---|---|---|---|
| Linux | Tested on Ubuntu 24.04, PHP 8.3.36/8.4.27/8.5.12 NTS | Native Ubuntu CI prepared; NOT_VERIFIED | NOT_VERIFIED | NOT_VERIFIED |
| Android | USER_REPORTED_PASS | USER_REPORTED_PASS | USER_REPORTED_PASS | USER_REPORTED_PASS |
| Windows | PORT_REQUIRED | PORT_REQUIRED | PORT_REQUIRED | PORT_REQUIRED |
| macOS | PORT_REQUIRED | PORT_REQUIRED | No current validation target provisioned | No current validation target provisioned |
| FreeBSD | PORT_REQUIRED | PORT_REQUIRED | PORT_REQUIRED | PORT_REQUIRED |

**Android:** on 2026-11-13 the project owner explicitly reported passing tests on
all four architectures. This report preserves that result; it does not recast it
as a failure. The PHP versions, tested source identity, exact tests, binary hashes
and native Termux/Bionic versus Linux/proot userspace were not supplied in this
session. Consequently there is no independently matched Android download bundle
yet. The Android row is owner evidence, not local or CI execution evidence.

A GNU/Linux userspace inside proot uses that distribution's ABI and libraries;
it must not be given a Bionic/Termux module merely because the device runs Android.
Native Termux is detected separately when it has no GNU/Linux os-release.
`amd64`/`x86_64`, `arm64`/`aarch64`, and `386`/`i686` are normalized as names.
The generic `arm` label alone does not identify ARM ISA level, floating-point ABI
or libc. No installer shortcut assumes these ABIs are interchangeable.

## Source constraints observed in the 0.10.0 release

- `ext/config.m4` rejects non-Linux Hyper builds explicitly.
- `ext/hyper/src/lib.rs` has `cfg(not(target_os = "linux"))` with a compile error;
  Linux socket flags and `MSG_NOSIGNAL` are used in the transport.
- `ext/workers.c::ar_engine_new` uses `pipe2`, pthreads and POSIX descriptors.
- The extension has no Windows `config.w32`/Winsock resource implementation.
- `scripts/check-install.php` scopes the published 0.10 validation to Linux
  64-bit NTS. A PHP8.3–8.5 32-bit target needs its own build and resource/FFI tests;
  naming an asset `386` does not establish that.
- `ext/backend.c` contains the poll path as well as Linux epoll. That alone does
  not certify all the remaining runtime, filesystem, TLS and process paths on BSD
  or macOS.

These observations describe **this source**, not a claim that the owner's Android
build cannot work. Its exact source/userspace has to be correlated first. The
installer change does not silently remove platform guards in an immutable release.

For current Rust target/toolchain status see the
[official platform table](https://doc.rust-lang.org/rustc/platform-support.html).
For PHP Windows build architectures/toolchains see
[PHP's build requirements](https://www.php.net/build-setup.php). Upstream language
support is a prerequisite, not an Asyncara certification result. Modern Apple
silicon is `arm64`, not the separate 32-bit `arm` target requested here.

## What can be installed now

Three Ubuntu24.04/amd64 bundles have local relocation and runtime evidence;
see [installation results](releases/0.10.0-binaries.json). GitHub asset publication
is pending independent verification. Ubuntu ARM64 is an explicit native CI option,
not an already passing matrix cell. Other Linux distributions require matching
libc/system dependency builds and execution evidence. No claim is made that the
Ubuntu binary works universally on Debian, Fedora, Alpine/musl, Android or BSD.

Windows/macOS/FreeBSD runtime ports and ARM/386 validation remain separate native
engineering work, not completed by this installer. No matching SDKs/runners were
available for local execution in this session; no results were fabricated.

To correlate an existing device test without exposing application data:

```sh
python3 install.py --check-platform
php -n -d extension=/absolute/path/to/asyncara.so scripts/distribution/platform-report.php
```

Attach the actual test command/result and source commit separately. The platform
report is read-only capability information, not a behavioral test.
