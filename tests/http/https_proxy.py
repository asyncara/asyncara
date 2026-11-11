#!/usr/bin/env python3
"""Verify separate origin/proxy TLS trust using independent private CAs."""
import argparse,hashlib,json,os,pathlib,subprocess,sys,time,traceback
p=argparse.ArgumentParser()
for key in ['php','module','out']:p.add_argument('--'+key,required=True)
p.add_argument('--backend',choices=['epoll','poll'],required=True)
a=p.parse_args();root=pathlib.Path(__file__).resolve().parents[2];out=pathlib.Path(a.out).resolve();out.mkdir(parents=True,exist_ok=False,mode=0o700)
sys.path.insert(0,str(root/'tests/server'));from tls import certificates
for name in ['origin','proxy']:(out/name).mkdir(mode=0o700)
origin_certs=certificates(out/'origin');proxy_certs=certificates(out/'proxy')
(proxy_certs/'mismatch.ext').write_text('subjectAltName=DNS:wrong-proxy.invalid\nextendedKeyUsage=serverAuth\n')
with(out/'mismatch-generation.log').open('x')as log:
 subprocess.run(['openssl','x509','-req','-in','server.csr','-CA','ca.pem','-CAkey','ca.key','-CAcreateserial','-days','2','-extfile','mismatch.ext','-out','mismatch.pem'],cwd=proxy_certs,stdout=log,stderr=log,check=True)
sha=lambda f:hashlib.sha256(f.read_bytes()).hexdigest()
manifest={str(f.relative_to(root)):sha(f)for d in ['ext','src','tests/http']for f in(root/d).rglob('*')if f.is_file()and '__pycache__'not in f.parts}
(out/'source-manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
peers=[];failure=None;code=None;start=time.monotonic()
def peer(cmd,name):
 with(out/(name+'.log')).open('x')as log:child=subprocess.Popen(cmd,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=log,text=True)
 peers.append(child);return child,json.loads(child.stdout.readline())
try:
 origin,ports=peer(['python3',str(root/'tests/fixtures/http_stream_server.py'),str(origin_certs/'server.pem'),str(origin_certs/'server.key')],'origin-peer')
 cfg=ports|{'validCert':str(proxy_certs/'server.pem'),'mismatchCert':str(proxy_certs/'mismatch.pem'),'key':str(proxy_certs/'server.key')}
 (out/'peer-config.json').write_text(json.dumps(cfg))
 proxy,ports2=peer(['python3',str(root/'tests/http/https_proxy_peer.py'),str(out/'peer-config.json')],'proxy-peer')
 config={'proxy':'https://localhost:'+str(ports2['proxy']),'mismatchProxy':'https://localhost:'+str(ports2['mismatch']),'origin':'https://localhost:'+str(ports['https']),'originCA':str(origin_certs/'ca.pem'),'proxyCA':str(proxy_certs/'ca.pem'),'proxyDirectory':str(proxy_certs),'originDirectory':str(origin_certs)}
 (out/'config.json').write_text(json.dumps(config))
 env=os.environ|{'ASYNCARA_BACKEND':a.backend,'ASYNCARA_HTTP_BACKEND':'curl-multi'}
 for key in ['http_proxy','HTTP_PROXY','https_proxy','HTTPS_PROXY','all_proxy','ALL_PROXY','SSL_CERT_FILE','CURL_CA_BUNDLE']:env.pop(key,None)
 cmd=[a.php,'-n','-d','extension='+str(pathlib.Path(a.module).resolve()),str(root/'tests/http/https_proxy.php'),str(out/'config.json')]
 with(out/'php.log').open('x')as log:
  child=subprocess.Popen(cmd,cwd=root,env=env,stdout=log,stderr=log);(out/'active.json').write_text(json.dumps({'pid':child.pid,'peerPids':[p.pid for p in peers],'command':cmd}))
  try:code=child.wait(timeout=90)
  except subprocess.TimeoutExpired:child.terminate();child.wait(timeout=10);raise
except BaseException as error:failure=repr(error);(out/'failure.log').write_text(traceback.format_exc())
finally:
 for child in reversed(peers):
  child.stdin.close()
  try:child.wait(timeout=5)
  except subprocess.TimeoutExpired:child.terminate();child.wait(timeout=5)
  remaining=child.stdout.read();(out/('peer-'+str(child.pid)+'-final.log')).write_text(remaining)
assert all(sha(root/name)==digest for name,digest in manifest.items()),'Source changed during validation'
report={'status':'PASS'if code==0 and failure is None and all(p.returncode==0 for p in peers)else'FAIL','command':cmd if 'cmd'in locals()else None,'exit':code,'failure':failure,'backend':a.backend,'wallSeconds':time.monotonic()-start,'peerExits':[p.returncode for p in peers],'moduleSha256':sha(pathlib.Path(a.module)),'logSha256':sha(out/'php.log')if(out/'php.log').exists()else None}
(out/'report.json').write_text(json.dumps(report,indent=2)+'\n');(out/'active.json').write_text('{"status":"TERMINAL"}');print(json.dumps(report,indent=2));raise SystemExit(report['status']!='PASS')
