#!/usr/bin/env python3
"""Deferred path identity and policy, each restriction in a fresh PHP request."""
import argparse,hashlib,json,os,pathlib,subprocess,time
r=pathlib.Path(__file__).resolve().parents[1];p=argparse.ArgumentParser()
for key in ['php','module','out']:p.add_argument('--'+key,required=True)
p.add_argument('--backend',choices=['epoll','poll'],required=True);a=p.parse_args()
o=pathlib.Path(a.out).resolve();o.mkdir(parents=True,exist_ok=False,mode=0o700)
(o/'a').mkdir();(o/'b').mkdir();(o/'b/file').write_bytes(b'unchanged');(o/'a/link').symlink_to(o/'b/file')
config=o/'config.json';config.write_text(json.dumps({'allowed':str(o/'a'),'forbidden':str(o/'b/file')}))
sha=lambda f:hashlib.sha256(f.read_bytes()).hexdigest();rows=[]
manifest={str(f.relative_to(r)):sha(f)for d in ['ext','src']for f in(r/d).rglob('*')if f.is_file()}
for name in ['tests/filesystem_paths.php','tests/filesystem_path_policy.php']:manifest[name]=sha(r/name)
(o/'source-manifest.json').write_text(json.dumps(manifest,indent=2))
report={'status':'RUNNING','backend':a.backend,'moduleSha256':sha(pathlib.Path(a.module)),'commands':rows}
try:
    for mode in ['paths','native-warning','public','late','symlink','dot']:
        cmd=[a.php,'-n','-d','extension='+str(pathlib.Path(a.module).resolve()),str(r/('tests/filesystem_paths.php'if mode=='paths'else'tests/filesystem_path_policy.php'))]
        if mode!='paths':cmd += [str(config),mode]
        start=time.monotonic()
        with(o/(mode+'.log')).open('x')as log:
            child=subprocess.Popen(cmd,env=os.environ|{'ASYNCARA_BACKEND':a.backend},stdout=log,stderr=log)
            (o/'active.json').write_text(json.dumps({'pid':child.pid,'command':cmd}))
            try:code=child.wait(timeout=60)
            except subprocess.TimeoutExpired:child.terminate();child.wait(timeout=5);raise
        rows.append({'name':mode,'command':cmd,'exit':code,'seconds':time.monotonic()-start,'logSha256':sha(o/(mode+'.log'))})
        assert code==0,mode
    assert (o/'b/file').read_bytes()==b'unchanged','Rejected write changed target'
    assert all(sha(r/n)==digest for n,digest in manifest.items()),'Source changed during test'
    report['status']='PASS'
except BaseException as error:report.update(status='FAIL',error=repr(error))
finally:
    (o/'report.json').write_text(json.dumps(report,indent=2));(o/'active.json').write_text('{"status":"TERMINAL"}')
print(json.dumps(report,indent=2));raise SystemExit(report['status']!='PASS')
