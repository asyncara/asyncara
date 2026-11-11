#!/usr/bin/env python3
"""Private-CA path ownership and open_basedir controls, curl transport."""
import argparse,hashlib,json,os,pathlib,shutil,subprocess,sys,time,traceback
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[1]/'server'))
from tls import certificates
p=argparse.ArgumentParser()
for k in ['php','module','out']:p.add_argument('--'+k,required=True)
p.add_argument('--backend',choices=['epoll','poll'],required=True)
p.add_argument('--transport',choices=['curl-multi','hyper-prototype'],default='curl-multi')
a=p.parse_args();r=pathlib.Path(__file__).resolve().parents[2];o=pathlib.Path(a.out).resolve();o.mkdir(parents=True,exist_ok=False,mode=0o700)
certs=certificates(o);rows=[];peer=None;failure=None;sha=lambda f:hashlib.sha256(f.read_bytes()).hexdigest()
manifest={str(f.relative_to(r)):sha(f)for d in ['ext','src','tests/http']for f in(r/d).rglob('*')if f.is_file()and '__pycache__'not in f.parts}
manifest['tests/fixtures/http_stream_server.py']=sha(r/'tests/fixtures/http_stream_server.py')
(o/'source-manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
for name,source in [('trusted','ca.pem'),('untrusted','wrong.pem')]:
 (o/name).mkdir();shutil.copyfile(certs/source,o/name/'ca.pem')
(o/'allowed').mkdir()
try:
 with(o/'peer.log').open('x')as log:peer=subprocess.Popen(['python3',str(r/'tests/fixtures/http_stream_server.py'),str(certs/'server.pem'),str(certs/'server.key')],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=log,text=True)
 ports=json.loads(peer.stdout.readline());config={'url':'https://localhost:'+str(ports['https']),'trusted':str(o/'trusted'),'untrusted':str(o/'untrusted'),'allowed':str(o/'allowed'),'forbidden':str(certs/'ca.pem')}
 (o/'config.json').write_text(json.dumps(config))
 env=os.environ|{'ASYNCARA_BACKEND':a.backend,'ASYNCARA_HTTP_BACKEND':a.transport}
 for k in ['http_proxy','HTTP_PROXY','https_proxy','HTTPS_PROXY','all_proxy','ALL_PROXY']:env.pop(k,None)
 modes=['client-relative','client-config-relative','client-queued','stream-relative','upload-relative','basedir']
 if a.transport=='curl-multi':modes+=['native-relative','native-basedir','proxy-basedir','native-proxy-basedir']
 for mode in modes:
  cmd=[a.php,'-n','-d','extension='+str(pathlib.Path(a.module).resolve()),str(r/'tests/http/ca_paths.php'),str(o/'config.json'),mode];start=time.monotonic()
  with(o/(mode+'.log')).open('x')as log:
   child=subprocess.Popen(cmd,cwd=r,env=env,stdout=log,stderr=log);(o/'active.json').write_text(json.dumps({'pid':child.pid,'peerPid':peer.pid,'command':cmd}))
   try:code=child.wait(timeout=20)
   except subprocess.TimeoutExpired:child.terminate();child.wait(timeout=5);raise
  rows.append({'mode':mode,'command':cmd,'exit':code,'seconds':time.monotonic()-start,'logSha256':sha(o/(mode+'.log'))})
except BaseException as error:
 failure=repr(error);(o/'failure.log').write_text(traceback.format_exc())
finally:
 if peer is not None:
  peer.stdin.close()
  try:peer.wait(timeout=5)
  except subprocess.TimeoutExpired:peer.terminate();peer.wait(timeout=5)
assert all(sha(r/name)==digest for name,digest in manifest.items()),'Source changed during validation'
report={'status':'PASS'if failure is None and len(rows)==len(modes) and all(v['exit']==0 for v in rows)and peer.returncode==0 else'FAIL','failure':failure,'transport':a.transport,'rows':rows,'peerExit':peer.returncode,'moduleSha256':sha(pathlib.Path(a.module))}
(o/'report.json').write_text(json.dumps(report,indent=2)+'\n');(o/'active.json').write_text('{"status":"TERMINAL"}');print(json.dumps(report,indent=2));raise SystemExit(report['status']!='PASS')
