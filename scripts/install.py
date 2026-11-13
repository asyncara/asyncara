#!/usr/bin/env python3
"""Install a verified prebuilt Asyncara bundle for the current user's PHP.

No compilation, sudo, php.ini changes, shell profile edits or source fallback.
"""
import argparse
import gzip
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import platform
import re
import shlex
import shutil
import subprocess
import sys
import tarfile
import tempfile
import urllib.error
import urllib.request
import uuid

MAX_ARCHIVE = 128 * 1024 * 1024
MAX_CONTENT = 256 * 1024 * 1024
MAX_FILES = 6000
REPOSITORY = 'asyncara/asyncara'


def architecture(machine):
    """Normalize names only; this does not certify a runtime or a C ABI."""
    name = machine.lower()
    if name in ('amd64', 'x86_64', 'x64'): return 'x86_64'
    if name in ('arm64', 'aarch64'): return 'aarch64'
    if name in ('386', 'x86', 'i386', 'i486', 'i586', 'i686'): return 'i686'
    if name in ('arm', 'armv7', 'armv7l', 'armv8l'): return 'arm'
    return name


def platforms():
    rows = []
    for system in ('Windows', 'Linux', 'macOS', 'Android', 'FreeBSD'):
        for arch in ('amd64', 'arm64', 'arm', '386'):
            result = 'NOT_VERIFIED'
            reason = 'No matching runtime build/test evidence or release bundle'
            if system == 'Android':
                result = 'USER_REPORTED_PASS'
                reason = 'Owner reports all four architectures pass; PHP/environment/build artifacts not yet supplied'
            elif system == 'Linux' and arch == 'amd64':
                result = 'TESTED_SOURCE'
                reason = 'Ubuntu 24.04; matching PHP 8.3/8.4/8.5 NTS; binary publication is separate'
            elif system == 'Linux' and arch == 'arm64':
                reason = 'Native ARM64 CI is available for explicit validation; not yet executed'
            elif system in ('Windows', 'macOS', 'FreeBSD'):
                result = 'PORT_REQUIRED'
                reason = 'Current full Hyper runtime explicitly targets Linux; requires native port and execution tests'
            if system == 'macOS' and arch in ('arm', '386'):
                reason = 'No modern macOS/PHP 8.3–8.5 validation target provisioned; arm means 32-bit, not Apple silicon'
            rows.append({'os': system, 'arch': arch, 'evidence': result, 'detail': reason})
    return rows


