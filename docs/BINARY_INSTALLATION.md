# Prebuilt installation

The installer adds `~/.local/bin/asyncara`, backed by a private, versioned bundle
under `~/.local/share/asyncara/releases/`. No compiler, Cargo, phpize or Docker is
needed on the application machine. PHP CLI and system runtime libraries are
prerequisites. No global `php.ini`, PHP installation or shell profile is changed.

**Publication is separate from implementation.** The installer and three amd64
bundles were tested locally. Their GitHub Release upload has not been verified.
The source-only `v0.10.0` tag is unchanged. The maintainer must attach the assets
below before the automatic download command works.

## Install

Initial validated binary profile: Ubuntu 24.04 amd64, PHP 8.3.36, 8.4.27 or 8.5.12,
NTS, non-debug, 64-bit. Match the **exact PHP patch version**, not only the module
API. A PHP patch upgrade requires a matching bundle. ARM64 has an optional native
CI build/test job; it is **not yet independently executed**. Other platform
claims and the owner's Android results are recorded in [PLATFORMS.md](PLATFORMS.md).

On Ubuntu 24.04, if the prerequisites are not already installed:

```sh
sudo apt-get update
sudo apt-get install -y php-cli python3 ca-certificates libcurl4t64 libsqlite3-0 libpq5 libssl3t64 zlib1g
```

Use your chosen PHP package source. The command above does not guarantee an exact
patch version with a published bundle. `php -v` identifies your installed version.
Python 3.11+ is required (Ubuntu 24.04 supplies 3.12).

After the assets are published, download `install.py` and `install.py.sha256`
from the [v0.10.0 Release](https://github.com/asyncara/asyncara/releases/tag/v0.10.0).
In their download directory:

```sh
sha256sum -c install.py.sha256
python3 install.py --version 0.10.0
~/.local/bin/asyncara --check
~/.local/bin/asyncara --example 127.0.0.1:8080
```

Then visit `http://127.0.0.1:8080/status`. Stop the foreground example with Ctrl-C.
Run your own application using `~/.local/bin/asyncara app.php`; the bundled
Composer autoloader loads Asyncara and its production PSR dependencies. A project
can still load its own additional Composer dependencies normally.

For a public repository, the initial download can use:

```sh
curl -fLO https://github.com/asyncara/asyncara/releases/download/v0.10.0/install.py
curl -fLO https://github.com/asyncara/asyncara/releases/download/v0.10.0/install.py.sha256
sha256sum -c install.py.sha256
python3 install.py --version 0.10.0
```

For this repository while it is private, use the authenticated browser, or GitHub
CLI with repository access:

```sh
gh auth login
gh release download v0.10.0 --repo asyncara/asyncara --pattern 'install.py*'
sha256sum -c install.py.sha256
python3 install.py --version 0.10.0
```

The installer tries public HTTPS, then authenticated `gh` if available. It never
copies a GitHub token into a CDN request or URL. A 404 can mean the asset is missing
or the repository is private; it is not silently replaced by a source build.
Checksums detect corruption; their trust is the release/account from which both
files were obtained, not an independent signature.

For an offline/manual install, download the matching `.tar.gz` and adjacent
`.tar.gz.sha256` as well, then pass the archive explicitly:

```sh
python3 install.py --php /usr/bin/php8.4 \
  --bundle asyncara-0.10.0-php8.4.27-nts-ubuntu24.04-x86_64.tar.gz
```

`--prefix` and `--bin-dir` select other user-owned directories. The installer
checks every file, PHP/platform identity and the relocated module, then runs the
release smoke test before activating the command. It refuses to overwrite an
unrelated `asyncara` command or modified installation. Repeating installation
of the same verified bundle is safe. It never adds itself to PATH; optionally add
`export PATH="$HOME/.local/bin:$PATH"` to your own shell configuration.

## Runtime boundaries

The launcher uses `php -n`: **global PHP ini files and dynamically configured
extensions are not loaded**. This isolates the bundled Connector/C from another
PHP extension's database library. Add application-specific PHP options explicitly,
for example `asyncara -d memory_limit=256M app.php`. Loading extra extensions is
an additional compatibility choice, not covered by this bundle's acceptance.
This launcher targets CLI and Native Server; it does not configure PHP-FPM.

The bundle includes Asyncara's PHP library, production Composer packages, the
native module with Hyper, PostgreSQL and MySQL/MariaDB, and a private Connector/C
3.4.10 shared library. Common authentication plugins are compiled into that
shared library for relocation. GSSAPI and PARSEC are not included. PHP itself,
libcurl, libpq, SQLite, OpenSSL and their system dependencies are **not bundled**.
A single binary is not portable across arbitrary libc, distro or PHP builds.

Third-party licenses are retained in `notices/` and `runtime/vendor/`. The
unmodified pinned Connector/C source accompanies its replaceable shared library.
The project's own LICENSE is unchanged. Release classification is EXPERIMENTAL.

## Maintainer: publish the installation assets

Workflow: `.github/workflows/binaries.yml`, **Prebuilt installation bundles**.
Pushes changing its installer/build paths build three amd64 PHP profiles plus
a native ARM64/PHP8.4 profile and
retain assets/logs in Actions. They do not publish a Release automatically.

1. Make the workflow available on the repository's default branch through the
   normal reviewed merge. GitHub's **Run workflow** UI requires that.
2. Run it for existing tag `v0.10.0`, initially with `publish=false`. Inspect all
   build and relocated installation results. The default `arm64=true` also runs a native
   ARM64/PHP8.4 job; lack of runner access is not a passing test.
3. Run with `publish=true` to attach validated binaries, manifests, checksums,
   `install.py` and `install.py.sha256` to the **existing** Release. The publisher
   checks tag/source identity and refuses existing asset-name collisions. It does
   not move a tag, overwrite an asset or create a different source release.
4. Alternatively download the successful `binary-ubuntu...` Actions artifacts,
   extract them, and attach their files manually via **Edit release**. Also attach
   `scripts/install.py` and its SHA-256 sidecar. Keep the source archive already
   published; the native bundles are additional assets.
5. Try the documented install on a separate matching machine before announcing
   availability. Retain that machine's install/check output.

Local build command on a disposable Ubuntu builder with matching PHP development
headers and the dependencies listed in the workflow:

```sh
bash scripts/distribution/build.sh "$PWD" \
  4252f83f2cebaaaa2be150984e5ab0fa6d5b1862 "$PWD/build/binaries-new"
```

Use a fresh output directory. Runtime source comes from `git archive` of the
specified immutable commit. The standard Cargo/phpize/configure/make pipeline
runs in that directory. Module relocation and integration must pass before an
asset is produced by a successful build job. Local evidence for the initial
bundles is linked from [binary installation results](releases/0.10.0-binaries.json).

## Remove or restore

Stop your own running application first. Remove only the installer-owned
`~/.local/bin/asyncara` symlink and the version directory referenced by its
`installation.json`. Other version directories remain available. If you changed
`--prefix`/`--bin-dir`, use those actual paths. No PHP ini reversal is needed.
Do not delete a pre-existing unrelated executable with that name.

Docker remains a separate packaging option; this change does not publish or
certify a Docker image.
