#!/usr/bin/env python3
"""Independent plaintext/TLS authenticated proxy and TLS origin verification."""
import argparse, concurrent.futures, hashlib, http.client, json, os, pathlib, select, subprocess, sys, time, traceback
p=argparse.ArgumentParser()
for name in ['php','module','out']: p.add_argument('--'+name,required=True)
p.add_argument('--backend',choices=['epoll','poll'],required=True)
p.add_argument('--server',action='store_true',help='Run the same checks in a Native Server handler, plus 16 concurrent requests')
a=p.parse_args();root=pathlib.Path(__file__).resolve().parents[2]
out=pathlib.Path(a.out).resolve();out.mkdir(parents=True,exist_ok=False,mode=0o700)
sys.path.insert(0,str(root/'tests/server'));from tls import certificates
certs=certificates(out);sha=lambda f:hashlib.sha256(pathlib.Path(f).read_bytes()).hexdigest()
manifest={str(f.relative_to(root)):sha(f)for d in ['ext','src','tests/http']for f in(root/d).rglob('*')if f.is_file()and '__pycache__'not in f.parts}
(out/'source-manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
(out/'peer-config.json').write_text(json.dumps({'cert':str(certs/'server.pem'),'key':str(certs/'server.key')}))
start=time.monotonic();code=None;failure=None;peer=None;child=None;server_checks=[]
try:
    with(out/'peer.log').open('x')as log:
        peer=subprocess.Popen([sys.executable,str(root/'tests/http/proxy_auth_peer.py'),str(out/'peer-config.json')],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=log,text=True)
    config=json.loads(peer.stdout.readline());config['ca']=str(certs/'ca.pem');(out/'config.json').write_text(json.dumps(config))
    env=os.environ|{'ASYNCARA_HTTP_BACKEND':'curl-multi','ASYNCARA_BACKEND':a.backend}
    for key in ['http_proxy','HTTP_PROXY','https_proxy','HTTPS_PROXY','all_proxy','ALL_PROXY']:env.pop(key,None)
    command=[a.php,'-n','-d','extension='+str(pathlib.Path(a.module).resolve()),str(root/'tests/http/proxy_auth.php'),str(out/'config.json')]
    if a.server:command.append('server')
    with(out/'php.log').open('x')as log:
        child=subprocess.Popen(command,cwd=root,env=env,stdout=subprocess.PIPE if a.server else log,stderr=log,text=True)
        (out/'active.json').write_text(json.dumps({'pid':child.pid,'peerPid':peer.pid,'command':command}))
        if a.server:
            assert select.select([child.stdout],[],[],10)[0],'Server readiness timeout'
            ready=child.stdout.readline();log.write(ready);log.flush();assert ready.startswith('READY '),ready
            host,port=ready.strip().split(' ',1)[1].rsplit(':',1)
            def request(path):
                c=http.client.HTTPConnection(host,int(port),timeout=15)
                try:
                    c.request('GET',path);r=c.getresponse();body=r.read();assert r.status==200,(path,r.status);return body
                finally:c.close()
            with concurrent.futures.ThreadPoolExecutor(max_workers=16)as workers:
                assert all(body==b'origin-ok'for body in workers.map(request,['/probe']*16))
            server_checks.append('16_concurrent_authenticated_proxy_handlers')
            assert request('/exercise')==b'checked';server_checks.append('original_client_assertions_inside_handler')
            assert request('/stop')==b'stopped'
        try:code=child.wait(timeout=90)
        except subprocess.TimeoutExpired:child.terminate();child.wait(timeout=10);raise
        if a.server:log.write(child.stdout.read())
except BaseException as e:failure=repr(e);(out/'failure.log').write_text(traceback.format_exc())
finally:
    if child and child.poll() is None:
        child.terminate();child.wait(timeout=10)
    if peer:
        peer.stdin.close()
        try:peer.wait(timeout=15)
        except subprocess.TimeoutExpired:peer.terminate();peer.wait(timeout=5)
        (out/'peer-final.json').write_text(peer.stdout.read())
try:
    assert code==0 and failure is None,(code,failure)
    assert peer.returncode==0
    stats=json.loads((out/'peer-final.json').read_text())
    assert stats['leaked']==0 and stats['originAuthOnConnect']==0 and stats['held']==0 and stats['rejected']>=8 and stats['connect']>=4 and stats['forward']>=8,stats
    assert all(sha(root/n)==s for n,s in manifest.items()),'Source changed'
    status='PASS'
except BaseException as e:status='FAIL';failure=repr(e)
report={'status':status,'exit':code,'failure':failure,'backend':a.backend,'server':a.server,'serverChecks':server_checks,'wallSeconds':time.monotonic()-start,'command':command if 'command'in locals()else None,
    'moduleSha256':sha(a.module),'manifestSha256':sha(out/'source-manifest.json'),'artifacts':{f.name:sha(f)for f in out.iterdir()if f.suffix=='.log'or f.name=='peer-final.json'}}
(out/'report.json').write_text(json.dumps(report,indent=2)+'\n');(out/'active.json').write_text('{"status":"TERMINAL"}\n')
print(json.dumps(report,indent=2));raise SystemExit(status!='PASS')
