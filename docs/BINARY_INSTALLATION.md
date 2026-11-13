# Prebuilt installation

`install.sh` is a self-contained POSIX shell bootstrap with an embedded PHP
installer. It uses the target PHP interpreter; **Python is not an end-user
dependency**. No compiler, Cargo, phpize or Docker runs on the application machine.
No global `php.ini`, PHP installation or shell profile is changed.

**Publication is separate from implementation.** Three amd64 bundles and the shell
installer were tested locally, including a real HTTPS `curl | sh` pipeline.
GitHub Release asset availability has not been verified. The immutable `v0.10.0`
source tag is unchanged; attach additional assets to that existing Release.

## Install

After publication, for a publicly accessible Release:

```sh
curl -fsSL https://github.com/asyncara/asyncara/releases/download/v0.10.0/install.sh | sh
asyncara --check
asyncara --example 127.0.0.1:8080
```

Visit `http://127.0.0.1:8080/status`. Run your own application with
`asyncara app.php`; Asyncara and its production PSR dependencies are autoloaded.

The installer detects the OS/userspace, architecture, exact PHP patch version,
NTS/ZTS, debug mode and bitness. It chooses one exact binary profile, downloads its
archive and SHA256 sidecar over HTTPS, verifies the archive and every file against
the manifest, checks compatibility, and runs the native installation and release
smoke tests **before** activating the command. No source-build fallback exists.
Missing packages, missing native dependencies and failed checks return failure.

Initial locally tested profile: **Ubuntu 24.04 amd64; PHP 8.3.36, 8.4.27 or 8.5.12
NTS, non-debug, 64-bit**. An exact PHP patch upgrade requires a matching bundle.
NTS/ZTS detection prevents mixing builds; it does not certify ZTS or provide a ZTS
asset. ARM64 CI is prepared, not yet observed running. Other OS/CPU and owner Android
results remain in [PLATFORMS.md](PLATFORMS.md).

Prerequisites: PHP CLI 8.3–8.5 with JSON/hash/zlib/SPL/proc_open available under
`php -n`, POSIX `sh`, `mktemp`, `cat`, `rm`, curl, CA certificates and system runtime
libraries. There is no separate archive utility requirement. On Ubuntu 24.04,
install missing runtime prerequisites through your normal package policy:

```sh
sudo apt-get update
sudo apt-get install -y php-cli curl ca-certificates libcurl4t64 libsqlite3-0 libpq5 libssl3t64 zlib1g
```

This does not guarantee that your package source supplies an exact PHP patch with
a published Asyncara bundle. The installer never installs system packages itself.

By default it uses `php` from PATH, installs version directories under
`~/.local/share/asyncara/releases/`, and activates a symlink named `asyncara` in a
suitable writable PATH directory (preferring user directories). If there is no
such directory, it uses `~/.local/bin` and prints the required export. It cannot
modify the parent shell's PATH. An unrelated existing `asyncara` command is never
replaced. No automatic sudo is used.

Select another already-installed PHP:

```sh
curl -fsSL https://github.com/asyncara/asyncara/releases/download/v0.10.0/install.sh | ASYNCARA_PHP=/usr/bin/php8.4 sh
```

Download and inspect the script first, or select explicit options:

```sh
curl -fLO https://github.com/asyncara/asyncara/releases/download/v0.10.0/install.sh
curl -fLO https://github.com/asyncara/asyncara/releases/download/v0.10.0/install.sh.sha256
sha256sum -c install.sh.sha256
sh install.sh --version 0.10.0
```

The pipeline verifies the **binary archive** automatically; the separate command
above also verifies the installer script before execution. Both rely on the
release account/HTTPS for authenticity, not an independent signing authority.
The bootstrap parses its complete function before invocation; a truncated initial
script fails rather than executing half of the PHP installer.

## Private release or offline installation

An anonymous `curl` command cannot access a private GitHub release. Use a browser
with access, or authenticated GitHub CLI:

