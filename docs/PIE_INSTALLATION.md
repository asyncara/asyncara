# PIE installation: verified scope and remaining blockers

Requested commands:

```sh
pie install asyncara/asyncara
pie install asyncara/asyncara:0.10.0
```

**Status: BLOCKED for a complete no-build Asyncara installation through upstream
PIE.** These commands are the target interface, not verified working commands.
Use [the shell installer](BINARY_INSTALLATION.md) for the complete bundle. Do not
replace the official `pie` executable with a project-specific wrapper.

## What was implemented and tested

`packaging/pie/composer.json` defines a separate `asyncara/asyncara` `php-ext`
package, with `license: proprietary` (the legal license remains the unchanged
PolyForm Strict LICENSE). The only download method is `pre-packaged-binary`:
there is no source/Composer-default fallback. Metadata scopes PHP to the three
exact NTS patches actually built; it does not advertise ZTS support.

Composer validates the metadata. The original library package `asyncara/runtime`
and its lock file are unchanged. This metadata must eventually belong to its own
published package with a versioned release; merely storing a nested composer.json
in this repository does not register `asyncara/asyncara` or change the immutable
v0.10.0 root package.

`tests/distribution/pie_contract.php` executes the **unmodified upstream PIE 1.5.5**
package loader, binary builder and Unix installer against a real Asyncara module
in isolated directories. PHP configuration setup is explicitly disabled in this
probe. A deliberately incorrect `.sha256` sidecar and extra runtime/library files
are present. The probe proves that the extension is copied, the sidecar is not
validated, and the other files are not installed. It is not a successful loading
or complete installation test. Source hashes and output are recorded in
[the attestation](releases/0.10.0-shell-installer.json).

## Upstream boundaries observed in PIE 1.5.5

| Requirement | Observed behavior | Consequence |
|---|---|---|
| Download without compilation | `pre-packaged-binary` is a supported download method | Binary-only package metadata is possible |
| No development tools required | Install command normally checks build tools before package resolution | The requested bare command needs upstream accommodation; flags are not the requested interface |
| Exact PHP patch/distro matching | Asset lookup uses PHP major.minor, architecture, OS family/libc and thread safety | Insufficient to select this project's exact-patch/Ubuntu profile by filename alone |
| Verify supplied archive SHA256 | Upstream emits a warning that dist-sha verification is unavailable for prebuilt URLs | HTTPS transport is not equivalent to validating the release checksum |
| Install PHP runtime/private library/CLI | Unix prebuilt path copies only `.so` into extension_dir | Asyncara's library, private Connector/C and launcher are absent |
| Keep package files available | Vendor cleanup removes downloaded package directories | Package `bin`/autoload metadata cannot provide a durable runtime installation |
| Never edit the original ini | PIE attempts configuration setup by default | Only explicit skip behavior in the probe guarantees no ini edits; the bare command is not equivalent |

Primary sources at the tested tag:

- [UnixInstall](https://github.com/php/pie/blob/1.5.5/src/Installing/UnixInstall.php).
- [VendorCleanup](https://github.com/php/pie/blob/1.5.5/src/ComposerIntegration/VendorCleanup.php).
- [Prebuilt download/checksum handling](https://github.com/php/pie/blob/1.5.5/src/ComposerIntegration/Listeners/OverrideDownloadUrlInstallListener.php).
- [InstallCommand](https://github.com/php/pie/blob/1.5.5/src/Command/InstallCommand.php).
- [Binary asset naming](https://github.com/php/pie/blob/1.5.5/src/Platform/PrePackagedBinaryAssetName.php).

A SHA256 calculated after downloading/copying a file identifies its bytes; it does
not verify those bytes against a trusted expected release checksum. Renaming the
current bundle to PIE's filename format would not solve these installation and
validation problems.

## What remains before claiming these commands work

1. A supported upstream mechanism for verifying binary archives and installing
   associated PHP/runtime/library/launcher files durably, without source builds or
   modifying the primary ini. This needs implementation and real end-to-end tests
   in PIE; Asyncara package metadata alone cannot supply it.
2. An explicit policy for exact PHP-patch/distro selection and rejection before
   enabling incompatible binaries. The current bundle profile must remain enforced.
3. A published `asyncara/asyncara` extension package/version, matching release
   artifacts and accessible registry/repository. Packagist availability is checked
   separately in the attestation; it is not inferred from a valid composer.json.
4. Fresh-machine tests of both exact requested commands with no Python/build tools,
   successful `asyncara --check`, unchanged ini, and checksum-negative controls.

No package registration, upstream patch, GitHub Release upload or end-to-end PIE
PASS is claimed in this change. No runtime startup side effects, source builds,
metadata renaming of the library, license changes or tag rewriting were used to
simulate support.

## Reproduce the contract probe

Use the official PIE 1.5.5 source and its locked Composer dependencies in a new,
private directory. No global installation/configuration is necessary:

```sh
composer install --working-dir=/path/to/pie-1.5.5 --no-dev --no-plugins --no-scripts
php tests/distribution/pie_contract.php /path/to/pie-1.5.5 \
  /new/probe-output /path/to/php8.5 /path/to/verified/asyncara.so
composer validate --strict packaging/pie/composer.json
```

The probe intentionally does not load the isolated copied module without its
ancillary runtime. It rejects an existing output directory and preserves its log.
The full shell-installer tests separately load the module and exercise the real
App, HTTP clients and filesystem/SQLite smoke on all three PHP profiles.
