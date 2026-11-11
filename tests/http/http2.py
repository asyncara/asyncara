#!/usr/bin/env python3
"""Buffered HTTP/2 certification using an independent h2-only TLS peer."""
import argparse,hashlib,json,os,pathlib,subprocess,sys,time,traceback
import h2
p=argparse.ArgumentParser()
for key in ['php','module','out']:p.add_argument('--'+key,required=True)
p.add_argument('--backend',choices=['epoll','poll'],required=True);p.add_argument('--proxy',action='store_true')
a=p.parse_args();root=pathlib.Path(__file__).resolve().parents[2];out=pathlib.Path(a.out).resolve();out.mkdir(parents=True,exist_ok=False,mode=0o700)
sys.path.insert(0,str(root/'tests/server'));from tls import certificates
(out/'origin').mkdir();certs=certificates(out/'origin');sha=lambda f:hashlib.sha256(f.read_bytes()).hexdigest();peers=[];start=time.monotonic();code=None;error=None
manifest={str(f.relative_to(root)):sha(f)for d in ['ext','src','tests/http']for f in(root/d).rglob('*')if f.is_file()and '__pycache__'not in f.parts}
(out/'source-manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
def launch(command,name):
 with(out/(name+'.log')).open('x')as log:child=subprocess.Popen(command,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=log,text=True)
 peers.append((name,child));return child,json.loads(child.stdout.readline())
try:
 origin,ports=launch([sys.executable,str(root/'tests/http/http2_peer.py'),str(certs)],'origin')
 config={'url':'https://localhost:'+str(ports['https']),'ca':str(certs/'ca.pem')}
 if a.proxy:
  (out/'proxy').mkdir();proxy_certs=certificates(out/'proxy')
  (out/'proxy-config.json').write_text(json.dumps({'http':0,'https':ports['https'],'validCert':str(proxy_certs/'server.pem'),'mismatchCert':str(proxy_certs/'server.pem'),'key':str(proxy_certs/'server.key')}))
  proxy,proxy_ports=launch([sys.executable,str(root/'tests/http/https_proxy_peer.py'),str(out/'proxy-config.json')],'proxy')
  config.update(proxy='https://localhost:'+str(proxy_ports['proxy']),proxyCA=str(proxy_certs/'ca.pem'))
 (out/'config.json').write_text(json.dumps(config))
 env=os.environ|{'ASYNCARA_BACKEND':a.backend,'ASYNCARA_HTTP_BACKEND':'curl-multi'}
 for key in ['http_proxy','HTTP_PROXY','https_proxy','HTTPS_PROXY','all_proxy','ALL_PROXY','SSL_CERT_FILE','CURL_CA_BUNDLE']:env.pop(key,None)
 command=[a.php,'-n','-d','extension='+str(pathlib.Path(a.module).resolve()),str(root/'tests/http/http2.php'),str(out/'config.json')]
 with(out/'php.log').open('x')as log:
  child=subprocess.Popen(command,cwd=root,env=env,stdout=log,stderr=log);(out/'active.json').write_text(json.dumps({'pid':child.pid,'command':command,'peerPids':[v.pid for _,v in peers]}))
  try:code=child.wait(timeout=90)
  except subprocess.TimeoutExpired:child.terminate();child.wait(timeout=5);raise
except BaseException as failure:error=repr(failure);(out/'failure.log').write_text(traceback.format_exc())
finally:
 for name,child in reversed(peers):
  child.stdin.close()
  try:child.wait(timeout=5)
  except subprocess.TimeoutExpired:child.terminate();child.wait(timeout=5)
  (out/(name+'-final.log')).write_text(child.stdout.read())
try:
 assert code==0 and error is None,(code,error)
 assert all(p.returncode==0 for _,p in peers)
 assert all(sha(root/name)==digest for name,digest in manifest.items()),'Changed source'
 peer=json.loads((out/'origin-final.log').read_text());assert not peer['errors'],peer['errors']
 held=[v for v in peer['requests']if v['path']=='/hold/shared'];assert len(held)==4 and len({v['connection']for v in held})==1 and len({v['stream']for v in held})==4,held
 assert peer['peakHeld']>=4 and peer['resets']>=1 and peer['rejectedAlpn']>=1,peer
 if a.proxy:assert json.loads((out/'proxy-final.log').read_text())['connect']>=1
 status='PASS'
except BaseException as failure:status='FAIL';error=repr(failure)
report={'status':status,'failure':error,'exit':code,'backend':a.backend,'proxy':a.proxy,'wallSeconds':time.monotonic()-start,'h2Version':h2.__version__,'moduleSha256':sha(pathlib.Path(a.module)),'sourceManifestSha256':sha(out/'source-manifest.json'),'artifacts':{f.name:sha(f)for f in out.glob('*.log')},'command':command if 'command'in locals()else None}
(out/'report.json').write_text(json.dumps(report,indent=2)+'\n');(out/'active.json').write_text('{"status":"TERMINAL"}');print(json.dumps(report,indent=2));raise SystemExit(status!='PASS')
