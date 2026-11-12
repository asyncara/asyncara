#!/usr/bin/env python3
"""Client certificate identity, pooling and real-kernel cancellation checks."""
import argparse, hashlib, json, os, pathlib, signal, subprocess, sys, time
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[1]/'server'))
from tls import certificates
p=argparse.ArgumentParser();p.add_argument('--php',required=True);p.add_argument('--module',required=True);p.add_argument('--out',required=True);p.add_argument('--backend',choices=['epoll','poll'],default='epoll');p.add_argument('--transport',choices=['curl-multi','hyper-prototype'],default='hyper-prototype');a=p.parse_args()
r=pathlib.Path(__file__).resolve().parents[2];o=pathlib.Path(a.out).resolve();o.mkdir(mode=0o700,parents=True,exist_ok=False);c=certificates(o)
def openssl(*args):
    with(o/'cert-generation.log').open('ab')as f:subprocess.run(['openssl',*args],cwd=c,stdout=f,stderr=f,check=True)
openssl('req','-new','-newkey','ec','-pkeyopt','ec_paramgen_curve:P-256','-nodes','-subj','/CN=second-client','-keyout','client2.key','-out','client2.csr')
openssl('x509','-req','-in','client2.csr','-CA','ca.pem','-CAkey','ca.key','-CAcreateserial','-days','2','-extfile','client.ext','-out','client2.pem')
openssl('x509','-req','-in','client.csr','-CA','ca.pem','-CAkey','ca.key','-CAcreateserial','-days','-1','-extfile','client.ext','-out','expired.pem')
(c/'client2.key').chmod(0o600)
(c/'identities.json').write_text(json.dumps({name:hashlib.sha256(subprocess.check_output(['openssl','x509','-in',str(c/(name+'.pem')),'-outform','DER'])).hexdigest()for name in ['client','client2']}))
env=os.environ|{'ASYNCARA_HTTP_BACKEND':a.transport,'ASYNCARA_BACKEND':a.backend}
for key in ['http_proxy','HTTP_PROXY','https_proxy','HTTPS_PROXY','all_proxy','ALL_PROXY']:env.pop(key,None)
cmd=[a.php,'-n','-d','extension='+str(pathlib.Path(a.module).resolve()),str(r/'tests/http/client_mtls.php'),str(c),str(o),a.transport];start=time.monotonic();error=None
with(o/'php.log').open('xb')as f:
    child=subprocess.Popen(cmd,cwd=r,stdout=f,stderr=subprocess.STDOUT,env=env,start_new_session=True)
    (o/'launch.json').write_text(json.dumps({'pid':child.pid,'command':cmd,'environment':{'ASYNCARA_HTTP_BACKEND':a.transport,'ASYNCARA_BACKEND':a.backend}}))
    try:code=child.wait(timeout=60)
    except subprocess.TimeoutExpired:
        error='60s runner watchdog exceeded';os.killpg(child.pid,signal.SIGTERM)
        try:code=child.wait(timeout=5)
        except subprocess.TimeoutExpired:os.killpg(child.pid,signal.SIGKILL);code=child.wait()
policy_code=None;policy_pid=None
if code==0 and error is None:
    policy=[a.php,'-n','-d','extension='+str(pathlib.Path(a.module).resolve()),str(r/'tests/http/identity_options.php'),str(c/'client.pem'),str(c/'client.key')]
    with(o/'native-policy.log').open('xb')as f:
        proc=subprocess.Popen(policy,cwd=r,stdout=f,stderr=subprocess.STDOUT,env=env,start_new_session=True);policy_pid=proc.pid
        (o/'policy-launch.json').write_text(json.dumps({'pid':proc.pid,'command':policy}))
        try:policy_code=proc.wait(timeout=30)
        except subprocess.TimeoutExpired:os.killpg(proc.pid,signal.SIGTERM);policy_code=proc.wait(timeout=5);error='Native policy watchdog exceeded'
report={'primaryPid':child.pid,'policyPid':policy_pid,'policyExit':policy_code,'mode':'WARMED_MEMORY_DIAGNOSTIC'if env.get('ASYNCARA_MTLS_MEMORY_WARMUP')=='1'else('DIAGNOSTIC'if env.get('ASYNCARA_MTLS_DIAGNOSTIC')=='1'else'DEFAULT_FIXTURE'),'status':'PASS'if code==0 and policy_code==0 and error is None else'FAIL','command':cmd,'exit':code,'error':error,'backend':a.backend,'transport':a.transport,'seconds':time.monotonic()-start,'moduleSha256':hashlib.sha256(pathlib.Path(a.module).read_bytes()).hexdigest(),'logSha256':hashlib.sha256((o/'php.log').read_bytes()).hexdigest()}
(o/'report.json').write_text(json.dumps(report,indent=2)+'\n');print(json.dumps(report,indent=2));raise SystemExit(report['status']!='PASS')