def sha(path):
    with open(path, 'rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def run(command):
    result = subprocess.run(list(map(str, command)), capture_output=True, text=True, timeout=60)
    if result.returncode:
        raise RuntimeError('Command failed: ' + shlex.join(list(map(str, command))) + '\n' + result.stdout[-4000:] + result.stderr[-4000:])
    return result.stdout


def php_identity(php):
    return json.loads(run([php, '-n', '-r', 'echo json_encode(["version"=>PHP_VERSION,"zts"=>(bool)PHP_ZTS,"debug"=>(bool)PHP_DEBUG,"bits"=>PHP_INT_SIZE*8]);']))


def platform_identity():
    system = platform.system()
    arch = architecture(platform.machine())
    # A proot Linux userspace deliberately identifies through its own os-release;
    # it needs its distro's ABI, not an Android/Bionic binary.
    if system == 'Linux':
        try: info = platform.freedesktop_os_release()
        except OSError: info = {}
        if info.get('ID') and info['ID'] not in ('android', 'termux'):
            return {'os': info['ID'], 'osVersion': info['VERSION_ID'], 'arch': arch}
    if system == 'Android' or (system == 'Linux' and
            (hasattr(sys, 'getandroidapilevel') or os.environ.get('TERMUX_VERSION'))):
        api = str(sys.getandroidapilevel()) if hasattr(sys, 'getandroidapilevel') else 'unknown'
        return {'os': 'android', 'osVersion': api, 'arch': arch}
    name = {'Windows': 'windows', 'Darwin': 'macos', 'FreeBSD': 'freebsd'}.get(system, system.lower())
    version = platform.mac_ver()[0] if system == 'Darwin' else platform.release()
    return {'os': name, 'osVersion': version, 'arch': arch}


def asset_name(version, php, host):
    return f'asyncara-{version}-php{php["version"]}-nts-{host["os"]}{host["osVersion"]}-{host["arch"]}.tar.gz'


def download(version, name, directory):
    # Public HTTPS downloads contain no credentials. For private releases, gh
    # performs authentication and redirects; never copy a token to a CDN host.
    target = directory / name
    url = f'https://github.com/{REPOSITORY}/releases/download/v{version}/{name}'
    try:
        with urllib.request.urlopen(url, timeout=30) as response, target.open('xb') as output:
            total = 0
            while chunk := response.read(65536):
                total += len(chunk)
                if total > MAX_ARCHIVE:
                    raise RuntimeError('Download exceeds maximum bundle size')
                output.write(chunk)
        return target
    except urllib.error.HTTPError as error:
        if error.code not in (401, 403, 404):
            raise RuntimeError(f'GitHub download failed (HTTP {error.code})') from error
    if shutil.which('gh'):
        run(['gh', 'release', 'download', 'v' + version, '--repo', REPOSITORY,
             '--pattern', name, '--dir', directory])
        if target.is_file() and target.stat().st_size <= MAX_ARCHIVE:
            return target
    raise RuntimeError('No accessible matching release asset: ' + name +
                       '\nPrivate repository: run gh auth login, or download the bundle and .sha256 in your browser and use --bundle.\nNo compiler fallback is performed.')


def expected_checksum(sidecar, name):
    if sidecar.stat().st_size > 4096:
        raise ValueError('Oversized checksum file')
    match = re.fullmatch(r'([0-9a-f]{64})\s+\*?([^\r\n]+)\n?', sidecar.read_text())
    if not match or match[2] != name:
        raise ValueError('Expected one SHA-256 entry for ' + name)
    return match[1]


def extract_bundle(archive, destination):
    """Bounded extraction; reject links, duplicate paths and traversal before use."""
    names = set()
    total = 0
    class BoundedReader:
        # Include tar/PAX metadata in the decompression budget too, before
        # tarfile can allocate for a malicious metadata length.
        def __init__(self, stream):
            self.stream = stream
            self.remaining = MAX_CONTENT + MAX_FILES * 2048

        def read(self, size):
            data = self.stream.read(min(size, self.remaining + 1))
            self.remaining -= len(data)
            if self.remaining < 0:
                raise ValueError('Bundle exceeds decompression limit')
            return data

    with gzip.open(archive, 'rb') as compressed, tarfile.open(fileobj=BoundedReader(compressed), mode='r|') as package:
        for entry in package:
            path = PurePosixPath(entry.name)
            if (not entry.isfile() or not path.parts or path.parts[0] != 'asyncara'
                    or len(path.parts) < 2 or '..' in path.parts or path.is_absolute()
                    or str(path) != entry.name or '\\' in entry.name or len(entry.name) > 1024):
                raise ValueError('Unsafe bundle member: ' + entry.name)
            relative = str(PurePosixPath(*path.parts[1:]))
            if relative in names:
                raise ValueError('Duplicate bundle member: ' + relative)
            names.add(relative)
            total += entry.size
            if len(names) > MAX_FILES or entry.size < 0 or total > MAX_CONTENT:
                raise ValueError('Bundle exceeds extraction limits')
            target = destination / relative
            target.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
            with package.extractfile(entry) as source, target.open('xb') as output:
                shutil.copyfileobj(source, output, length=65536)
            target.chmod(0o600)
    return names


def validate_bundle(root, names, version, php, host):
    manifest_file = root / 'manifest.json'
    if manifest_file.stat().st_size > 2 * 1024 * 1024:
        raise ValueError('Oversized manifest')
    manifest = json.loads(manifest_file.read_text())
    if (not isinstance(manifest, dict) or manifest.get('schema') != 1 or manifest.get('project') != 'asyncara/runtime'
            or manifest.get('version') != version or manifest.get('php') != php
            or manifest.get('platform') != host):
        raise ValueError('Bundle version, PHP build or platform does not match this installation')
    if not isinstance(manifest.get('sourceCommit'), str) or not re.fullmatch('[0-9a-f]{40}', manifest['sourceCommit']):
        raise ValueError('Missing exact source commit')
    files = manifest['files']
    if not isinstance(files, dict) or names != set(files) | {'manifest.json'}:
        raise ValueError('Bundle inventory differs from its manifest')
    required = {'extension/asyncara.so', 'lib/libasyncara-mariadb.so.3', 'runtime/autoload.php',
                'runtime/vendor/autoload.php', 'runtime/scripts/check-install.php',
                'runtime/tests/release/smoke.php', 'runtime/LICENSE'}
    if not required <= set(files):
        raise ValueError('Incomplete binary/runtime bundle')
    for name, digest in files.items():
        if not isinstance(digest, str) or not re.fullmatch('[0-9a-f]{64}', digest) or sha(root / name) != digest:
            raise ValueError('Bundle file checksum mismatch: ' + name)
    return manifest


def launcher(php, destination):
    module = str(destination / 'extension/asyncara.so')
    runtime = destination / 'runtime'
    return '#!/bin/sh\nset -eu\n' + '\n'.join([
        'php=' + shlex.quote(str(php)),
        'module=' + shlex.quote(module),
        'runtime=' + shlex.quote(str(runtime)),
        'case "${1:-}" in',
        '  --check) shift; exec "$php" -n -d "extension=$module" "$runtime/scripts/check-install.php" --require-hyper --require-sql "$@" ;;',
        '  --example) shift; exec "$php" -n -d "extension=$module" "$runtime/examples/server.php" "$@" ;;',
        '  --version) exec "$php" -n -d "extension=$module" -r \'echo "Asyncara ", phpversion("asyncara"), " / PHP ", PHP_VERSION, "\\n";\' ;;',
        'esac',
        'exec "$php" -n -d "extension=$module" -d "auto_prepend_file=$runtime/vendor/autoload.php" "$@"',
    ]) + '\n'


def owned_link(path, prefix):
    if not path.is_symlink():
        return False
    target = path.resolve()
    return (target.is_relative_to(prefix / 'releases') and target.name == 'asyncara'
            and (target.parent.parent / 'installation.json').is_file())


def install(archive, checksum, version, php, identity, host, prefix, bin_dir):
    import fcntl  # Imported only after a supported Unix installation profile is selected.
    if archive.stat().st_size > MAX_ARCHIVE or sha(archive) != checksum:
        raise ValueError('Archive SHA-256 mismatch or oversized archive')
    prefix.mkdir(parents=True, exist_ok=True, mode=0o700)
    with (prefix / '.install.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        entry = bin_dir / 'asyncara'
        if os.path.lexists(entry) and not owned_link(entry, prefix):
            raise ValueError('Refusing to replace an unrelated file: ' + str(entry))
        destination = prefix / 'releases' / asset_name(version, identity, host).removesuffix('.tar.gz')
        with tempfile.TemporaryDirectory(prefix='.staging-', dir=prefix) as temporary:
            staging = Path(temporary)
            names = extract_bundle(archive, staging)
            manifest = validate_bundle(staging, names, version, identity, host)
            # Dynamic-linker/library failures occur before any active command changes.
            check = [php, '-n', '-d', 'extension=' + str(staging / 'extension/asyncara.so')]
            run(check + [staging / 'runtime/scripts/check-install.php', '--require-hyper', '--require-sql'])
            run(check + [staging / 'runtime/tests/release/smoke.php'])
            receipt = {'schema': 1, 'archiveSha256': checksum, 'php': str(php),
                       'version': version, 'sourceCommit': manifest['sourceCommit']}
            if os.path.lexists(destination):
                old = json.loads((destination / 'installation.json').read_text())
                if old != receipt:
                    raise ValueError('Existing installation differs; preserve it and use a new --prefix')
                if destination.is_symlink() or validate_bundle(destination, names, version, identity, host) != manifest:
                    raise ValueError("Existing installation manifest differs")
                if (destination / 'bin/asyncara').read_text() != launcher(php, destination):
                    raise ValueError('Existing launcher was modified; refusing to overwrite it')
            else:
                (staging / 'bin').mkdir()
                (staging / 'bin/asyncara').write_text(launcher(php, destination))
                (staging / 'bin/asyncara').chmod(0o755)
                (staging / 'installation.json').write_text(json.dumps(receipt, indent=2) + '\n')
                destination.parent.mkdir(exist_ok=True)
                staging.rename(destination)
            bin_dir.mkdir(parents=True, exist_ok=True)
            if os.path.lexists(entry) and not owned_link(entry, prefix):
                raise ValueError('Command changed during installation; refusing to overwrite it')
            link = bin_dir / ('.asyncara-' + uuid.uuid4().hex)
            try:
                link.symlink_to(destination / 'bin/asyncara')
                link.replace(entry)
            finally:
                if link.is_symlink():
                    link.unlink()
        print('Installed Asyncara ' + version + ': ' + str(entry))
        print('Check: ' + shlex.quote(str(entry)) + ' --check')
        print('Example: ' + shlex.quote(str(entry)) + ' --example 127.0.0.1:8080')
        print('Application: ' + shlex.quote(str(entry)) + ' app.php')
        if str(bin_dir) not in os.environ.get('PATH', '').split(os.pathsep):
            print('Optional PATH setting: export PATH=' + shlex.quote(str(bin_dir)) + ':"$PATH"')
        return destination


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--version', default='0.10.0')
    parser.add_argument('--php', default='php')
    parser.add_argument('--bundle', type=Path, help='Downloaded local bundle with adjacent .sha256 file')
    parser.add_argument('--prefix', type=Path, default=Path.home() / '.local/share/asyncara')
    parser.add_argument('--bin-dir', type=Path, default=Path.home() / '.local/bin')
    parser.add_argument('--list-platforms', action='store_true', help='Report all requested targets and evidence; does not install')
    parser.add_argument('--check-platform', action='store_true', help='Print detected OS/architecture without installing')
    args = parser.parse_args()
    if args.list_platforms:
        print(json.dumps(platforms(), indent=2)); return 0
    host = None
    try:
        host = platform_identity()
        if args.check_platform:
            print(json.dumps(host, indent=2)); return 0
        if host['os'] != 'ubuntu' or host['osVersion'] != '24.04' or host['arch'] not in ('x86_64', 'aarch64'):
            raise ValueError('No published/validated installer profile for ' + json.dumps(host) +
                             '. See --list-platforms. Android owner-reported tests are retained; matching bundles and ABI details are still required.')
        if not re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+', args.version):
            raise ValueError('Use a release version such as 0.10.0')
        php = shutil.which(args.php)
        if not php:
            raise ValueError('PHP CLI not found; install PHP 8.3–8.5 NTS first or pass --php')
        php = Path(php).resolve()
        identity = php_identity(php)
        if (identity['zts'] or identity['debug'] or identity['bits'] != 64
                or not re.fullmatch(r'8\.[345]\.[0-9]+', identity['version'])):
            raise ValueError('Prebuilt profile: Ubuntu 24.04, PHP 8.3–8.5 64-bit NTS non-debug only; a matching published asset is required')
        prefix = args.prefix.expanduser().resolve()
        bin_dir = args.bin_dir.expanduser().resolve()
        with tempfile.TemporaryDirectory(prefix='asyncara-download-') as temporary:
            if args.bundle:
                archive = args.bundle.resolve()
                sidecar = Path(str(archive) + '.sha256')
            else:
                name = asset_name(args.version, identity, host)
                archive = download(args.version, name, Path(temporary))
                sidecar = download(args.version, name + '.sha256', Path(temporary))
            install(archive, expected_checksum(sidecar, archive.name), args.version, php,
                    identity, host, prefix, bin_dir)
        return 0
    except (OSError, EOFError, ValueError, RuntimeError, KeyError, tarfile.TarError, subprocess.SubprocessError) as error:
        print('Installation failed: ' + str(error), file=sys.stderr)
        print('No PHP configuration was changed.', file=sys.stderr)
        if host and host['os'] == 'ubuntu':
            print('Missing runtime libraries? Install libcurl4t64 libsqlite3-0 libpq5 libssl3t64 zlib1g, then retry.', file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())
