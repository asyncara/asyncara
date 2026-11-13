#!/usr/bin/env python3
"""Extract, independently phpize/build/install and exercise every supplied PHP SDK."""
import argparse,hashlib,json,os,pathlib,shutil,subprocess,tarfile,time,re
parser=argparse.ArgumentParser();parser.add_argument('--archive',required=True);parser.add_argument('--output',required=True);parser.add_argument('--dependencies',required=True);parser.add_argument('--variants',required=True)
a=parser.parse_args();archive=pathlib.Path(a.archive).resolve();out=pathlib.Path(a.output).resolve();deps=pathlib.Path(a.dependencies).resolve();variants=json.loads(pathlib.Path(a.variants).read_text())
if out.exists():parser.error('Use a new evidence directory')
out.mkdir(parents=True);report={'archive':str(archive),'sha256':hashlib.sha256(archive.read_bytes()).hexdigest(),'variants':[],'status':'RUNNING'}
base=[p for p in sorted(deps.glob('*.deb')) if not p.name.startswith(('php','valgrind','libc6-dbg','libdebuginfod','libipt'))]
def run(label,command,cwd,env,record,timeout=600):
    log=out/(record['name']+'-'+label+'.log');begin=time.monotonic()
    with log.open('w')as stream:result=subprocess.run(command,cwd=cwd,env=env,stdout=stream,stderr=subprocess.STDOUT,timeout=timeout)
    record['steps'].append({'name':label,'exit':result.returncode,'seconds':time.monotonic()-begin,'log':log.name,'sha256':hashlib.sha256(log.read_bytes()).hexdigest()})
    if result.returncode:raise RuntimeError('Failed '+record['name']+' '+label+'; see '+str(log))
    print(record['name'],label,'PASS',flush=True)
try:
    for variant in variants:
        name=variant['name'];folder=out/name;folder.mkdir();record={'name':name,'steps':[]};report['variants'].append(record)
        with tarfile.open(archive)as package:
            members=package.getmembers()
            prefixes={pathlib.PurePosixPath(m.name).parts[0] for m in members if pathlib.PurePosixPath(m.name).parts}
            if len(prefixes)!=1:raise RuntimeError('Expected one source archive root')
            prefix=prefixes.pop()
            if not re.fullmatch(r'asyncara-[0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9.-]+)?',prefix):raise RuntimeError('Invalid versioned archive root')
            for m in members:
                path=pathlib.PurePosixPath(m.name)
                if not m.isfile()or path.is_absolute()or'..'in path.parts or path.parts[0]!=prefix:raise RuntimeError('Unsafe/non-source archive member '+m.name)
            package.extractall(folder,filter='data');record['sourceFiles']=len(members)
        source=folder/prefix;local=source/'.local';(local/'sysroot').mkdir(parents=True);(local/'bin').mkdir()
        packages=base+[pathlib.Path(variant['devPackage']).resolve()]
        record['developmentPackages']={p.name:hashlib.sha256(p.read_bytes()).hexdigest()for p in packages}
        for package in packages:subprocess.run(['dpkg-deb','-x',str(package),str(local/'sysroot')],check=True)
        env=os.environ|{'PHP_BIN':variant['php']}
        for key in ['PHP_TOOL_VERSION','PHP_INCLUDE_DIR','ASYNCARA_EXTENSION','ASYNCARA_BACKEND','MARIADB_PREFIX','EXTRA_CFLAGS','EXTRA_LDFLAGS']:env.pop(key,None)
        run('phpize',['bash','scripts/phpize-local.sh'],source,env,record)
        run('install',['make','-C','ext','install','INSTALL_ROOT='+str(source/'build/install')],source,env,record)
        installed=list((source/'build/install').rglob('asyncara.so'))
        if len(installed)!=1:raise RuntimeError('Expected one installed extension')
        env['ASYNCARA_EXTENSION']=str(installed[0].relative_to(source));record['installedExtension']=env['ASYNCARA_EXTENSION'];record['extensionSha256']=hashlib.sha256(installed[0].read_bytes()).hexdigest()
        run('load',[variant['php'],'-n','-d','extension='+str(installed[0]),'--ri','asyncara'],source,env,record)
        run('composer',['composer','install','--no-interaction','--ignore-platform-req=ext-asyncara'],source,env,record)
        run('peer',['python3','scripts/install-test-websockets.py'],source,env,record)
        run('tests',['bash','scripts/test.sh'],source,env,record)
        run('poll',['bash','scripts/test.sh'],source,env|{'ASYNCARA_BACKEND':'poll'},record)
        run('websocket',['bash','scripts/test-websocket.sh'],source,env,record)
        run('network',['bash','scripts/test-network-edges.sh'],source,env,record)
        record['status']='PASS'
        (out/'report.json').write_text(json.dumps(report,indent=2)+'\n')
    report['status']='PASS'
except BaseException as error:
    report['status']='FAIL';report['error']=str(error);raise
finally:(out/'report.json').write_text(json.dumps(report,indent=2)+'\n')
