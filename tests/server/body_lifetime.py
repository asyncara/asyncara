#!/usr/bin/env python3
"""Completed request bodies must not be captured by a persistent connection Fiber."""
import argparse, hashlib, http.client, json, os, pathlib, subprocess, time, traceback
p=argparse.ArgumentParser();p.add_argument('--php',required=True);p.add_argument('--module',required=True);p.add_argument('--out',required=True);a=p.parse_args()
root=pathlib.Path(__file__).resolve().parents[2];out=pathlib.Path(a.out);out.mkdir(parents=True,exist_ok=False)
cmd=[a.php,'-n','-d','extension='+str(pathlib.Path(a.module).resolve()),str(root/'tests/server/body_lifetime.php')]
result={'command':cmd,'backend':os.getenv('ASYNCARA_BACKEND','epoll'),'moduleSha256':hashlib.sha256(pathlib.Path(a.module).read_bytes()).hexdigest()};connections=[];began=time.monotonic()
with (out/'stderr.log').open('w') as err:
 proc=subprocess.Popen(cmd,stdout=subprocess.PIPE,stderr=err,text=True)
 (out/'launch.json').write_text(json.dumps(result|{'pid':proc.pid}))
 try:
  line=proc.stdout.readline();assert line.startswith('READY '),line
  port=int(line.strip().rsplit(':',1)[1]);control=http.client.HTTPConnection('127.0.0.1',port,timeout=5);connections.append(control)
  def request(conn,path,method='GET',body=None):
   conn.request(method,path,body=body);response=conn.getresponse();data=response.read();assert response.status==200,(response.status,data);return data
  # Warm lazy class loading and both routes before taking the baseline.
  request(control,'/discard','POST',b'')
  request(control,'/memory');before=json.loads(request(control,'/memory'))
  for _ in range(8):
   c=http.client.HTTPConnection('127.0.0.1',port,timeout=5);connections.append(c)
   assert request(c,'/discard','POST',b'x'*524288)==b'ok'
  # Connections remain open, so their persistent Fibers are still alive.
  after=json.loads(request(control,'/memory'));result.update(before=before,after=after,deltaPhpBytes=after['phpBytes']-before['phpBytes'])
  assert after['native']['connections']==9,after
  assert after['native']['bufferBytes']==0,after
  assert result['deltaPhpBytes']<1048576,result # not eight retained half-MiB bodies
  for c in connections[1:]:c.close()
  request(control,'/stop');control.close();tail=proc.communicate(timeout=5)[0];(out/'stdout.log').write_text(tail);assert proc.returncode==0
  result['status']='PASS'
 except BaseException as e:result.update(status='FAIL',error=repr(e),traceback=traceback.format_exc())
 finally:
  for c in connections:c.close()
  if proc.poll() is None:
   proc.terminate()
   try:proc.wait(timeout=5)
   except subprocess.TimeoutExpired:proc.kill();proc.wait()
  result.update(exitCode=proc.returncode,wallSeconds=time.monotonic()-began);(out/'report.json').write_text(json.dumps(result,indent=2)+'\n')
print(json.dumps(result,indent=2));raise SystemExit(result['status']!='PASS')
