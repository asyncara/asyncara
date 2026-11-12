#!/usr/bin/env python3
"""Hyper redirect chains with independent HTTP/TLS peers and held reads."""
import argparse, hashlib, json, os, pathlib, signal, subprocess, sys, time
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[1]/'server'))
from tls import certificates
p=argparse.ArgumentParser();p.add_argument('--php',required=True);p.add_argument('--module',required=True);p.add_argument('--out',required=True);p.add_argument('--backend',choices=['epoll','poll'],default='epoll');p.set_defaults(transport='hyper-prototype');a=p.parse_args()
r=pathlib.Path(__file__).resolve().parents[2];o=pathlib.Path(a.out).resolve();o.mkdir(mode=0o700,parents=True,exist_ok=False);c=certificates(o)
env=os.environ|{'ASYNCARA_HTTP_BACKEND':a.transport,'ASYNCARA_BACKEND':a.backend}
for key in ['http_proxy','HTTP_PROXY','https_proxy','HTTPS_PROXY','all_proxy','ALL_PROXY']:env.pop(key,None)
cmd=[a.php,'-n','-d','extension='+str(pathlib.Path(a.module).resolve()),str(r/'tests/http/hyper_redirects.php'),str(c),str(o),a.transport];start=time.monotonic();error=None
with(o/'php.log').open('xb')as f:
    child=subprocess.Popen(cmd,cwd=r,stdout=f,stderr=subprocess.STDOUT,env=env,start_new_session=True)
    (o/'launch.json').write_text(json.dumps({'pid':child.pid,'command':cmd,'environment':{'ASYNCARA_HTTP_BACKEND':a.transport,'ASYNCARA_BACKEND':a.backend}}))
    try:code=child.wait(timeout=60)
    except subprocess.TimeoutExpired:
        error='60s runner watchdog exceeded';os.killpg(child.pid,signal.SIGTERM)
        try:code=child.wait(timeout=5)
        except subprocess.TimeoutExpired:os.killpg(child.pid,signal.SIGKILL);code=child.wait()
report={'mode':'WARMED_MEMORY_DIAGNOSTIC'if env.get('ASYNCARA_REDIRECT_MEMORY_WARMUP')=='1'else'DEFAULT_FIXTURE','status':'PASS'if code==0 and error is None else'FAIL','command':cmd,'exit':code,'error':error,'backend':a.backend,'transport':a.transport,'seconds':time.monotonic()-start,'moduleSha256':hashlib.sha256(pathlib.Path(a.module).read_bytes()).hexdigest(),'logSha256':hashlib.sha256((o/'php.log').read_bytes()).hexdigest()}
(o/'report.json').write_text(json.dumps(report,indent=2)+'\n');print(json.dumps(report,indent=2));raise SystemExit(report['status']!='PASS')
