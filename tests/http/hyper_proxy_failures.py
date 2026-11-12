#!/usr/bin/env python3
import argparse,json,subprocess,pathlib,sys,os,time,hashlib
p=argparse.ArgumentParser()
for key in ['php','module','out','backend']:p.add_argument('--'+key,required=True)
a=p.parse_args();root=pathlib.Path(__file__).resolve().parents[2];out=pathlib.Path(a.out).resolve();out.mkdir(parents=True,exist_ok=False);sys.path.insert(0,str(root/'tests/server'));from tls import certificates
certs=certificates(out);peers=[];sha=lambda p:hashlib.sha256(pathlib.Path(p).read_bytes()).hexdigest();start=time.monotonic();error=None;code=None
manifest={str(p.relative_to(root)):sha(p)for d in ['ext','src','tests/http']for p in(root/d).rglob('*')if p.is_file()and '__pycache__'not in p.parts};(out/'source-manifest.json').write_text(json.dumps(manifest,indent=2))
def peer(name,cmd):
 with(out/(name+'.log')).open('x')as log:child=subprocess.Popen(cmd,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=log,text=True)
 peers.append((name,child));return json.loads(child.stdout.readline())
try:
 origin=peer('origin',['python3',str(root/'tests/fixtures/http_stream_server.py'),str(certs/'server.pem'),str(certs/'server.key')]);config=peer('proxy',['python3',str(root/'tests/http/hyper_proxy_peer.py'),str(origin['https'])]);config.update(origin='https://localhost:'+str(origin['https']),ca=str(certs/'ca.pem'));(out/'config.json').write_text(json.dumps(config))
 env=os.environ|{'ASYNCARA_BACKEND':a.backend,'ASYNCARA_HTTP_BACKEND':'hyper-prototype'}
 for key in ['http_proxy','HTTP_PROXY','https_proxy','HTTPS_PROXY','all_proxy','ALL_PROXY']:env.pop(key,None)
 cmd=[a.php,'-n','-d','extension='+str(pathlib.Path(a.module).resolve()),str(root/'tests/http/hyper_proxy_failures.php'),str(out/'config.json')]
 with(out/'php.log').open('x')as log:
  child=subprocess.Popen(cmd,env=env,cwd=root,stdout=log,stderr=log);(out/'active.json').write_text(json.dumps({'pid':child.pid,'peers':[p.pid for _,p in peers],'command':cmd}));code=child.wait(timeout=90)
except BaseException as exc:error=repr(exc)
finally:
 if 'child'in locals()and child.poll()is None:child.terminate();child.wait(timeout=5)
 for name,peer in reversed(peers):
  peer.stdin.close()
  try:peer.wait(timeout=5)
  except subprocess.TimeoutExpired:peer.terminate();peer.wait(timeout=5)
  (out/(name+'-final.json')).write_text(peer.stdout.read())
try:
 assert code==0 and error is None,(code,error)
 stats=json.loads((out/'proxy-final.json').read_text());assert stats['held']==0 and stats['active']==0 and all(v>=1 for v in stats['counts'].values()),stats
 assert all(p.returncode==0 for _,p in peers)
 assert all(sha(root/n)==s for n,s in manifest.items()),'Changed source';status='PASS'
except BaseException as exc:error=repr(exc);status='FAIL'
report={'status':status,'exit':code,'failure':error,'command':cmd if 'cmd'in locals()else None,'seconds':time.monotonic()-start,'moduleSha256':sha(a.module),'artifacts':{p.name:sha(p)for p in out.iterdir()if p.is_file()}};(out/'report.json').write_text(json.dumps(report,indent=2));(out/'active.json').write_text('{"status":"TERMINAL"}');print(json.dumps(report,indent=2));raise SystemExit(status!='PASS')
