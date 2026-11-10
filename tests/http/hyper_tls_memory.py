#!/usr/bin/env python3
"""Scoped memory control; --certs reads the functional TLS runner's private fixtures."""
import argparse, hashlib, json, os, pathlib, signal, subprocess, time
p=argparse.ArgumentParser()
for key in ['php','module','certs','out']: p.add_argument('--'+key,required=True)
p.add_argument('--backend',choices=['epoll','poll'],required=True);a=p.parse_args()
r=pathlib.Path(__file__).resolve().parents[2];o=pathlib.Path(a.out).resolve();o.mkdir(parents=True,exist_ok=False)
c=pathlib.Path(a.certs).resolve();assert (c/'expired.pem').is_file(), 'Run hyper_tls.py to generate the isolated certificates first'
cmd=[a.php,'-n','-d','extension='+str(pathlib.Path(a.module).resolve()),str(r/'tests/http/hyper_tls_memory.php'),str(c),str(o)]
env=os.environ|{'ASYNCARA_HTTP_BACKEND':'hyper-prototype','ASYNCARA_BACKEND':a.backend}
for key in ['http_proxy','HTTP_PROXY','https_proxy','HTTPS_PROXY','all_proxy','ALL_PROXY']:env.pop(key,None)
t=time.monotonic();timed_out=False
with (o/'php.log').open('x') as log:
    child=subprocess.Popen(cmd,env=env,stdout=log,stderr=subprocess.STDOUT,start_new_session=True)
    (o/'launch.json').write_text(json.dumps({'pid':child.pid,'command':cmd}))
    try:code=child.wait(timeout=120)
    except subprocess.TimeoutExpired:
        timed_out=True;os.killpg(child.pid,signal.SIGTERM)
        try:code=child.wait(timeout=5)
        except subprocess.TimeoutExpired:os.killpg(child.pid,signal.SIGKILL);code=child.wait()
result={'status':'PASS' if code==0 and not timed_out else 'FAIL','scope':'diagnostic memory, not latency acceptance','command':cmd,'exit':code,'timeout':timed_out,'backend':a.backend,'wallSeconds':time.monotonic()-t,'moduleSha256':hashlib.sha256(pathlib.Path(a.module).read_bytes()).hexdigest(),'logSha256':hashlib.sha256((o/'php.log').read_bytes()).hexdigest()}
(o/'report.json').write_text(json.dumps(result,indent=2)+'\n');print(json.dumps(result,indent=2));raise SystemExit(result['status']!='PASS')
