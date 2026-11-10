#!/usr/bin/env python3
"""Real socket protocol/lifecycle checks. No Asyncara HTTP client in the oracle."""
import argparse, asyncio, hashlib, json, os, pathlib, subprocess, time, traceback

async def response(reader, head=False):
    raw = await asyncio.wait_for(reader.readuntil(b'\r\n\r\n'), 5)
    lines = raw.split(b'\r\n'); status = int(lines[0].split()[1])
    headers = dict(line.split(b':',1) for line in lines[1:] if b':' in line)
    headers = {k.lower():v.strip() for k,v in headers.items()}
    assert b'transfer-encoding' not in headers, headers
    data = b'' if head else await asyncio.wait_for(reader.readexactly(int(headers.get(b'content-length',b'0'))), 5)
    return status, headers, data

async def checks(port):
    completed=[]
    async def connect(): return await asyncio.open_connection('127.0.0.1', port)
    async def request(path, method='GET', body=b'', headers=b''):
        r,w = await connect()
        try:
            w.write(f'{method} {path} HTTP/1.1\r\nHost: localhost\r\nContent-Length: {len(body)}\r\n'.encode()+headers+b'\r\n'+body)
            await w.drain(); return await response(r, method=='HEAD')
        finally: w.close(); await w.wait_closed()
    s,h,b=await request('/status'); assert s==200 and json.loads(b)['engine']=='Asyncara/Rust'; completed.append('basic')
    s,h,b=await request('/work/42?payload_bytes=17'); assert json.loads(b)=={'worker_id':42,'payload':'x'*17}; completed.append('params_query')
    s,h,b=await request('/data','POST',b'{"a":[1,2]}'); assert s==201 and json.loads(b)['received']=={'a':[1,2]}; completed.append('json_native')
    s,h,b=await request('/io'); assert s==200 and json.loads(b)=={'file':True,'http':'OK','db':42},(s,b); completed.append('client_files_sqlite_in_handler')
    assert (await request('/data','POST',b'{'))[0]==400
    assert (await request('/api/v1/users'))[0]==200
    assert (await request('/api/v1/upload','POST'))[0]==401
    assert (await request('/api/v1/upload','POST',headers=b'Authorization: test-token\r\n'))[2]==b'Done'; completed.append('groups_middleware')
    assert (await request('/missing'))[0]==404
    for path in ['/error','/bad-header','/oversized']:
        s,h,b=await request(path); assert s==500 and b'secret' not in b and b'injected' not in h
    completed.append('errors_safe')
    assert (await request('/data','POST',b'x'*1025))[0]==413; completed.append('body_limit')
    s,h,b=await request('/status','HEAD'); assert s==200 and b==b'' and int(h[b'content-length'])>0; completed.append('head')
    r,w=await connect()
    w.write(b'GET /status HTTP/1.1\r\nHost: localhost\r\n\r\n'*3); await w.drain()
    for _ in range(3): assert (await response(r))[0]==200
    for _ in range(10):
        w.write(b'GET /set-context HTTP/1.1\r\nHost: localhost\r\n\r\nGET /get-context HTTP/1.1\r\nHost: localhost\r\n\r\n'); await w.drain()
        assert (await response(r))[2]==b'ok'
        assert json.loads((await response(r))[2])=={'value':None}
    w.close(); await w.wait_closed(); completed.append('pipeline')
    r,w=await connect()
    w.write(b'POST /data HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n3\r\n{"x\r\n4\r\n":1}\r\n0\r\n\r\n')
    await w.drain(); s,h,b=await response(r); assert s==201 and json.loads(b)['received']=={'x':1}
    w.close(); await w.wait_closed(); completed.append('chunked')
    for packet in [b'GET /status HTTP/1.1\r\n\r\n', b'GET /status HTTP/1.1\r\nHost: a\r\nHost: b\r\n\r\n', b'POST /data HTTP/1.1\r\nHost: a\r\nContent-Length: 1\r\nContent-Length: 2\r\n\r\nx', b'GET /status HTTP/1.1\r\nHost: user@localhost\r\n\r\n', b'GET /status HTTP/1.1\r\nHost: localhost:bad\r\n\r\n']:
        r,w=await connect(); w.write(packet); await w.drain()
        rejected=await response(r);assert rejected[0]==400,(packet,rejected)
        w.close(); await w.wait_closed()
    completed.append('invalid_host_and_framing')
    # Two timers must fire while this independently held request is incomplete.
    r,w=await connect(); w.write(b'GET /hold HTTP/1.1\r\nHost: localhost\r\n\r\n'); await w.drain()
    pending=asyncio.create_task(response(r)); observed=None
    for _ in range(200):
        observed=json.loads((await request('/metrics'))[2])
        if observed['ticks']>=2: break
        await asyncio.sleep(.001)
    assert observed['ticks']>=2 and observed['pending']==1 and not pending.done(), observed
    assert (await request('/release'))[0]==200
    assert (await pending)[2]==b'released'
    w.close(); await w.wait_closed(); completed.append('two_ticks_before_independent_release')
    results=await asyncio.gather(*[request(f'/work/{i}?delay_ms=30&payload_bytes=1024') for i in range(32)])
    assert all(s==200 and len(json.loads(b)['payload'])==1024 for s,h,b in results); completed.append('32_concurrent_fibers')
    r,w=await connect(); w.write(b'GET /never HTTP/1.1\r\nHost: localhost\r\n\r\n'); await w.drain()
    for _ in range(200):
        metrics=json.loads((await request('/metrics'))[2])
        if metrics['pending']==1: break
        await asyncio.sleep(.001)
    assert metrics['pending']==1, metrics
    w.close(); await w.wait_closed()
    deadline=time.monotonic()+3
    while time.monotonic()<deadline:
        metrics=json.loads((await request('/metrics'))[2])
        if metrics['cancelled']==1: break
        await asyncio.sleep(.005)
    assert metrics['cancelled']==1 and metrics['pending']==0, metrics
    completed.append('disconnect_cancels_handler')
    # Header limit, partial input and deadline cleanup.
    r,w=await connect(); w.write(b'GET /status HTTP/1.1\r\nHost: localhost\r\nX: '+b'x'*70000+b'\r\n\r\n'); await w.drain()
    oversized_head=await response(r)
    assert oversized_head[0]==431, oversized_head
    w.close(); await w.wait_closed(); completed.append('header_limit')
    r,w=await connect(); w.write(b'GET /status HTTP/1.1\r\nHost:'); await w.drain()
    assert await asyncio.wait_for(r.read(),4)==b''
    w.close(); await w.wait_closed(); completed.append('partial_header_deadline')
    metrics=json.loads((await request('/metrics'))[2])
    assert metrics['pending']==0 and metrics['native']['bufferBytes']==0, metrics
    assert metrics['native']['requestsHandled'] > metrics['native']['handlerTasksCreated']+20, metrics
    assert metrics['native']['timedOut']>=1 and metrics['native']['connections']<=4, metrics
    s,h,b=await request('/large'); assert s==200 and b==b'x'*2097152
    completed.append('vectored_large_response')
    # Reserve a control connection, then fill all other native admission slots.
    cr,cw=await connect()
    async def control():
        cw.write(b'GET /metrics HTTP/1.1\r\nHost: localhost\r\n\r\n'); await cw.drain()
        return json.loads((await response(cr))[2])
    await control()
    idle=[await connect() for _ in range(63)]
    snapshot=await control(); assert snapshot['native']['connections']==64,snapshot
    er,ew=await connect();ew.write(b'GET /status HTTP/1.1\r\nHost: localhost\r\n\r\n');await ew.drain()
    extra=asyncio.create_task(response(er))
    snapshot=await control();assert snapshot['native']['connections']==64 and not extra.done(),snapshot
    idle[0][1].close();await idle[0][1].wait_closed()
    assert (await extra)[0]==200
    for _,writer in idle[1:]:writer.close();await writer.wait_closed()
    ew.close();await ew.wait_closed();cw.close();await cw.wait_closed()
    completed.append('bounded_connection_admission')
    assert (await request('/stop'))[2]==b'stopped'; completed.append('graceful_stop')
    return {'checks':completed,'metrics':metrics,'progress':observed}

