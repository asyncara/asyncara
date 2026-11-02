#!/usr/bin/env python3
"""Package tracked working-tree source; omit toolchains, secrets and binaries."""
import gzip, hashlib, io, pathlib, subprocess, tarfile

root = pathlib.Path(__file__).resolve().parent.parent
paths = sorted(p for p in subprocess.check_output(['git', '-C', str(root), 'ls-files', '-z']).decode().split('\0') if p)
if not paths:
    raise SystemExit('No tracked source: stage the intended source files before packaging.')
target = root / 'build/asyncara-0.1.0-dev.tar.gz'
target.parent.mkdir(exist_ok=True)
with target.open('wb') as raw, gzip.GzipFile(filename='', mode='wb', fileobj=raw, mtime=0) as compressed:
    with tarfile.open(fileobj=compressed, mode='w|', format=tarfile.PAX_FORMAT) as archive:
        for name in paths:
            path = root / name
            if not path.is_file() or path.is_symlink():
                raise SystemExit('Expected a regular source file: '+name)
            data = path.read_bytes()
            info = tarfile.TarInfo('asyncara-0.1.0-dev/' + name)
            info.size = len(data)
            info.mode = path.stat().st_mode & 0o777
            archive.addfile(info, io.BytesIO(data))
digest = hashlib.sha256(target.read_bytes()).hexdigest()
target.with_suffix(target.suffix + '.sha256').write_text(digest+'  '+target.name+'\n')
with tarfile.open(target) as archive:
    for name in paths:
        data = archive.extractfile('asyncara-0.1.0-dev/' + name).read()
        if data != (root/name).read_bytes():
            raise SystemExit('Archive verification failed: '+name)
print(str(target)+'\n'+digest+'\n'+str(len(paths))+' source files verified')
