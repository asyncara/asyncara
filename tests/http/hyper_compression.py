#!/usr/bin/env python3
"""Bounded Hyper gzip/deflate decoding and real pending-I/O progress."""
import argparse,hashlib,json,os,pathlib,signal,subprocess,sys,time
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[1]/'server'))
from tls import certificates
p=argparse.ArgumentParser()
for key in ['php','module','out']:p.add_argument('--'+key,required=True)
p.add_argument('--backend',choices=['epoll','poll'],required=True);p.add_argument('--bounds',action='store_true');a=p.parse_args()
r=pathlib.Path(__file__).resolve().parents[2];o=pathlib.Path(a.out).resolve();o.mkdir(parents=True,exist_ok=False);certs=certificates(o)
cmd=[a.php,'-n','-d','extension='+str(pathlib.Path(a.module).resolve()),str(r/'tests/http/hyper_compression.php'),str(certs),str(o)]
if a.bounds:cmd.append('--bounds')
env=os.environ|{'ASYNCARA_HTTP_BACKEND':'hyper-prototype','ASYNCARA_BACKEND':a.backend}
for key in ['http_proxy','HTTP_PROXY','https_proxy','HTTPS_PROXY','all_proxy','ALL_PROXY']:env.pop(key,None)
sha=lambda f:hashlib.sha256(f.read_bytes()).hexdigest()
manifest={str(f.relative_to(r)):sha(f)for d in ['ext','src','tests/http']for f in(r/d).rglob('*')if f.is_file()and '__pycache__'not in f.parts}
(o/'source-manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
t=time.monotonic();timed_out=False
with(o/'php.log').open('x')as f:
    child=subprocess.Popen(cmd,stdout=f,stderr=subprocess.STDOUT,env=env,start_new_session=True);(o/'launch.json').write_text(json.dumps({'pid':child.pid,'command':cmd}))
    try:code=child.wait(timeout=120)
    except subprocess.TimeoutExpired:
        timed_out=True;os.killpg(child.pid,signal.SIGTERM)
        try:code=child.wait(timeout=5)
        except subprocess.TimeoutExpired:os.killpg(child.pid,signal.SIGKILL);code=child.wait()
assert all(sha(r/name)==digest for name,digest in manifest.items()), 'Source changed during validation'
result={'scope':'compression-bounds'if a.bounds else'buffered-compression','status':'PASS'if code==0 and not timed_out else'FAIL','exit':code,'timeout':timed_out,'command':cmd,'backend':a.backend,'wallSeconds':time.monotonic()-t,'moduleSha256':hashlib.sha256(pathlib.Path(a.module).read_bytes()).hexdigest(),'logSha256':hashlib.sha256((o/'php.log').read_bytes()).hexdigest()}
(o/'report.json').write_text(json.dumps(result,indent=2)+'\n');print(json.dumps(result,indent=2));raise SystemExit(result['status']!='PASS')
