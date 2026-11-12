#!/usr/bin/env python3
"""Real SOCKS5 integration; original evidence is never overwritten."""
import argparse, hashlib, json, os, pathlib, subprocess, sys, time, traceback
p=argparse.ArgumentParser()
for key in ['php','module','backend','out']:p.add_argument('--'+key,required=True)
p.add_argument('--transport',choices=['hyper','curl-multi'],default='hyper')
p.add_argument('--curl-control',action='store_true',help='Scoped curl positive control, excludes local DNS fallback and malformed peer certification')
a=p.parse_args();root=pathlib.Path(__file__).resolve().parents[2];out=pathlib.Path(a.out).resolve();out.mkdir(parents=True,exist_ok=False,mode=0o700)
sys.path.insert(0,str(root/'tests/server'));from tls import certificates
certs=certificates(out);sha=lambda p:hashlib.sha256(pathlib.Path(p).read_bytes()).hexdigest()
manifest={str(f.relative_to(root)):sha(f)for d in ['ext','src','tests/http']for f in(root/d).rglob('*')if f.is_file()and '__pycache__'not in f.parts}
(out/'source-manifest.json').write_text(json.dumps(manifest,indent=2));(out/'peer-config.json').write_text(json.dumps({'cert':str(certs/'server.pem'),'key':str(certs/'server.key')}))
start=time.monotonic();failure=None;code=None;child=None;peer=None
try:
 with(out/'peer.log').open('x')as log:peer=subprocess.Popen([sys.executable,str(root/'tests/http/socks5_peer.py'),str(out/'peer-config.json')],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=log,text=True)
 config=json.loads(peer.stdout.readline());config.update(ca=str(certs/'ca.pem'),wrongCa=str(certs/'wrong.pem'));(out/'config.json').write_text(json.dumps(config))
 env=os.environ|{'ASYNCARA_BACKEND':a.backend,'ASYNCARA_HTTP_BACKEND':'hyper-prototype'if a.transport=='hyper'else'curl-multi'}
 for key in ['http_proxy','HTTP_PROXY','https_proxy','HTTPS_PROXY','all_proxy','ALL_PROXY']:env.pop(key,None)
 command=[a.php,'-n','-d','extension='+str(pathlib.Path(a.module).resolve()),str(root/'tests/http/socks5.php'),str(out/'config.json')]
 if a.curl_control:command.append('--curl-control')
 with(out/'php.log').open('x')as log:
  child=subprocess.Popen(command,cwd=root,env=env,stdout=log,stderr=log);(out/'active.json').write_text(json.dumps({'pid':child.pid,'peerPid':peer.pid,'supervisorPid':os.getpid(),'command':command}));code=child.wait(timeout=90)
except BaseException as error:failure=repr(error);(out/'failure.log').write_text(traceback.format_exc())
finally:
 if child and child.poll()is None:child.terminate();child.wait(timeout=5)
 if peer:
  peer.stdin.close()
  try:peer.wait(timeout=5)
  except subprocess.TimeoutExpired:peer.terminate();peer.wait(timeout=5)
  (out/'peer-final.json').write_text(peer.stdout.read())
try:
 assert code==0 and failure is None,(code,failure)
 stats=json.loads((out/'peer-final.json').read_text());assert peer.returncode==0 and not stats['errors'] and not stats['leaked'] and not stats['held'] and not stats['active'],stats
 assert stats['auth']>=(4 if a.curl_control else 6) and stats['rejected']>=2,stats
 expected=['plain','auth','held','stall','v6reply','domainreply']if a.curl_control else[k for k in stats['counts']if k!='downgrade' or a.transport=='hyper']
 assert all(stats['counts'][k]>=1 for k in expected),stats
 assert any(v['type']==3 and v['host']=='proxy-only.invalid'for v in stats['targets'])
 assert any(v['type']in[1,4]and v['mode']=='auth'for v in stats['targets'])
 if a.transport=='hyper':assert len([v for v in stats['targets']if v['mode']=='auth'])<=8,'Repeated rejected-address handshake despite reusable resolved connection'
 if stats['ipv6']:assert any(v['type']==4 for v in stats['targets'])
 assert all(sha(root/n)==s for n,s in manifest.items()),'Source changed'
 status='PASS'
except BaseException as error:failure=repr(error);status='FAIL'
report={'status':status,'failure':failure,'exit':code,'backend':a.backend,'transport':a.transport,'scope':'positive curl control; excludes local DNS fallback and malformed replies'if a.curl_control else'full SOCKS5 transport','seconds':time.monotonic()-start,'moduleSha256':sha(a.module),'manifestSha256':sha(out/'source-manifest.json'),'command':command if 'command'in locals()else None,'artifacts':{f.name:sha(f)for f in out.iterdir()if f.suffix in ['.log','.json']and f.name!='active.json'}}
(out/'report.json').write_text(json.dumps(report,indent=2));(out/'active.json').write_text('{"status":"TERMINAL"}\n');print(json.dumps(report,indent=2));raise SystemExit(status!='PASS')
