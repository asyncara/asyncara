#!/usr/bin/env python3
import ssl
from tls import certificates
import argparse, concurrent.futures, hashlib, http.client, json, os, pathlib, signal, socket, subprocess, time, traceback
p=argparse.ArgumentParser();p.add_argument('--php',required=True);p.add_argument('--module',required=True);p.add_argument('--posix',required=True);p.add_argument('--out',required=True);p.add_argument('--failure',choices=['none','worker-exit'],default='none');p.add_argument('--tls',action='store_true');p.add_argument('--input',action='store_true');a=p.parse_args()
o=pathlib.Path(a.out);o.mkdir(parents=True,exist_ok=False);root=pathlib.Path(__file__).resolve().parents[2]
with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
cmd=[a.php,'-n','-d','extension='+a.posix,'-d','extension='+str(pathlib.Path(a.module).resolve()),str(root/'tests/server/workers.php'),str(port)]
certs=certificates(o) if a.tls else None
if certs:cmd.append(str(certs))
ctx=ssl.create_default_context(cafile=str(certs/'ca.pem')) if certs else None
result={'tls':a.tls,'command':cmd,'moduleSha256':hashlib.sha256(pathlib.Path(a.module).read_bytes()).hexdigest()};began=time.monotonic()
with (o/'stderr.log').open('w') as err:
 child=subprocess.Popen(cmd,stdout=subprocess.PIPE,stderr=err,text=True,start_new_session=True,env=os.environ|({'ASYNCARA_TEST_STREAM_REQUESTS':'1'} if a.input else {}))
 (o/'launch.json').write_text(json.dumps(result|{'pid':child.pid}))
 try:
  line=child.stdout.readline();assert line.startswith('READY '),line
  def req(path, body=None):
   c=http.client.HTTPSConnection('127.0.0.1',port,timeout=5,context=ctx) if ctx else http.client.HTTPConnection('127.0.0.1',port,timeout=5)
   try:c.request('POST' if body is not None else 'GET',path,body=body);r=c.getresponse();b=r.read();assert r.status==200,(r.status,b);return b
   finally:c.close()
  with concurrent.futures.ThreadPoolExecutor(max_workers=16) as pool:
   replies=list(pool.map(lambda _:json.loads(req('/pid')),range(256)))
   assert len({r['pid'] for r in replies})==4, replies
   list(pool.map(lambda _:req('/set'),range(32)))
   states=list(pool.map(lambda _:json.loads(req('/pid')),range(64)))
   if a.input:
    payload=bytes(range(256))*256
    inputs=list(pool.map(lambda _:json.loads(req('/input',payload)),range(128)))
    assert all(x['bytes']==len(payload) and x['sha256']==hashlib.sha256(payload).hexdigest() for x in inputs),inputs
    result['inputRequests']=len(inputs)
  assert all(r['state'] is None for r in states),states
  pids={r['pid'] for r in replies};result['workerPids']=sorted(pids)
  if a.failure=='worker-exit':
   # Kill only a child whose PID was returned by our isolated fixture.
   victim=min(pids)
   fields=pathlib.Path('/proc',str(victim),'stat').read_text().rsplit(')',1)[1].split()
   assert int(fields[1])==child.pid,'Worker ownership changed'
   os.kill(victim,signal.SIGKILL)
  else:child.send_signal(signal.SIGTERM)
  tail=child.communicate(timeout=10)[0];(o/'stdout.log').write_text(tail)
  assert (child.returncode==0)==(a.failure=='none'),(child.returncode,tail)
  if a.failure=='worker-exit':assert 'HTTP worker failed' in tail+(o/'stderr.log').read_text()
  assert all(not pathlib.Path('/proc',str(pid)).exists() for pid in pids)
  result.update(status='PASS',failureMode=a.failure)
 except BaseException as e:result.update(status='FAIL',error=repr(e),traceback=traceback.format_exc())
 finally:
  if child.poll() is None:
   os.killpg(child.pid,signal.SIGTERM)
   try:child.wait(10)
   except subprocess.TimeoutExpired:os.killpg(child.pid,signal.SIGKILL);child.wait()
  result.update(exitCode=child.returncode,wallSeconds=time.monotonic()-began)
  (o/'report.json').write_text(json.dumps(result,indent=2)+'\n')
print(json.dumps(result,indent=2));raise SystemExit(result['status']!='PASS')