def main():
    p=argparse.ArgumentParser(); p.add_argument('--php',required=True); p.add_argument('--module',required=True); p.add_argument('--out',required=True); p.add_argument('--backend',choices=['epoll','poll'],default='epoll'); args=p.parse_args()
    out=pathlib.Path(args.out); out.mkdir(parents=True,exist_ok=False)
    root=pathlib.Path(__file__).resolve().parents[2]
    cmd=[args.php,'-n','-d','extension='+str(pathlib.Path(args.module).resolve()),str(root/'tests/server/fixture.php')]
    env=os.environ|{'ASYNCARA_BACKEND':args.backend}
    gitroot=subprocess.run(['git','rev-parse','--show-toplevel'],cwd=root,text=True,capture_output=True)
    in_checkout=gitroot.returncode==0 and pathlib.Path(gitroot.stdout.strip()).resolve()==root.resolve()
    source=subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip() if in_checkout else None
    start=time.monotonic(); result={'command':cmd,'backend':args.backend,'moduleSha256':hashlib.sha256(pathlib.Path(args.module).read_bytes()).hexdigest(),'source':source,'sourceIdentity':'git+manifest' if in_checkout else 'manifest-only; see package attestation'}
    manifest={str(f.relative_to(root)):hashlib.sha256(f.read_bytes()).hexdigest() for d in ['ext','src','tests/server'] for f in (root/d).rglob('*') if f.is_file() and '__pycache__' not in f.parts}
    (out/'source-manifest.json').write_text(json.dumps(manifest,sort_keys=True,indent=2))
    (out/'source.patch').write_bytes(subprocess.check_output(['git','diff','--binary','HEAD'],cwd=root) if in_checkout else b'')
    with (out/'stderr.log').open('w') as stderr:
        proc=subprocess.Popen(cmd,stdout=subprocess.PIPE,stderr=stderr,env=env,text=True)
        (out/'launch.json').write_text(json.dumps(result|{'pid':proc.pid},indent=2))
        try:
            line=proc.stdout.readline(); (out/'ready.txt').write_text(line)
            assert line.startswith('READY '),line
            result.update(asyncio.run(checks(int(line.strip().rsplit(':',1)[1]))))
            tail=proc.communicate(timeout=5)[0]; (out/'stdout.log').write_text(tail)
            assert proc.returncode==0,(proc.returncode,tail)
            result['status']='PASS'
        except BaseException as exc:
            result.update(status='FAIL',error=repr(exc),traceback=traceback.format_exc())
        finally:
            if proc.poll() is None:
                proc.terminate()
                try: proc.wait(5)
                except subprocess.TimeoutExpired: proc.kill(); proc.wait()
            if not (out/'stdout.log').exists(): (out/'stdout.log').write_text(proc.stdout.read())
            result.update(exitCode=proc.returncode,wallSeconds=time.monotonic()-start)
            (out/'report.json').write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result,indent=2)); raise SystemExit(result['status']!='PASS')
if __name__=='__main__': main()
