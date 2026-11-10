# Hyper client TLS continuation

Status: IMPLEMENTED_AND_TESTED in the HTTP/1.1 buffered GET scope below.
Remaining validation and packaging are recorded separately; broader Hyper features remain partial.
Base5f984ea, isolated phase7-native-server branch. Release EXPERIMENTAL.

The selected `ASYNCARA_HTTP_BACKEND=hyper-prototype` now admits verified HTTPS
buffered GET through the existing HttpClient API. Default curl remains unchanged.
This does not add general methods, custom headers, decompression, redirects,
proxies, uploads, response streaming, HTTP/2 or HTTP/3 to Hyper.

This document records the TLS checkpoint. The subsequent bounded methods,
ordinary headers and request bodies are described in [HYPER_REQUESTS.md](HYPER_REQUESTS.md),
with their own source, failure and validation identities. They do not add the
iterable/file streaming APIs or the other unsupported protocols above.

## Usage

Select the optional backend before request initialization with
`ASYNCARA_HTTP_BACKEND=hyper-prototype`; build with `--with-asyncara-hyper`
or `scripts/build-hyper-local.sh`. Existing HttpClient names are unchanged.

```php
Asyncara\Async::run(function (): void {
    $client = new Asyncara\HTTP\HttpClient(caFile: '/path/to/trust.pem');
    $response = $client->get('https://service.example/status')->await();
    echo $response->body;
});
```

Omit caFile to use the documented local default trust source. The example URL
is illustrative; verification uses isolated local peers, not a public service.

## Execution and trust

Numeric plaintext requests retain the direct native path. HTTPS and domain
requests use an owned Task/permit. DNS uses existing resolver workers. CA bytes
are loaded through IO File workers, in <=64KiB reads with a1MiB total ceiling;
file acquisition/release use the existing shielded ownership contract. No
synchronous trust-store file read occurs inside the Rust progress callback.
The file must be local and regular; PHP open_basedir applies. The first trust
snapshot is retained per HttpClient; a new client reloads changed file contents.

Trust priority is explicit caFile, then SSL_CERT_FILE, then Linux
/etc/ssl/certs/ca-certificates.crt. There is no network root download or permissive
certificate verifier. Parsing accepts at most512 trust certificates. Rustls
0.23.46 with AWS-LC verifies chains, time and DNS/IP identity, sends SNI for DNS
names, and advertises HTTP/1.1 only. TLS1.2/1.3 are enabled. Client certificates,
revocation/OCSP policy controls and resumption are not implemented in this path.

The request-local native engine caches at most16 exact PEM/configuration pairs.
A new seventeenth policy is rejected; existing policies remain usable. Cached
PEM bytes are bounded to16MiB; parsed roots/configuration, TLS, PHP and kernel
allocations are additional and outside complete native-ledger accounting. This
is a REJECT_NEW_ITEM policy, not unlimited rotation in a long-running worker.
Reuse requires matching origin, resolved endpoint and exact trust-policy identity;
plain HTTP cannot borrow HTTPS transport. No hash-only trust comparison is used.

The verified server adapter is shared through rustls::Connection (Client/Server).
One connection driver owns socket polling/wakers. No Tokio executor or network
worker thread is introduced. Connect deadlines include the TLS handshake; total
and connect budgets also include CA loading/DNS after permit admission. A running
file/resolver worker must drain after cancellation. Cancelling one waiter does
not cancel the shared request owner. Ordinary explicit owner cancellation drops
the underlying connection before native completion is published. TLS bytes already
transmitted cannot be retracted. Pool/trust configuration lives until native PHP
request teardown; creating a new HttpClient does not itself reset the native pool.

## Evidence and boundaries

Raw root: /home/coder/Asyncara-hyper-tls-20261110T033522Z.
`tests/http/hyper_tls.py` is an independent Python/OpenSSL peer driver.
It checks TLS1.2/1.3, hostname mismatch, untrusted CA, SNI/ALPN, trust rotation
isolation, binary1MiB integrity, two ticks during held responses and incomplete
handshakes, shared waiter cancellation, owner cancellation and the native connect
deadline. `hyper_tls_bounds.php` rejects malformed/oversized roots,513certificates,
and the seventeenth trust policy, then checks existing-policy recovery and cleanup.

The first new runner accidentally selected curl-multi with an invalid selector;
its results are not Hyper evidence. Explicit backend identity is now asserted.
The next run passed functional assertions but fixture TLS shutdown waited for an
idle client pool that PHP only closes at request shutdown. The controller now
aborts its own fixture transports on stdin EOF; production TLS behavior and test
assertions are unchanged. Original failures are retained. The same-path CA-file
rotation observation under curl is not reclassified as a Hyper defect.

