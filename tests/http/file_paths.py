#!/usr/bin/env python3
"""File transfer path snapshots, including blocked client admission."""
import argparse, hashlib, json, os, pathlib, subprocess, sys, time

r=pathlib.Path(__file__).resolve().parents[2]
p=argparse.ArgumentParser()
for key in ['php','module','out']:p.add_argument('--'+key,required=True)
p.add_argument('--backend',choices=['epoll','poll'],required=True)
p.add_argument('--transport',choices=['curl-multi','hyper-prototype'],required=True)
a=p.parse_args();o=pathlib.Path(a.out).resolve();o.mkdir(parents=True,exist_ok=False,mode=0o700)
sha=lambda f:hashlib.sha256(f.read_bytes()).hexdigest()
sys.path.insert(0,str(r/'tests/server'));from tls import certificates
certs=certificates(o);peer=None;child=None;start=time.monotonic()
manifest={str(f.relative_to(r)):sha(f)for d in ['ext','src','tests/http']for f in(r/d).rglob('*')if f.is_file()and '__pycache__'not in f.parts}
manifest['tests/fixtures/http_stream_server.py']=sha(r/'tests/fixtures/http_stream_server.py')
(o/'source-manifest.json').write_text(json.dumps(manifest,indent=2))
report={'status':'RUNNING','backend':a.backend,'transport':a.transport,'moduleSha256':sha(pathlib.Path(a.module))}
try:
    with(o/'peer.log').open('x')as log:
        peer=subprocess.Popen(['python3',str(r/'tests/fixtures/http_stream_server.py'),str(certs/'server.pem'),str(certs/'server.key')],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=log,text=True)
    ports=json.loads(peer.stdout.readline());config=o/'config.json'
    config.write_text(json.dumps({'url':'http://127.0.0.1:'+str(ports['http']),'files':str(o/'files'),'transport':a.transport}))
    cmd=[a.php,'-n','-d','extension='+str(pathlib.Path(a.module).resolve()),str(r/'tests/http/file_paths.php'),str(config)]
    env=os.environ|{'ASYNCARA_BACKEND':a.backend,'ASYNCARA_HTTP_BACKEND':a.transport}
    for key in ['http_proxy','HTTP_PROXY','https_proxy','HTTPS_PROXY','all_proxy','ALL_PROXY']:env.pop(key,None)
    with(o/'php.log').open('x')as log:
        child=subprocess.Popen(cmd,stdout=log,stderr=log,env=env)
        (o/'active.json').write_text(json.dumps({'pid':child.pid,'peerPid':peer.pid,'command':cmd}))
        code=child.wait(timeout=60)
    report.update(command=cmd,exit=code,logSha256=sha(o/'php.log'))
    assert code==0,'File transfer regression failed'
    assert all(sha(r/name)==digest for name,digest in manifest.items()),'Source changed during test'
    report['status']='PASS'
except BaseException as error:report.update(status='FAIL',error=repr(error))
finally:
    if child is not None and child.poll()is None:child.terminate();child.wait(timeout=5)
    if peer is not None:
        peer.stdin.close()
        try:peer.wait(timeout=5)
        except subprocess.TimeoutExpired:peer.terminate();peer.wait(timeout=5);report.update(status='FAIL',error='Peer shutdown deadline')
        report['peerExit']=peer.returncode
        if peer.returncode:report['status']='FAIL'
    report['seconds']=time.monotonic()-start
    (o/'report.json').write_text(json.dumps(report,indent=2));(o/'active.json').write_text('{"status":"TERMINAL"}')
print(json.dumps(report,indent=2));raise SystemExit(report['status']!='PASS')
