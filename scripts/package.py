#!/usr/bin/env python3
"""Package tracked working-tree source; omit toolchains, secrets and binaries."""
import argparse, gzip, hashlib, io, pathlib, subprocess, tarfile, re

root = pathlib.Path(__file__).resolve().parent.parent
match = re.search(r'^#define PHP_ASYNCARA_VERSION "([0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9.-]+)?)"$',
                  (root / 'ext/php_asyncara.h').read_text(), re.M)
if not match:
    raise SystemExit('Invalid native release version')
prefix = 'asyncara-' + match[1]
parser = argparse.ArgumentParser()
parser.add_argument('--output', default='build/' + prefix + '.tar.gz')
parser.add_argument('--include-untracked', action='store_true', help='Include reviewed, non-ignored new source files')
options = parser.parse_args()
paths = sorted(p for p in subprocess.check_output(['git', '-C', str(root), 'ls-files', '-z']).decode().split('\0') if p)
if options.include_untracked:
    paths = sorted(set(paths) | {p for p in subprocess.check_output(['git', '-C', str(root), 'ls-files', '--others', '--exclude-standard', '-z']).decode().split('\0') if p})
if not paths:
    raise SystemExit('No tracked source: stage the intended source files before packaging.')
target = root / options.output
target.parent.mkdir(exist_ok=True)
if target.exists() or target.with_suffix(target.suffix + '.sha256').exists():
    raise SystemExit('Preserve historical artifacts: choose a new --output path.')
with target.open('xb') as raw, gzip.GzipFile(filename='', mode='wb', fileobj=raw, mtime=0) as compressed:
    with tarfile.open(fileobj=compressed, mode='w|', format=tarfile.PAX_FORMAT) as archive:
        for name in paths:
            path = root / name
            if not path.is_file() or path.is_symlink():
                raise SystemExit('Expected a regular source file: '+name)
            data = path.read_bytes()
            info = tarfile.TarInfo(prefix + '/' + name)
            info.size = len(data)
            # Preserve executable intent, not the checkout user's umask/group
            # permissions. Clean checkouts must produce identical archives.
            info.mode = 0o755 if path.stat().st_mode & 0o100 else 0o644
            archive.addfile(info, io.BytesIO(data))
digest = hashlib.sha256(target.read_bytes()).hexdigest()
target.with_suffix(target.suffix + '.sha256').write_text(digest+'  '+target.name+'\n')
with tarfile.open(target) as archive:
    for name in paths:
        data = archive.extractfile(prefix + '/' + name).read()
        if data != (root/name).read_bytes():
            raise SystemExit('Archive verification failed: '+name)
print(str(target)+'\n'+digest+'\n'+str(len(paths))+' source files verified')
