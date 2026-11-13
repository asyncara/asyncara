#!/usr/bin/env python3
"""Attach verified bundles to an existing release; refuse changed tags/asset names."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import shutil
import subprocess

p=argparse.ArgumentParser();p.add_argument('--tag',required=True);p.add_argument('--assets',type=Path,required=True);a=p.parse_args()
if not re.fullmatch(r'v[0-9]+\.[0-9]+\.[0-9]+',a.tag):p.error('Use a release tag')
def run(*args):return subprocess.check_output(args,text=True).strip()
remote=run('git','ls-remote','origin','refs/tags/'+a.tag,'refs/tags/'+a.tag+'^{}').splitlines()
refs=dict(line.split('\t')[::-1]for line in remote)
commit=refs.get('refs/tags/'+a.tag+'^{}',refs.get('refs/tags/'+a.tag))
archives=sorted(a.assets.glob('*.tar.gz'))
if not archives:raise SystemExit('No binary assets')
for archive in archives:
    manifest=json.loads(Path(str(archive)+'.manifest.json').read_text())
    if manifest['sourceCommit']!=commit or manifest['version']!=a.tag[1:]:raise SystemExit('Tag moved or mismatched package source')
    digest=hashlib.sha256(archive.read_bytes()).hexdigest()
    if Path(str(archive)+'.sha256').read_text()!=digest+'  '+archive.name+'\n':raise SystemExit('Bad checksum')
installer=Path(__file__).resolve().parents[1]/'install.py'
shutil.copy2(installer,a.assets/'install.py')
(a.assets/'install.py.sha256').write_text(hashlib.sha256(installer.read_bytes()).hexdigest()+'  install.py\n')
release=json.loads(run('gh','release','view',a.tag,'--repo','asyncara/asyncara','--json','assets'))
existing={asset['name'] for asset in release['assets']}
files=sorted(f for f in a.assets.iterdir() if f.is_file())
conflicts=existing & {f.name for f in files}
if conflicts:raise SystemExit('Preserve existing release assets; conflicts: '+', '.join(sorted(conflicts)))
subprocess.run(['gh','release','upload',a.tag,'--repo','asyncara/asyncara',*map(str,files)],check=True)