Official APIs: [rustls Connection](https://docs.rs/rustls/0.23.46/rustls/enum.Connection.html),
[ClientConnection](https://docs.rs/rustls/0.23.46/rustls/client/struct.ClientConnection.html).
No new throughput, comparative benchmark or deployment/endurance claim.

## Bounded TLS read coalescing

The shared transport now fills the caller's bounded read buffer from records
that are already available. Each dispatch performs at most 16 iterations with
the same initialized 16 KiB scratch buffer. A would-block result after plaintext
was delivered returns immediately; it never waits to fill a larger buffer.
Small-body early delivery and two timer ticks before peer release remain tested.
There is no new producer queue, deadline change or added busy wait.

The original `matrix-r1` GCC/poll slow-input reproduction failed at 3.021684s,
with six ticks and the native timeout counter incremented. This establishes the
3s deadline as the termination condition for that reproduction, not for every
historical reset. After coalescing, `matrix-r2` passes all 85 commands across PHP
8.3/8.4/8.5 NTS, DEBUG and GCC ASan/UBSan on epoll/poll. The same GCC/poll 8 MiB
slow upload completed in 0.866549s with 131 chunks instead of 514. These are
local functional measurements; independent repeated comparisons are separate.
Rust/AWS-LC is release code, not instrumented by GCC's C/PHP sanitizer.

The historical Phase 4 zero-tick cause remains unresolved and unrelated to this
measured deadline/chunk-count finding. Original reports are never relabeled.

## Completed regression and memory checks

`regressions-r1/report.json`: 16 commands PASS, including seven ordinary Rust
units and the explicitly invoked TLS canonical-driver-waker unit (32 requests),
Gate A 193.188s, full PHP85 epoll/poll suites, eight four-worker normal/forced-exit
plain/TLS input workloads, body lifetime and allocation audit. The ordinary Rust
run records the certificate-dependent unit as ignored; the separate invocation
is its actual execution evidence. `http-r1/report.json` passes 74 HTTP commands.

`memory-r1` preserves two DEBUG server TLS input/output Valgrind passes and an
original client acceptance FAIL at its 3s request deadline. That workload is
not relabeled as passing under instrumentation. A separate diagnostic fixture
uses normal HttpClient defaults and retains body integrity, two ticks before
peer release, shared-waiter/owner cancellation, recovery and cleanup checks.
Its first cold TLS1.2 request took 5.116563s on epoll under Valgrind, beyond the
acceptance fixture's3s budget. `memory-r2` passes this distinct memory workload
on epoll and poll with zero errors and zero definite/indirect/possible lost
bytes; 5,306 bytes remain reachable. No suppressions or production GC changes.
These scoped DEBUG results do not erase historical distribution-PHP findings.

Reproduce the memory scope after the functional runner generated certificates:

```sh
python3 tests/http/hyper_tls_memory.py --php /path/to/debug-valgrind-wrapper \
  --module build/hyper.so --backend epoll \
  --certs /path/to/functional-run/certs --out /new/memory-run
```

Certificate-dependent test keys stay outside source distributions. The wrapper
uses USE_ZEND_ALLOC=0, leak-check=full, all leak kinds and error-exitcode=99, with
no suppressions. Exact commands/log hashes are in the durable attestation.

## Repeated local coalescing comparison

Three interleaved GCC/poll samples use the same server input test, 8 MiB body,
slow-consumer delay and 3s connection deadline. Before: one timeout at3.004260s,
two passes at2.910632s/2.683981s, always514chunks. After: three passes at
1.011431s/1.282986s/0.940179s,131..132chunks. The functional source difference
for the server transport is bounded TLS ready-record coalescing; other native
client changes between matrix snapshots are only a module comment and cfg(test)
coverage. The server fixture/thresholds are byte-identical. This is a shared-host
local comparison, not a general throughput claim or a deterministic old failure.
[Commands, fingerprints and retained failures](phase7/hyper-tls.json).

## Independently verified source package

Source: `8e67aebb3aa6cc2e4b346cf8004cfd88475c540a`. Archive: `build/asyncara-0.1.0-dev-server-r4.tar.gz`,
590 files, SHA256 `5592b3e7b37cbcb0aae6373710489d0b743e91457eee324ecaa01f35c05594f5`.
Two builds of the archive are byte-identical; every entry matches the Git tree.
A fresh extraction, fresh Cargo target and native build pass21 checks covering
server protocol, TLS, input/output streaming, ownership, and client verified TLS
and lifetime controls on epoll/poll. Independent module SHA256:
`9e0621ad0d9f66cdfb2e2394f4187cbe9a63a600a61e4b402f895c277daa6362`.
[Independent attestation](phase7/server-r4-package.json). No GitHub release or
broad performance/endurance certification. Original server-r1/r2/r3 and Phase4
checkout/module/LICENSE remain unchanged.
