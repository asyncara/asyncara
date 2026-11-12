#!/usr/bin/env python3
"""Independent HTTP/1 peers: Expect, HEAD, half-close, truncation and deadlines."""
import argparse, asyncio, hashlib, json, os, pathlib, select, subprocess, time, traceback
from run import response

async def checks(port):
    rows = []
    async def connect(): return await asyncio.open_connection('127.0.0.1', port)
    async def request(path):
        r,w = await connect()
        try:
            w.write(f'GET {path} HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n'.encode()); await w.drain()
            return await response(r)
        finally: w.close(); await w.wait_closed()
    async def metrics(): return json.loads((await request('/metrics'))[2])
    # A real peer withholds the body until the server's interim response.
    r,w = await connect(); payload=b'{"value":42}'
    w.write(f'POST /echo HTTP/1.1\r\nHost: localhost\r\nContent-Length: {len(payload)}\r\nExpect: 100-continue\r\n\r\n'.encode()); await w.drain()
    interim=await asyncio.wait_for(r.readuntil(b'\r\n\r\n'),2); assert interim.startswith(b'HTTP/1.1 100 '),interim
    assert (await request('/status'))[2]==b'ok'
    w.write(payload); await w.drain(); assert (await response(r))[2]==payload
    # HEAD must not consume the next pipelined response's bytes.
    w.write(b'HEAD /status HTTP/1.1\r\nHost: localhost\r\n\r\nGET /status HTTP/1.1\r\nHost: localhost\r\n\r\n'); await w.drain()
    head=await response(r,head=True); assert head[0]==200 and head[1][b'content-length']==b'2' and head[2]==b''
    assert (await response(r))[2]==b'ok'; w.close(); await w.wait_closed(); rows.append('expect-and-head-pipeline')
    r,w = await connect(); w.write(b'POST /echo HTTP/1.1\r\nHost: localhost\r\nContent-Length: 1025\r\nExpect: 100-continue\r\n\r\n'); await w.drain()
    assert (await response(r))[0]==413; w.close(); await w.wait_closed(); rows.append('oversized-expect-rejected-before-body')
    r,w = await connect(); w.write(b'GET /hold HTTP/1.1\r\nHost: localhost\r\n\r\n'); await w.drain(); w.write_eof()
    held=asyncio.create_task(response(r))
    try:
        for _ in range(200):
            m=await metrics()
            if m['ticks']>=2: break
            await asyncio.sleep(.001)
        assert m['ticks']==2 and m['pending']==1 and not held.done(),m
        assert (await request('/release'))[0]==200
        assert (await held)[2]==b'held'
        assert await asyncio.wait_for(r.read(),2)==b''
    finally:
        w.close(); await w.wait_closed()
        if not held.done(): held.cancel()
        await asyncio.gather(held,return_exceptions=True)
    rows.append('two-ticks-with-write-half-closed-before-release')
    r,w = await connect(); w.write(b'GET /status HTTP/1.1\r\nHost: localhost\r\n\r\n'*2); await w.drain(); w.write_eof()
    assert (await response(r))[2]==b'ok'; assert (await response(r))[2]==b'ok'
    assert await asyncio.wait_for(r.read(),2)==b''; w.close(); await w.wait_closed(); rows.append('half-closed-pipeline')
    r,w = await connect(); w.write(f'POST /echo HTTP/1.1\r\nHost: localhost\r\nContent-Length: {len(payload)}\r\n\r\n'.encode()+payload); await w.drain(); w.write_eof()
    assert (await response(r))[2]==payload; w.close(); await w.wait_closed(); rows.append('complete-body-before-fin')
    before=(await metrics())['echoes']
    r,w = await connect(); w.write(b'POST /echo HTTP/1.1\r\nHost: localhost\r\nContent-Length: 12\r\n\r\n{'); await w.drain(); w.write_eof()
    raw=await asyncio.wait_for(r.read(),2); assert not raw or raw.startswith(b'HTTP/1.1 400 '),raw
    w.close(); await w.wait_closed(); assert (await metrics())['echoes']==before; rows.append('truncated-body-never-successful')
    r,w = await connect(); w.write(b'GET /never HTTP/1.1\r\nHost: localhost\r\n\r\n'); await w.drain(); w.write_eof()
    assert await asyncio.wait_for(r.read(),2)==b''; w.close(); await w.wait_closed()
    final=await metrics(); assert final['pending']==0 and final['cancelled']==1 and final['native']['timedOut']>=1,final
    assert final['native']['bufferBytes']==0 and final['native']['connections']<=2,final
    assert (await request('/stop'))[2]==b'stopped'; rows.append('half-close-does-not-disable-deadline')
    return {'checks':rows,'metrics':final}

def main():
    p=argparse.ArgumentParser()
    for arg in ['php','module','out']: p.add_argument('--'+arg,required=True)
    p.add_argument('--backend',choices=['epoll','poll'],default='epoll');p.add_argument('--stream',action='store_true');p.add_argument('--baseline',action='store_true')
    a=p.parse_args();root=pathlib.Path(__file__).resolve().parents[2];out=pathlib.Path(a.out).resolve();out.mkdir(parents=True,exist_ok=False)
    sha=lambda f:hashlib.sha256(pathlib.Path(f).read_bytes()).hexdigest()
    command=[a.php,'-n','-d','extension='+str(pathlib.Path(a.module).resolve()),str(root/'tests/server/protocol_fixture.php'),'0'if a.baseline else'1','1'if a.stream else'0']
    result={'command':command,'backend':a.backend,'streamRequests':a.stream,'moduleSha256':sha(a.module),'fixtureSha256':sha(root/'tests/server/protocol_fixture.php')};start=time.monotonic()
    with(out/'stderr.log').open('x')as err:
        child=subprocess.Popen(command,env=os.environ|{'ASYNCARA_BACKEND':a.backend},stdout=subprocess.PIPE,stderr=err,text=True)
        (out/'launch.json').write_text(json.dumps(result|{'pid':child.pid},indent=2))
        try:
            assert select.select([child.stdout],[],[],10)[0];line=child.stdout.readline();(out/'ready.txt').write_text(line);assert line.startswith('READY '),line
            result.update(asyncio.run(checks(int(line.strip().rsplit(':',1)[1]))))
            tail=child.communicate(timeout=5)[0];(out/'stdout.log').write_text(tail);assert child.returncode==0,tail
            final=json.loads(tail.splitlines()[-1]);assert final['done']
            for key in ['activeTasks','activeTimers','ioWatchers','pendingNative']:assert final['runtime'][key]==0,final
            result.update(status='PASS',final=final)
        except BaseException as error:result.update(status='FAIL',error=repr(error),traceback=traceback.format_exc())
        finally:
            if child.poll()is None:
                child.terminate()
                try:child.wait(timeout=5)
                except subprocess.TimeoutExpired:child.kill();child.wait()
            if not(out/'stdout.log').exists():(out/'stdout.log').write_text(child.stdout.read())
            result.update(exit=child.returncode,seconds=time.monotonic()-start)
    result['artifacts']={f.name:sha(f)for f in out.iterdir()if f.is_file()};(out/'report.json').write_text(json.dumps(result,indent=2));print(json.dumps(result,indent=2));return result['status']!='PASS'
if __name__=='__main__':raise SystemExit(main())
