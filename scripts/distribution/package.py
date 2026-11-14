#!/usr/bin/env python3
"""Build a relocatable, version-specific binary bundle; not a universal PHP ABI."""
import argparse
import gzip
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tarfile
import tempfile

TOOLS = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('installer', TOOLS / 'install.py')
installer = importlib.util.module_from_spec(spec); spec.loader.exec_module(installer)


def command(args, **kwargs):
    return subprocess.check_output(list(map(str, args)), text=True, **kwargs).strip()


def minimum_glibc(paths):
    """Required ELF symbol versions, including the statically linked Rust code."""
    versions = set()
    for path in paths:
        versions.update(re.findall(r'Name: GLIBC_([0-9]+\.[0-9]+)(?:\s|$)', command(['readelf','--version-info',path])))
    if not versions:
        raise RuntimeError('Cannot establish the packaged ELF glibc requirement')
    return max(versions, key=lambda value: tuple(map(int, value.split('.'))))


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--source', type=Path, required=True)
    p.add_argument('--source-commit', required=True)
    p.add_argument('--module', type=Path, required=True)
    p.add_argument('--connector', type=Path, required=True, help='Private Connector/C build root with source.tar.gz and install/')
    p.add_argument('--php', required=True)
    p.add_argument('--cargo', type=Path, required=True, help='Cargo executable; metadata supplies third-party notices')
    p.add_argument('--output', type=Path, required=True)
    a = p.parse_args(); source = a.source.resolve(); out = a.output.resolve()
    if not re.fullmatch('[0-9a-f]{40}', a.source_commit): p.error('Provide exact source commit')
    version = re.search(r'^#define PHP_ASYNCARA_VERSION "([0-9.]+)"$', (source/'ext/php_asyncara.h').read_text(), re.M)[1]
    php = installer.php_identity(a.php); host = installer.platform_identity()
    if host['os'] != 'ubuntu' or host['osVersion'] != '24.04' or host['arch'] not in ('x86_64','aarch64') or php['zts'] or php['debug'] or php['bits'] != 64:
        p.error('Uncertified binary build profile')
    portable = dict(host, os='linux-glibc', osVersion='')
    name = installer.asset_name(version, php, portable); out.mkdir(parents=True, exist_ok=True)
    archive = out/name
    if archive.exists() or Path(str(archive)+'.sha256').exists(): p.error('Preserve existing assets; use a fresh output directory')
    with tempfile.TemporaryDirectory(prefix='asyncara-binary-package-') as temp:
        root = Path(temp); runtime = root/'runtime'; runtime.mkdir()
        for name in ['autoload.php','composer.json','composer.lock','LICENSE','src','examples','vendor']:
            item=source/name; dest=runtime/name
            if item.is_dir(): shutil.copytree(item,dest,symlinks=True)
            else: shutil.copy2(item,dest)
        for name in ['ext/php_asyncara.h','scripts/check-install.php','tests/release/smoke.php']:
            dest=runtime/name;dest.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(source/name,dest)
        (root/'extension').mkdir();module=root/'extension/asyncara.so';shutil.copy2(a.module,module)
        (root/'lib').mkdir();connector=root/'lib/libasyncara-mariadb.so.3'
        shutil.copy2(a.connector/'install/lib/mariadb/libmariadb.so.3',connector)
        command(['patchelf','--set-soname','libasyncara-mariadb.so.3',connector])
        command(['patchelf','--set-rpath','$ORIGIN',connector])
        command(['patchelf','--replace-needed','libmariadb.so.3','libasyncara-mariadb.so.3',module])
        command(['patchelf','--set-rpath','$ORIGIN/../lib',module])
        for path in [module,connector]:
            dynamic=command(['readelf','-d',path])
            if re.search(r'\[(?:/home/|/tmp/)',dynamic): raise RuntimeError('Build-specific dynamic path retained')
        # Relocatable authentication uses compiled-in common auth plugins; no
        # absolute development plugin directory may be needed at runtime.
        cache=(a.connector/'build/CMakeCache.txt').read_text()
        for plugin in ['CACHING_SHA2_PASSWORD','SHA256_PASSWORD','CLIENT_ED25519','MYSQL_CLEAR_PASSWORD','DIALOG']:
            if not re.search(r'CLIENT_PLUGIN_'+plugin+r':[^=]+=STATIC$',cache,re.M):
                raise RuntimeError('Connector must build '+plugin+' statically for relocation')
        notices=root/'notices';notices.mkdir()
        csource=a.connector/'mariadb-connector-c-3.4.10'
        shutil.copy2(csource/'COPYING.LIB',notices/'MariaDB-COPYING.LIB')
        shutil.copy2(a.connector/'source.tar.gz',notices/'mariadb-connector-c-3.4.10-source.tar.gz')
        rust_target = command(['rustc', '-vV']).split('host: ')[1].splitlines()[0]
        metadata=json.loads(command([a.cargo,'metadata','--locked','--filter-platform',rust_target,'--format-version','1','--manifest-path',source/'ext/hyper/Cargo.toml']))
        resolved = {node['id'] for node in metadata['resolve']['nodes']}
        licenses=[]
        for package in metadata['packages']:
            if package['name']=='asyncara-hyper-bridge' or package['id'] not in resolved: continue
            directory=Path(package['manifest_path']).parent
            candidates={f for pattern in ['LICENSE*','COPYING*','NOTICE*'] for f in directory.glob(pattern) if f.is_file()}
            if package.get('license_file'): candidates.add(directory/package['license_file'])
            if not candidates: raise RuntimeError('Missing third-party notice: '+package['name'])
            dest=notices/'rust'/(package['name']+'-'+package['version']);dest.mkdir(parents=True)
            for f in candidates: shutil.copy2(f,dest/f.name)
            licenses.append({'name':package['name'],'version':package['version'],'license':package.get('license'),'repository':package.get('repository')})
        (notices/'rust-packages.json').write_text(json.dumps(licenses,indent=2)+'\n')
        (notices/'README.txt').write_text('Asyncara LICENSE applies to Asyncara only. Rust dependency notices and pinned Connector/C source are supplied separately. System libcurl/libpq/SQLite/OpenSSL and PHP are not bundled. Connector/C is a replaceable shared library; the source archive is unmodified.\n')
        check=[a.php,'-n','-d','extension='+str(module)]
        info=json.loads(command(check+[runtime/'scripts/check-install.php','--require-hyper','--require-sql']))
        if info['status']!='PASS': raise RuntimeError('Relocated package failed installation check')
        command(check+[runtime/'tests/release/smoke.php'])
        files={}
        for f in sorted(root.rglob('*')):
            if f.is_symlink():raise RuntimeError('Do not ship symlinks: '+str(f))
            if f.is_file():files[str(f.relative_to(root))]=installer.sha(f)
        manifest={'schema':2,'project':'asyncara/runtime','version':version,'php':php,'platform':host,
                  'compatibility':{'os':'linux','libc':'glibc','arch':host['arch'],'minimumGlibc':minimum_glibc([module,connector])},
                  'sourceCommit':a.source_commit,'features':{'hyper':True,'postgresql':True,'mysql':True},
                  'connector':'3.4.10/OpenSSL; compiled-in common authentication plugins','files':files}
        (root/'manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
        with archive.open('xb')as raw,gzip.GzipFile(filename='',mode='wb',fileobj=raw,mtime=0)as compressed,tarfile.open(fileobj=compressed,mode='w|')as tar:
            for f in sorted(root.rglob('*')):
                if not f.is_file():continue
                data=f.read_bytes();entry=tarfile.TarInfo('asyncara/'+str(f.relative_to(root)));entry.size=len(data);entry.mode=0o644;tar.addfile(entry,io.BytesIO(data))
        digest=installer.sha(archive);Path(str(archive)+'.sha256').write_text(digest+'  '+archive.name+'\n')
        (out/(archive.name+'.manifest.json')).write_text(json.dumps(manifest,indent=2)+'\n')
        print(json.dumps({'archive':str(archive),'sha256':digest,'files':len(files),'php':php,'platform':host}))


if __name__=='__main__': main()
