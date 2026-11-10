#!/usr/bin/env python3
import signal,traceback
import socket
import argparse,hashlib,json,os,pathlib,subprocess,sys,time
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[1]/'server'))
from tls import certificates
p=argparse.ArgumentParser();p.add_argument('--php',required=True);p.add_argument('--module',required=True);p.add_argument('--out',required=True);p.add_argument('--backend',choices=['epoll','poll'],default='epoll');a=p.parse_args()
r=pathlib.Path(__file__).resolve().parents[2];o=pathlib.Path(a.out).resolve();o.mkdir(parents=True,exist_ok=False);c=certificates(o)
def openssl(*args):
 with(o/'cert-generation.log').open('ab')as f:subprocess.run(['openssl',*args],cwd=c,stdout=f,stderr=f,check=True)
openssl('req','-new','-newkey','ec','-pkeyopt','ec_paramgen_curve:P-256','-nodes','-subj','/CN=localhost','-keyout','dns.key','-out','dns.csr')
(c/'dns.ext').write_text('subjectAltName=DNS:localhost\nextendedKeyUsage=serverAuth\n');openssl('x509','-req','-in','dns.csr','-CA','ca.pem','-CAkey','ca.key','-CAcreateserial','-days','2','-extfile','dns.ext','-out','dns.pem');(c/'dns.key').chmod(0o600)
openssl('x509','-req','-in','server.csr','-CA','ca.pem','-CAkey','ca.key','-CAcreateserial','-days','-1','-extfile','server.ext','-out','expired.pem')
cmd=[a.php,'-n','-d','extension='+str(pathlib.Path(a.module).resolve()),str(r/'tests/http/hyper_tls.php'),str(c),str(o)]
env=os.environ|{'ASYNCARA_HTTP_BACKEND':'hyper-prototype','ASYNCARA_BACKEND':a.backend}
for key in ['http_proxy','HTTP_PROXY','https_proxy','HTTPS_PROXY','all_proxy','ALL_PROXY']:env.pop(key,None)
t=time.monotonic()
failure=None
with(o/'php.log').open('w')as f:
 child=subprocess.Popen(cmd,stdout=f,stderr=subprocess.STDOUT,env=env,start_new_session=True);(o/'launch.json').write_text(json.dumps({'pid':child.pid,'command':cmd}))
 try:code=child.wait(timeout=30)
 except subprocess.TimeoutExpired:
  failure=traceback.format_exc();os.killpg(child.pid,signal.SIGTERM)
  try:code=child.wait(timeout=5)
  except subprocess.TimeoutExpired:os.killpg(child.pid,signal.SIGKILL);code=child.wait()
boundsCode=None
if code==0 and failure is None:
 with socket.socket() as reserved:
  reserved.bind(('127.0.0.1',0)) # deliberately not listening; never contact unrelated services
  bounds=[a.php,'-n','-d','extension='+str(pathlib.Path(a.module).resolve()),str(r/'tests/http/hyper_tls_bounds.php'),str(c/'ca.pem'),str(reserved.getsockname()[1])]
  with(o/'bounds.log').open('w')as f:
   check=subprocess.Popen(bounds,stdout=f,stderr=subprocess.STDOUT,env=env,start_new_session=True)
   (o/'bounds-launch.json').write_text(json.dumps({'pid':check.pid,'command':bounds}))
   try:boundsCode=check.wait(timeout=30)
   except subprocess.TimeoutExpired:
    failure=traceback.format_exc();os.killpg(check.pid,signal.SIGTERM)
    try:boundsCode=check.wait(timeout=5)
    except subprocess.TimeoutExpired:os.killpg(check.pid,signal.SIGKILL);boundsCode=check.wait()
result={'boundsExit':boundsCode,'status':'PASS' if code==0 and boundsCode==0 and failure is None else 'FAIL','exit':code,'command':cmd,'backend':a.backend,'httpBackend':'hyper-prototype','wallSeconds':time.monotonic()-t,'moduleSha256':hashlib.sha256(pathlib.Path(a.module).read_bytes()).hexdigest(),'logSha256':hashlib.sha256((o/'php.log').read_bytes()).hexdigest(),'failure':failure};(o/'report.json').write_text(json.dumps(result,indent=2));print(json.dumps(result,indent=2));raise SystemExit(result['status']!='PASS')