```sh
gh auth login
gh release download v0.10.0 --repo asyncara/asyncara --pattern 'install.sh*'
sha256sum -c install.sh.sha256
sh install.sh
```

For bundle downloads the installer tries HTTPS, then authenticated `gh` for the
original GitHub release endpoint. It never puts a token in a CDN URL. Missing
assets and inaccessible private assets fail explicitly, with no local build.

For offline installation, obtain the matching `.tar.gz` and adjacent
`.tar.gz.sha256` alongside the script, then:

```sh
ASYNCARA_PHP=/usr/bin/php8.4 sh install.sh   --bundle asyncara-0.10.0-php8.4.27-nts-ubuntu24.04-x86_64.tar.gz
```

`--prefix /absolute/path` and `--bin-dir /absolute/path` override destinations.
Paths containing spaces are supported and tested. `sh install.sh --check-platform`
only prints the detected profile. `ASYNCARA_RELEASE_BASE_URL` can select a trusted
HTTPS mirror; it does not disable integrity or identity checks. Never use a mirror
you do not trust to supply native executable code.

## Installation safety and runtime boundaries

Extraction is bounded to 128 MiB compressed, 256 MiB file contents and 6,000 files.
Only regular files are accepted. Traversal, links, special files, duplicate paths,
malformed/truncated archives, invalid manifests and mismatched checksums are
rejected. A private staging directory and prefix lock prevent partial activation.
A version directory is renamed into place only after its checks succeed; a final
launcher check precedes atomic command replacement. Earlier versions remain.
Repeating an identical verified install is safe. Locally modified installations
are preserved and rejected rather than overwritten. The original Python installer
and its tests remain historical source; it is no longer the recommended installer
or a new Release asset.

The launcher uses `php -n`: original ini files and their dynamically enabled
extensions are not loaded. Add explicit application options, for example
`asyncara -d memory_limit=256M app.php`. This avoids library collisions and leaves
your original PHP behavior unchanged. Loading additional extensions requires its
own compatibility testing. The launcher targets CLI/Native Server; it does not
configure FPM or enable the extension in plain `php` automatically.

Bundles contain the PHP library/production Composer packages, native module with
Hyper/PostgreSQL/MySQL/MariaDB, and private Connector/C 3.4.10. Common authentication
plugins are compiled in; GSSAPI/PARSEC are not included. PHP itself, libcurl, libpq,
SQLite, OpenSSL and system dependencies are not bundled. A binary is not portable
across arbitrary libc, distro or PHP builds. Third-party notices and replaceable
Connector/C source are retained. The project LICENSE is unchanged; status remains
EXPERIMENTAL.

## Maintainer: publish without changing the release tag

### Upload all remaining 0.10.0 files in one run

For the already validated local 0.10.0 assets, run:

```sh
gh auth login --hostname github.com
sh scripts/distribution/publish-0.10.0.sh
```

Use the `release/0.10.0` checkout containing this script. It finds the original
local artifact directories automatically; the prepared portable kit instead has
`publish.sh` beside an `assets/` directory. Either entry point needs only PHP CLI
8.3+ and `gh` (plus curl for public download verification), without Python/build
tools. `--assets /absolute/directory` selects a flat directory containing all 13
files. These ignored build artifacts are not included in an ordinary Git clone.

The pinned plan is `packaging/releases/0.10.0-assets.json`:

- Original source archive and checksum: two files, normally already uploaded.
- PHP8.3.36/8.4.27/8.5.12 NTS bundles, each with manifest and checksum: nine files.
- `install.sh` and its checksum: two files. The obsolete Python installer is not
  among the uploads.

If the source pair already matches, exactly **11 missing files** are uploaded.
The script checks the live asset list instead of assuming that number. It verifies
all local bytes, the remote tag's exact source commit and the existing published
Release. It downloads existing matching names and verifies SHA256 before any
upload, then uploads only missing assets without `--clobber`. A conflict stops the
run; existing unrelated files, release metadata, visibility and tags are preserved.
The shell installer is uploaded last, after its binary assets.

