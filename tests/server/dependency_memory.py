#!/usr/bin/env python3
"""Unsuppressed differential controls for server TLS; never a clean-result filter.

Build the locked bridge first, then point --deps at its release/deps directory.
RUSTUP_HOME/CARGO_HOME and VALGRIND_LIB may select isolated local toolchains.
"""
import argparse, hashlib, json, os, pathlib, subprocess, time
from tls import certificates

def main():
    p=argparse.ArgumentParser()
    p.add_argument('--deps',type=pathlib.Path,required=True)
    p.add_argument('--out',type=pathlib.Path,required=True)
    p.add_argument('--rustc',default='rustc')
    p.add_argument('--valgrind',default='valgrind')
    p.add_argument('--extra-debuginfo-path')
    p.add_argument('--provider', choices=['ring','aws'], default='ring')
    a=p.parse_args();a.out=a.out.resolve();a.out.mkdir(parents=True,exist_ok=False)
    root=pathlib.Path(__file__).resolve().parents[2];deps=a.deps.resolve();rows=[]
    certs=certificates(a.out)
    def library(name):
        paths=list(deps.glob('lib'+name+'-*.rlib'))
        if len(paths)!=1:raise RuntimeError('Expected exactly one '+name+' rlib; use a fresh Cargo target')
        return str(paths[0])
    def run(name,cmd):
        start=time.monotonic();log=a.out/(name+'.log')
        with log.open('x') as f:
            proc=subprocess.Popen(cmd,stdout=f,stderr=subprocess.STDOUT)
            (a.out/'active.json').write_text(json.dumps({'pid':proc.pid,'command':cmd,'status':'RUNNING'}))
            code=proc.wait()
        row={'name':name,'command':cmd,'exit':code,'wallSeconds':time.monotonic()-start,'logSha256':hashlib.sha256(log.read_bytes()).hexdigest()}
        rows.append(row);(a.out/'report.json').write_text(json.dumps({'status':'RUNNING','rows':rows},indent=2));return code
    run('rustc-version',[a.rustc,'--version','--verbose']);run('valgrind-version',[a.valgrind,'--version'])
    for name in (['ring_control'] if a.provider=='ring' else [])+['rustls_aead_control','rustls_control']:
        source=root/'tests/server'/(name+'.rs');exe=a.out/name
        externs=['ring'] if name=='ring_control' else ['rustls']+(['rustls_pemfile'] if name=='rustls_control' else [])
        cmd=[a.rustc,'--edition=2021','-g','-O',str(source),'-L','dependency='+str(deps),'-o',str(exe)]
        if a.provider=='aws':cmd+=['--cfg','feature="aws"']
        for lib in externs:cmd+=['--extern',lib+'='+library(lib)]
        if run(name+'-build',cmd):break
        variants=['AES_128_GCM','AES_256_GCM','CHACHA20_POLY1305'] if name=='rustls_control' else ['direct']
        for variant in variants:
            args=[str(certs),variant] if name=='rustls_control' else []
            if run(name+'-'+variant+'-plain',[str(exe)]+args):continue
            cmd=[a.valgrind,'--track-origins=yes','--leak-check=full','--show-leak-kinds=all','--errors-for-leak-kinds=definite,indirect','--error-exitcode=99','--log-file='+str(a.out/(name+'-'+variant+'-memcheck.log'))]
            if a.extra_debuginfo_path:cmd+=['--extra-debuginfo-path='+a.extra_debuginfo_path]
            run(name+'-'+variant+'-valgrind',cmd+[str(exe)]+args)
    status='FAIL' if any(x['exit'] for x in rows) else 'PASS'
    result={'status':status,'provider':a.provider,'rows':rows,'sources':{x:hashlib.sha256((root/'tests/server'/x).read_bytes()).hexdigest() for x in ['ring_control.rs','rustls_aead_control.rs','rustls_control.rs']}}
    (a.out/'active.json').write_text(json.dumps({'status':'TERMINAL'}));(a.out/'report.json').write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result,indent=2));raise SystemExit(status!='PASS')
if __name__=='__main__':main()
