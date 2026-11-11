# HTTPS proxy trust and ownership

Native checkpoint `2a5224f` extends the existing curl multi client with an
independent proxy trust file. It does not replace the transport or connection
pool. Release remains EXPERIMENTAL. The documented HTTP/1.1 proxy capability is
IMPLEMENTED_AND_TESTED; broader protocol/proxy certification remains partial.

```php
$client = new Asyncara\HTTP\HttpClient(
    proxy: 'https://proxy.example:8443',
    caFile: '/application/origin-ca.pem',
    proxyCaFile: '/application/proxy-ca.pem',
);
$response = $client->get('https://service.example/data')->await();
```

`proxyCaFile` is an optional final constructor argument. It requires an explicit
HTTPS proxy; plaintext/implicit-environment proxy selection is rejected when a
proxy trust file is provided. Both proxy chain/hostname and origin chain/hostname
verification remain enabled. Origin `caFile` is not silently reused as proxy
trust. With no explicit proxy CA, libcurl uses its configured default roots.

`HttpClient` snapshots relative trust filenames at construction, and native
submission captures an owned absolute path with open_basedir admission checks.
The same native path validation applies to both CA options, including empty,
NUL and CR/LF rejection. This captures the filename, not certificate contents
or symlink identity, and does not provide an atomic filesystem sandbox.
Library trust-file loading, crypto, DNS and third-party allocations retain the
limits described in [HTTP_TRUST_PATHS.md](HTTP_TRUST_PATHS.md).

Network waiting uses curl multi and the existing epoll/poll readiness integration.
Uploads/downloads retain the separate filesystem worker backend. This is not
PHP cURL interception, a worker wrapper for network waits, or a second pool.
The optional Hyper transport still rejects proxies explicitly.

The native mappings are `ext/backend.c:ar_http_start()` using
[CURLOPT_PROXY_CAINFO](https://curl.se/libcurl/c/CURLOPT_PROXY_CAINFO.html),
`CURLOPT_PROXY_SSL_VERIFYPEER=1` and `CURLOPT_PROXY_SSL_VERIFYHOST=2`.
`ext/php_asyncara.c:valid_http_options()` validates the option combination;
`asyncara_submit()` owns the trust paths until job cleanup.

## Independent fixture and boundaries

`tests/http/https_proxy.py` creates separate private proxy and origin CAs.
`https_proxy_peer.py` implements a bounded, loopback-only test proxy over TLS,
including CONNECT to the private TLS origin. No external proxy is involved.
`https_proxy.php` checks HTTP forwarding, TLS-over-TLS CONNECT, response streaming,
held-body timer progress before independent release, streamed upload integrity,
gzip decoding, early close and recovery. Untrusted/default, wrong proxy CA,
wrong origin CA and trusted-but-wrong proxy hostname all reject. CONNECT-only
headers must not escape into origin response metadata.

The tests also cover relative-path ownership and invalid strings. The separate
`ca_paths.py` suite checks repeated proxy trust open_basedir rejection through
both public and native APIs and verifies no retained native allocations.
`https_proxy_fpm.py` preserves the original two-tick/overlap assertions for
20 sequential and40 four-worker requests, then checks pending fatal-request
cleanup and same-worker recovery. It snapshots its source/fixture identities.

This certifies only the executed Linux/libcurl/OpenSSL HTTP/1.1 configuration.
Proxy authentication schemes, SOCKS, HTTP/2/3 proxying, interception appliances,
remote-WAN behavior and broad platform compatibility are not certified by these
fixtures. It does not establish the historical zero-tick cause or endurance.

## Reproduce

```
python3 tests/http/https_proxy.py --php /path/to/php --module /path/to/asyncara.so \
  --backend epoll --out /new/proxy-evidence
python3 tests/http/https_proxy_fpm.py --module /path/to/asyncara.so \
  --fpm /path/to/php-fpm --backend poll --out /new/proxy-fpm-evidence
```

The complete HTTP gate includes the CLI fixture on both backends. CI separately
runs the four-worker fixture. Artifact upload selects JSON/log files, excluding
private test keys. Source archives contain fixture code, never generated keys.

## Executed verification (2026-11-11)

Raw root: `/home/coder/Asyncara-priority-20261110T205459Z`.
Native `2a5224f`; lifecycle/negative-input test checkpoint `f6fb750`.
[Machine-readable commands, module/source hashes and independent FPM review](phase7/https-proxy.json).

| Check | Actual result |
|---|---|
| Before feature, old client | `https-proxy-before-r1` FAIL: unknown constructor argument; retained missing-capability control |
| Five builds, epoll/poll | `https-proxy-matrix-r1` PASS35 commands/137.111s: PHP8.3.36/8.4.27/8.5.12, DEBUG8.5.12, GCC ASan/UBSan8.5.12 |
| Trust path validation | Matrix includes160 executed curl/Hyper mode checks; proxy-specific open_basedir failures are repeated40 times per mode |
| Ordinary-unload DEBUG Valgrind | `https-proxy-memory-r1` PASS both backends;0 errors and0 definitely/indirectly/possibly lost bytes;3,888bytes remain reachable, no suppressions |
| Gate A | PASS47 checks/190.670s outer wall time |
| Full PHP8.5 suites | epoll PASS34.216s; poll PASS40.931s |
| Full HTTP gate | PASS90 commands/153.722s outer wall time |
| Private-proxy FPM | PASS both backends:120 main requests/480 overlapping HTTP operations,20 sequential+40 four-worker per backend, fatal pending-request cleanup and same-PID recovery |
| Allocation audit | PASS5.513s |

The C/PHP GCC build does not instrument Rust/AWS-LC release code. These scoped
ordinary-unload passes do not erase the separately documented libpq, PHP cyclic
shutdown or older curl findings. The prior TLS proxy timing failure retains its
original source/run identity. Short FPM success does not certify long endurance.

## Independent HTTP/2 and HTTPS proxy source package

Source `bb3fcf5d15a9cac3bece5450941c17b1a25fe4f6`; archive
`build/asyncara-0.1.0-dev-http2-proxy-r1.tar.gz`,666files. SHA256
`2737618dca7170025eb4f0f2c08ba6cb30ec11b854cedecd37c0e104d1fa2267`.
Two packages are byte-identical; every entry matches the committed Git tree.
Fresh extraction, fresh Cargo target and native build pass15 commands: build plus
HTTP/2 direct/proxy/FPM, HTTPSproxy/FPM and Hyper compression/streaming on epoll/poll.
Build wall137.755s. [Build attestation](phase7/http2-proxy-package.json).
Independent module SHA256: `fcb6e41b37c93161f974195bcef4b191624c3d546bdf29f7ad47fb86d1412244`.
No release publication; the package does not erase scoped Valgrind deadline
failures or certify streaming HTTP/2, HTTP/3 or historical endurance.
