#!/usr/bin/env python3
"""Scoped TLS/body owner cleanup, usable with a DEBUG-PHP Valgrind wrapper."""
import argparse,hashlib,http.client,json,os,pathlib,ssl,subprocess,time,traceback
from tls import certificates
p=argparse.ArgumentParser();p.add_argument('--php',required=True);p.add_argument('--module',required=True);p.add_argument('--out',required=True);a=p.parse_args()
root=pathlib.Path(__file__).resolve().parents[2];out=pathlib.Path(a.out).resolve();out.mkdir(parents=True,exist_ok=False);certs=certificates(out)
ctx=ssl.create_default_context(cafile=str(certs/'ca.pem'));cmd=[a.php,'-n','-d','extension='+str(pathlib.Path(a.module).resolve()),str(root/'tests/server/memory.php'),str(certs)]
result={'command':cmd,'moduleSha256':hashlib.sha256(pathlib.Path(a.module).read_bytes()).hexdigest()};start=time.monotonic();connections=[]
with (out/'stderr.log').open('w') as err:
    proc=subprocess.Popen(cmd,stdout=subprocess.PIPE,stderr=err,text=True);(out/'launch.json').write_text(json.dumps(result|{'pid':proc.pid}))
    try:
        line=proc.stdout.readline();assert line.startswith('READY '),line
        port=int(line.strip().rsplit(':',1)[1])
        for i in range(12):
            c=http.client.HTTPSConnection('127.0.0.1',port,context=ctx,timeout=30);connections.append(c)
            c.request('POST','/echo',body=b'x'*131072);r=c.getresponse();assert r.status==200 and r.read()==b'x'*131072
            c.request('GET','/stream');r=c.getresponse();assert r.status==200 and r.read()==b'x'*65536
            c.close()
        c=http.client.HTTPSConnection('127.0.0.1',port,context=ctx,timeout=30);connections.append(c);c.request('GET','/stop');r=c.getresponse();assert r.read()==b'ok';c.close()
        tail=proc.communicate(timeout=60)[0];(out/'stdout.log').write_text(tail);assert proc.returncode==0,(proc.returncode,tail)
        result['status']='PASS'
    except BaseException as e:result.update(status='FAIL',error=repr(e),traceback=traceback.format_exc())
    finally:
        for c in connections:c.close()
        if proc.poll() is None:
            proc.terminate()
            try:proc.wait(10)
            except subprocess.TimeoutExpired:proc.kill();proc.wait()
        result.update(exit=proc.returncode,wallSeconds=time.monotonic()-start);(out/'report.json').write_text(json.dumps(result,indent=2)+'\n')
print(json.dumps(result,indent=2));raise SystemExit(result['status']!='PASS')
