#!/usr/bin/env python3
"""Resolve Hyper content-codec flags without installing or building anything.

The custom allocator in zstd's static API must use the matching static archive.
Hide its symbols so loading another PHP extension cannot interpose that API.
"""
import argparse, json, os, pathlib, shlex, subprocess

def flags():
    packages = ['libbrotlidec', 'libzstd']
    subprocess.run(['pkg-config', '--atleast-version=1.0.0', packages[0]], check=True)
    subprocess.run(['pkg-config', '--atleast-version=1.5.0', packages[1]], check=True)
    get = lambda mode: shlex.split(subprocess.check_output(['pkg-config', mode, *packages], text=True))
    cflags = get('--cflags')
    libs = shlex.split(subprocess.check_output(['pkg-config', '--static', '--libs', *packages], text=True))
    search = [x for x in libs if x.startswith('-L')]
    candidates = [pathlib.Path(x[2:])/'libzstd.a' for x in search]
    candidates.append(pathlib.Path(subprocess.check_output(shlex.split(os.environ.get('CC', 'cc')) + ['-print-file-name=libzstd.a'], text=True).strip()))
    archive = next((p.resolve() for p in candidates if p.is_absolute() and p.is_file()), None)
    if archive is None:
        raise RuntimeError('Matching libzstd.a required; configure pkg-config search paths for the development package')
    # Runtime version equality is checked before constructing a decoder, too.
    return {'cflags': cflags, 'libs': [x for x in libs if x != '-lzstd'] + [str(archive), '-Wl,--exclude-libs,libzstd.a:libbrotlidec.a:libbrotlicommon.a'], 'zstdArchive': str(archive)}

if __name__ == '__main__':
    parser = argparse.ArgumentParser(); parser.add_argument('--field', choices=['cflags','libs','zstdArchive'])
    args = parser.parse_args(); result = flags()
    print(json.dumps(result) if args.field is None else (shlex.join(result[args.field]) if isinstance(result[args.field],list) else result[args.field]))