After uploading, all 13 assets are downloaded afresh and checked. Public repositories
also get an anonymous HTTPS download/checksum check for every asset. Private
repositories get `PUBLISHED_VERIFIED_PRIVATE`, explicitly stating that anonymous
`curl` is unavailable; the script does not change visibility. A SHA256 conflict,
missing/incomplete asset, inaccessible repository, changed tag, draft or locked
Release prevents a false success.

An interrupted run retains completed uploads. Run the **same command again**;
matching files are skipped after verification. A failed/incomplete GitHub asset
with a conflicting name requires inspection, never automatic deletion. Each run
retains its snapshots, downloads, command exits and report under a new directory
in `~/.local/state/asyncara/`; the exact path is printed first. Options:

```sh
sh scripts/distribution/publish-0.10.0.sh --check-local  # no GitHub access
sh scripts/distribution/publish-0.10.0.sh --dry-run     # read-only remote preview
```

This publisher does not install Asyncara on the maintainer's machine, register a
PIE package, build new binaries or claim other OS/PHP profiles. Its isolated tests
use simulated GitHub operations; real publication still occurs when the maintainer
runs it after login. See [publisher validation](releases/0.10.0-publisher.json).

### Build new binary profiles through CI

Workflow: `.github/workflows/binaries.yml`, **Prebuilt installation bundles**.
It builds immutable release source on disposable runners and validates relocated
installation. Python/compiler/Cargo here are **builder/test dependencies**, not
end-user dependencies. Local results do not imply hosted workflow success.

1. Make the workflow available on the default branch through a normal merge.
2. Run it for `v0.10.0` with `publish=false`; review the PHP83/84/85 amd64 jobs and
   optional native ARM64/PHP84 job. Lack of runner access is not a pass.
3. Run with `publish=true` to attach validated bundles, manifests, SHA256 files,
   `install.sh` and `install.sh.sha256` to the existing Release. The publisher
   verifies tag/source identity and refuses asset-name collisions. It never moves
   a tag, overwrites an asset or replaces the original source archive.
4. Alternatively download the successful Actions binary artifacts and attach
   their files through **Edit release**. Also upload the generated `scripts/install.sh`
   as `install.sh` and its SHA256 sidecar. Preserve existing assets.
5. If bundles are already published, attach **only** the installer and checksum
   using a fresh staging directory and authenticated GitHub CLI:

```sh
python3 scripts/distribution/publish.py --tag v0.10.0   --assets /absolute/new/installer-assets --installer-only
```

That is a maintainer command. No Python is needed to install the uploaded files.
The exact script prepared in this execution and checksums are recorded in
[the shell installer attestation](releases/0.10.0-shell-installer.json).

Finally test the actual published URL on a separate matching machine. Anonymous
installation requires public asset access. This task does not change repository
visibility. PIE needs additional upstream/package work; see
[PIE_INSTALLATION.md](PIE_INSTALLATION.md).

## Verification and development

Regenerate the single release script after changing its embedded implementation:

```sh
php -n scripts/distribution/render-installer.php
php -n scripts/distribution/render-installer.php --check
sh -n scripts/install.sh
php -n tests/distribution/installer.php
python3 tests/distribution/integration.py --php /path/to/php --bundle /path/to/bundle.tar.gz --output /new/test-directory
python3 tests/distribution/shell_https.py --php /path/to/php --bundle /path/to/bundle.tar.gz --output /new/https-directory
```

The HTTPS test traps Python/compiler/phpize invocations in the installer's PATH,
checks bare `asyncara` availability, protects a pre-existing php.ini, and rejects
bad SHA256, missing assets and an incomplete script. Python here hosts the test
server only. [Earlier binary results](releases/0.10.0-binaries.json) remain intact.

To remove an installation, stop your own application and remove only the owned
command symlink and its version directory identified by `installation.json`.
Other versions and PHP configuration remain unaffected. Docker is separate.
