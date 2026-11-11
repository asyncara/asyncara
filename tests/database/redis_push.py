#!/usr/bin/env python3
"""Isolated real Redis RESP2/3 push ownership, verified TLS and ACL controls."""
import argparse,hashlib,json,os,pathlib,secrets,socket,subprocess,sys,time,traceback
p=argparse.ArgumentParser()
for key in ['php','module','out']:p.add_argument('--'+key,required=True)
p.add_argument('--backend',choices=['epoll','poll'],required=True);a=p.parse_args()
r=pathlib.Path(__file__).resolve().parents[2];o=pathlib.Path(a.out).resolve();o.mkdir(parents=True,exist_ok=False,mode=0o700)
sys.path.insert(0,str(r/'tests/server'));from tls import certificates
certs=certificates(o);sha=lambda p:hashlib.sha256(p.read_bytes()).hexdigest();rows=[];child=None;start=time.monotonic()
manifest={str(f.relative_to(r)):sha(f)for d in ['ext','src','tests/database']for f in(r/d).rglob('*')if f.is_file()and '__pycache__'not in f.parts}
(o/'source-manifest.json').write_text(json.dumps(manifest,indent=2))
def port():
 with socket.socket()as s:s.bind(('127.0.0.1',0));return s.getsockname()[1]
plain=port();tls=port();password=secrets.token_hex(24)
acl=o/'users.acl';acl.write_text('user default off\nuser asyncara on #'+hashlib.sha256(password.encode()).hexdigest()+' ~asyncara:* &asyncara:* +@all\n');acl.chmod(0o600)
command=['redis-server','--bind','127.0.0.1','--port',str(plain),'--tls-port',str(tls),'--tls-cert-file',str(certs/'server.pem'),'--tls-key-file',str(certs/'server.key'),'--tls-ca-cert-file',str(certs/'ca.pem'),'--tls-auth-clients','no','--aclfile',str(acl),'--save','','--appendonly','no','--dir',str(o)]
report={'status':'RUNNING','backend':a.backend,'redisVersion':subprocess.check_output(['redis-server','--version'],text=True).strip(),'moduleSha256':sha(pathlib.Path(a.module)),'commands':rows}
try:
 with(o/'redis.log').open('x')as log:child=subprocess.Popen(command,stdout=log,stderr=log)
 (o/'active.json').write_text(json.dumps({'pid':child.pid,'command':command}));deadline=time.monotonic()+5
 while True:
  if child.poll()is not None:raise RuntimeError('Redis startup failed')
  try:
   with socket.create_connection(('127.0.0.1',plain),.1):break
  except OSError:
   if time.monotonic()>deadline:raise
   time.sleep(.01)
 for secured in [False,True]:
  name='tls'if secured else'plain';config=o/(name+'.json');config.write_text(json.dumps({'port':tls if secured else plain,'tls':secured,'password':password,'ca':str(certs/'ca.pem')}));config.chmod(0o600)
  cmd=[a.php,'-n','-d','extension='+str(pathlib.Path(a.module).resolve()),str(r/'tests/database/redis_push.php'),str(config)];began=time.monotonic()
  with(o/(name+'.log')).open('x')as log:result=subprocess.run(cmd,env=os.environ|{'ASYNCARA_BACKEND':a.backend},stdout=log,stderr=log,timeout=60)
  rows.append({'name':name,'command':cmd,'exit':result.returncode,'seconds':time.monotonic()-began,'logSha256':sha(o/(name+'.log'))});assert result.returncode==0,name
 assert all(sha(r/n)==v for n,v in manifest.items()),'Source changed during test'
 report['status']='PASS'
except BaseException as error:report.update(status='FAIL',error=repr(error));(o/'failure.log').write_text(traceback.format_exc())
finally:
 if child is not None and child.poll()is None:child.terminate();child.wait(timeout=5)
 report.update(seconds=time.monotonic()-start,serverExit=child.returncode if child else None);(o/'report.json').write_text(json.dumps(report,indent=2));(o/'active.json').write_text('{"status":"TERMINAL"}')
print(json.dumps(report,indent=2));raise SystemExit(report['status']!='PASS')
