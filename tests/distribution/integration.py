#!/usr/bin/env python3
"""Install the REAL bundle away from its build tree; exercise autoload and App."""
import argparse
import hashlib
import http.client
import json
import os
from pathlib import Path
import re
import selectors
import subprocess
import time

p=argparse.ArgumentParser(description=__doc__)
p.add_argument('--bundle',type=Path,required=True);p.add_argument('--php',required=True);p.add_argument('--output',type=Path,required=True)
a=p.parse_args();out=a.output.resolve();out.mkdir(parents=True,exist_ok=False)
root=Path(__file__).resolve().parents[2];started=time.monotonic();commands=[]
def run(cmd, env=None):
    r=subprocess.run(list(map(str,cmd)),capture_output=True,text=True,timeout=90,env=env)
    index=len(commands);(out/f'{index:02d}.log').write_text(r.stdout+r.stderr)
    commands.append({'argv':list(map(str,cmd)),'exit':r.returncode,'log':f'{index:02d}.log'})
    if r.returncode:raise RuntimeError(r.stdout+r.stderr)
    return r.stdout
try:
    args=['python3',root/'scripts/install.py','--php',a.php,'--bundle',a.bundle.resolve(),'--prefix',out/'prefix with spaces','--bin-dir',out/'bin with spaces']
    run(args);run(args)
    executable=out/'bin with spaces/asyncara'
    info=json.loads(run([executable,'--check']));assert info['status']=='PASS',info
    run([executable,'--version'])
    app=out/'autoload.php';app.write_text('<?php Asyncara\\Async::run(function () { Asyncara\\Async::sleep(.001); echo "AUTOLOAD_OK\\n"; });')
    assert run([executable,app]).strip()=='AUTOLOAD_OK'
    destination=executable.resolve().parent.parent
    for backend in ['epoll','poll']:
        env=dict(os.environ,ASYNCARA_BACKEND=backend)
        server_log=(out/f'server-{backend}.log').open('w')
        server=subprocess.Popen([executable,'--example','127.0.0.1:0'],stdout=subprocess.PIPE,stderr=server_log,text=True,env=env)
        try:
            with selectors.DefaultSelector() as selector:
                selector.register(server.stdout,selectors.EVENT_READ)
                if not selector.select(10):raise RuntimeError('Server did not report readiness')
                line=server.stdout.readline();server_log.write(line);server_log.flush()
            match=re.search(r'http://127.0.0.1:(\d+)',line);assert match,line
            port=int(match[1]);conn=http.client.HTTPConnection('127.0.0.1',port,timeout=10)
            conn.request('GET','/status');r=conn.getresponse();assert r.status==200;assert json.loads(r.read())['status']=='OK'
            conn.request('GET','/work/12?delay_ms=2&payload_bytes=31');r=conn.getresponse();assert r.status==200;assert json.loads(r.read())=={'worker_id':12,'payload':'x'*31}
            conn.request('POST','/data',body='{"ok":true}',headers={'Content-Type':'application/json'});r=conn.getresponse();assert r.status==201;assert json.loads(r.read())['received']=={'ok':True}
            conn.request('POST','/api/v1/upload',body='');r=conn.getresponse();assert r.status==401;r.read();conn.close()
            for transport in ['curl-multi','hyper-prototype']:
                client=out/'http.php'
                client.write_text('<?php Asyncara\\Async::run(function () { $c=new Asyncara\\HTTP\\HttpClient(); $r=$c->get("http://127.0.0.1:'+str(port)+'/status")->await(); if($r->status!==200) throw new RuntimeException("HTTP"); echo "HTTP_OK\\n"; });')
                # Same installed launcher, no require or development build path.
                assert run([executable,client],dict(env,ASYNCARA_HTTP_BACKEND=transport)).strip()=='HTTP_OK'
        finally:
            server.terminate()
            try:server.wait(timeout=10)
            except subprocess.TimeoutExpired:server.kill();server.wait();raise
            server_log.close();server.stdout.close()
    result={'status':'PASS','seconds':time.monotonic()-started,'php':a.php,'bundle':str(a.bundle.resolve()),
            'archiveSha256':hashlib.sha256(a.bundle.read_bytes()).hexdigest(),'moduleSha256':hashlib.sha256((destination/'extension/asyncara.so').read_bytes()).hexdigest(),
            'installed':str(destination),'tests':['real install','idempotence','automatic Composer autoload','epoll/poll App routes, POST, auth','curl/Hyper HTTP requests'], 'commands':commands}
except BaseException as error:
    result={'status':'FAIL','error':repr(error),'commands':commands,'seconds':time.monotonic()-started}
    (out/'report.json').write_text(json.dumps(result,indent=2)+'\n');raise
(out/'report.json').write_text(json.dumps(result,indent=2)+'\n');print(json.dumps(result))
