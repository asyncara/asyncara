#!/usr/bin/env python3
"""Attach verified bundles to an existing release; refuse changed tags/asset names."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess

p=argparse.ArgumentParser();p.add_argument('--tag',required=True);p.add_argument('--assets',type=Path,required=True)
p.add_argument('--installer-only',action='store_true',help='Attach only install.sh/checksum; preserve existing binary assets')
a=p.parse_args()
if not re.fullmatch(r'v[0-9]+\.[0-9]+\.[0-9]+',a.tag):p.error('Use a release tag')
def run(*args):return subprocess.check_output(args,text=True).strip()
remote=run('git','ls-remote','origin','refs/tags/'+a.tag,'refs/tags/'+a.tag+'^{}').splitlines()
refs=dict(line.split('\t')[::-1]for line in remote)
commit=refs.get('refs/tags/'+a.tag+'^{}',refs.get('refs/tags/'+a.tag))
archives=sorted(a.assets.glob('*.tar.gz'))
if not commit:raise SystemExit('Existing remote tag required')
if not archives and not a.installer_only:raise SystemExit('No binary assets')
files=[]
for archive in archives:
    if a.installer_only:break
    manifest=json.loads(Path(str(archive)+'.manifest.json').read_text())
    if manifest['sourceCommit']!=commit or manifest['version']!=a.tag[1:]:raise SystemExit('Tag moved or mismatched package source')
    digest=hashlib.sha256(archive.read_bytes()).hexdigest()
    if Path(str(archive)+'.sha256').read_text()!=digest+'  '+archive.name+'\n':raise SystemExit('Bad checksum')
    files.extend([archive,Path(str(archive)+'.sha256'),Path(str(archive)+'.manifest.json')])
installer=Path(__file__).resolve().parents[1]/'install.sh'
runtime=(Path(__file__).resolve().parent/'install-runtime.php').read_text()
default=re.search(r"\$options=\['version'=>'([^']+)'",runtime)[1]
if a.tag!='v'+default:raise SystemExit('Installer default version does not match requested release')
subprocess.run(['php','-n',str(Path(__file__).resolve().parent/'render-installer.php'),'--check'],check=True)
a.assets.mkdir(parents=True,exist_ok=True)
payloads={'install.sh':installer.read_bytes(),'install.sh.sha256':(hashlib.sha256(installer.read_bytes()).hexdigest()+'  install.sh\n').encode()}
for name,data in payloads.items():
    dest=a.assets/name
    if dest.exists() and dest.read_bytes()!=data:raise SystemExit('Preserve existing staging file: '+str(dest))
    if not dest.exists():dest.write_bytes(data)
    files.append(dest)
release=json.loads(run('gh','release','view',a.tag,'--repo','asyncara/asyncara','--json','assets'))
existing={asset['name'] for asset in release['assets']}
files=sorted(files)
conflicts=existing & {f.name for f in files}
if conflicts:raise SystemExit('Preserve existing release assets; conflicts: '+', '.join(sorted(conflicts)))
subprocess.run(['gh','release','upload',a.tag,'--repo','asyncara/asyncara',*map(str,files)],check=True)
