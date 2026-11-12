#!/usr/bin/env python3
"""Buffered HTTP/2 certification using an independent h2-only TLS peer."""
import argparse,hashlib,json,os,pathlib,subprocess,sys,time,traceback
import h2
p=argparse.ArgumentParser()
for key in ['php','module','out']:p.add_argument('--'+key,required=True)
p.add_argument('--backend',choices=['epoll','poll'],required=True);p.add_argument('--proxy',action='store_true')
p.add_argument('--transport',choices=['curl-multi','hyper'],default='curl-multi')
p.add_argument('--case',choices=['lifecycle','early-upload','goaway'],default='lifecycle')
p.add_argument('--server',action='store_true',help='Run the same Hyper lifecycle assertions inside a native App handler')
p.add_argument('--proxy-auth',action='store_true',help='Basic authenticated HTTPS CONNECT; implies --proxy')
a=p.parse_args();root=pathlib.Path(__file__).resolve().parents[2];out=pathlib.Path(a.out).resolve();out.mkdir(parents=True,exist_ok=False,mode=0o700)
if a.proxy_auth:a.proxy=True
if a.server and (a.transport!='hyper' or a.case!='lifecycle'):p.error('--server requires Hyper lifecycle case')
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
  if a.proxy_auth:
   (out/'proxy-config.json').write_text(json.dumps({'cert':str(proxy_certs/'server.pem'),'key':str(proxy_certs/'server.key'),'targetTlsPort':ports['https']}))
   proxy,proxy_ports=launch([sys.executable,str(root/'tests/http/proxy_auth_peer.py'),str(out/'proxy-config.json')],'proxy')
   config.update(proxy=proxy_ports['httpsProxy'],proxyCredentials={'username':'asyncara-test','password':'synthetic:proxy-secret'})
  else:
   (out/'proxy-config.json').write_text(json.dumps({'http':0,'https':ports['https'],'validCert':str(proxy_certs/'server.pem'),'mismatchCert':str(proxy_certs/'server.pem'),'key':str(proxy_certs/'server.key')}))
   proxy,proxy_ports=launch([sys.executable,str(root/'tests/http/https_proxy_peer.py'),str(out/'proxy-config.json')],'proxy')
   config.update(proxy='https://localhost:'+str(proxy_ports['proxy']))
  config['proxyCA']=str(proxy_certs/'ca.pem')
 (out/'config.json').write_text(json.dumps(config))
 env=os.environ|{'ASYNCARA_BACKEND':a.backend,'ASYNCARA_HTTP_BACKEND':'hyper-prototype'if a.transport=='hyper'else'curl-multi'}
 for key in ['http_proxy','HTTP_PROXY','https_proxy','HTTPS_PROXY','all_proxy','ALL_PROXY','SSL_CERT_FILE','CURL_CA_BUNDLE']:env.pop(key,None)
 fixture='http2_goaway.php'if a.case=='goaway'else 'http2_early_upload.php'if a.case=='early-upload'else('hyper_http2.php'if a.transport=='hyper'else'http2.php')
 command=[a.php,'-n','-d','extension='+str(pathlib.Path(a.module).resolve()),str(root/'tests/http'/fixture),str(out/'config.json')]
 if a.server:command.append('--server')
 with(out/'php.log').open('x')as log:
  child=subprocess.Popen(command,cwd=root,env=env,stdout=subprocess.PIPE if a.server else log,stderr=log,text=True);(out/'active.json').write_text(json.dumps({'pid':child.pid,'command':command,'peerPids':[v.pid for _,v in peers]}))
  try:
   if a.server:
    import selectors,http.client
    with selectors.DefaultSelector()as selector:
     selector.register(child.stdout,selectors.EVENT_READ);assert selector.select(10),'Server readiness deadline'
    line=child.stdout.readline();assert line.startswith('READY '),line
    port=int(line.strip().rsplit(':',1)[1]);client=http.client.HTTPConnection('127.0.0.1',port,timeout=30)
    try:
     client.request('GET','/check');reply=client.getresponse();body=reply.read();assert reply.status==200,(reply.status,body)
     observed=json.loads(body);assert observed['ticks']==2,observed;(out/'server-response.json').write_text(json.dumps(observed,indent=2))
     client.request('GET','/stop');reply=client.getresponse();assert reply.status==200;reply.read()
    finally:client.close()
   code=child.wait(timeout=90)
   if a.server:log.write(line+child.stdout.read())
  except subprocess.TimeoutExpired:child.terminate();child.wait(timeout=5);raise
except BaseException as failure:error=repr(failure);(out/'failure.log').write_text(traceback.format_exc())
finally:
 if 'child'in locals()and child.poll()is None:child.terminate();child.wait(timeout=5)
 if a.server and 'child'in locals()and child.stdout is not None:
  with(out/'server-stdout.log').open('x')as saved:saved.write(child.stdout.read())
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
 if a.case=='lifecycle':
  held=[v for v in peer['requests']if v['path']=='/hold/shared'];assert len(held)==4 and len({v['connection']for v in held})==1 and len({v['stream']for v in held})==4,held
  assert peer['peakHeld']>=4 and peer['resets']>=1,peer
  if a.transport=='curl-multi':assert peer['rejectedAlpn']>=1,peer
  else:assert peer['rejectedAlpn']==0 and peer['earlyResponses']>=1,peer
 elif a.case=='goaway':assert len([v for v in peer['requests']if v['path']=='/goaway-drain'])==16 and peer['accepted']>=17,peer
 else:assert peer['earlyResponses']==16 and peer['resets']>=16 and peer['accepted']==1,peer
 if a.proxy:assert json.loads((out/'proxy-final.log').read_text())['connect']>=1
 status='PASS'
except BaseException as failure:status='FAIL';error=repr(failure)
report={'status':status,'failure':error,'exit':code,'backend':a.backend,'transport':a.transport,'case':a.case,'server':a.server,'proxy':a.proxy,'proxyAuth':a.proxy_auth,'wallSeconds':time.monotonic()-start,'h2Version':h2.__version__,'moduleSha256':sha(pathlib.Path(a.module)),'sourceManifestSha256':sha(out/'source-manifest.json'),'artifacts':{f.name:sha(f)for f in out.glob('*.log')},'command':command if 'command'in locals()else None}
(out/'report.json').write_text(json.dumps(report,indent=2)+'\n');(out/'active.json').write_text('{"status":"TERMINAL"}');print(json.dumps(report,indent=2));raise SystemExit(status!='PASS')
