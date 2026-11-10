#!/usr/bin/env python3
"""Chunk framing/early delivery, real slow reader, producer cancellation; no PHP oracle."""
import argparse, asyncio, hashlib, json, os, pathlib, socket, ssl, subprocess, time, traceback
from tls import certificates

async def head(r):
    raw=await asyncio.wait_for(r.readuntil(b'\r\n\r\n'),5)
    lines=raw.split(b'\r\n');return int(lines[0].split()[1]),{k.lower():v.strip() for k,v in (l.split(b':',1) for l in lines[1:] if b':' in l)}
async def chunk(r):
    size=int((await asyncio.wait_for(r.readline(),5)).strip(),16)
    data=await asyncio.wait_for(r.readexactly(size+2),5);assert data[-2:]==b'\r\n';return data[:-2]
async def body(r,h):
    if h.get(b'transfer-encoding')==b'chunked':
        parts=[]
        while True:
            part=await chunk(r)
            if not part:return b''.join(parts)
            parts.append(part)
    return await asyncio.wait_for(r.readexactly(int(h.get(b'content-length',b'0'))),5)
async def checks(port,certs):
    completed=[]
    ctx=None
    if certs:
        ctx=ssl.create_default_context(cafile=str(certs/'ca.pem'));ctx.set_alpn_protocols(['http/1.1'])
    async def connect():return await asyncio.open_connection('127.0.0.1',port,ssl=ctx,**({'server_hostname':'localhost','ssl_shutdown_timeout':3} if ctx else {}))
    async def close(w):
        w.close()
        try:await w.wait_closed()
        except (ssl.SSLError,ConnectionError):pass
    async def send(w,path,method='GET'):
        w.write(f'{method} {path} HTTP/1.1\r\nHost: localhost\r\n\r\n'.encode());await w.drain()
    async def request(path):
        r,w=await connect()
        try:await send(w,path);s,h=await head(r);return s,await body(r,h)
        finally:await close(w)
    r,w=await connect();await send(w,'/stream');s,h=await head(r)
    assert s==200 and h.get(b'transfer-encoding')==b'chunked' and b'content-length' not in h,h
    assert await chunk(r)==b'first'
    held=asyncio.create_task(chunk(r));metrics=json.loads((await request('/metrics'))[1])
    assert metrics['ticks']>=2 and not held.done(),metrics
    assert (await request('/release'))[0]==200 and await held==b'last' and await chunk(r)==b''
    await send(w,'/status');s,h=await head(r);assert s==200 and await body(r,h)==b'ok';await close(w)
    completed.append('early_chunk_two_ticks_before_release_keepalive')
    assert (await request('/empty'))==(200,b'')
    file=pathlib.Path(__file__).with_name('stream_fixture.php').read_bytes()
    assert (await request('/file'))==(200,file);completed.append('worker_file_stream')
    r,w=await connect();await send(w,'/head','HEAD');s,h=await head(r);assert s==200
    assert b'content-length' not in h and b'transfer-encoding' not in h,h
    await send(w,'/status');s,h=await head(r);assert s==200 and await body(r,h)==b'ok';await close(w)
    completed.append('head_does_not_evaluate_producer')
    r,w=await connect();await send(w,'/head-eager','HEAD');s,h=await head(r);assert s==200;await close(w)
    metrics=json.loads((await request('/metrics'))[1]);assert metrics['eagerCompleted'] is True,metrics
    completed.append('head_closes_and_joins_eager_owned_readable_stream')
    assert (await request('/invalid-eager'))==(500,b'')
    metrics=json.loads((await request('/metrics'))[1]);assert metrics['eagerCompleted'] is True,metrics
    completed.append('rejected_headers_close_and_join_eager_owned_readable_stream')
    assert (await request('/complete'))==(200,b'x'*16777216)
    completed.append('complete_16mib_through_256kib_budget')
    for path in ['/failure','/bad-type','/large-chunk','/limit']:
        r,w=await connect();await send(w,path)
        try:
            s,h=await head(r);await body(r,h)
        except (ValueError,asyncio.IncompleteReadError,ConnectionError,ssl.SSLError):pass
        else:raise AssertionError('Failed producer completed a valid response: '+path)
        await close(w)
    completed.append('post_header_failure_aborts_without_second_response')
    r,w=await connect();w.get_extra_info('socket').setsockopt(socket.SOL_SOCKET,socket.SO_RCVBUF,4096)
    await send(w,'/flood');s,h=await head(r);assert s==200
    w.transport.pause_reading()
    for _ in range(30):
        metrics=json.loads((await request('/metrics'))[1])
        if metrics['produced']>8:break
    first=metrics
    for _ in range(10):metrics=json.loads((await request('/metrics'))[1])
    assert 0<metrics['produced']<256 and metrics['closed']==0,metrics
    assert metrics['native']['bufferBytes']<=262144 and metrics['ticks']>=first['ticks']+20,metrics
    w.transport.abort() # owned stalled peer only
    deadline=time.monotonic()+5
    while time.monotonic()<deadline:
        metrics=json.loads((await request('/metrics'))[1])
        if metrics['closed']==1 and metrics['native']['bufferBytes']==0:break
        await asyncio.sleep(.01)
    assert metrics['closed']==1 and metrics['native']['bufferBytes']==0,metrics
    assert metrics['native']['timedOut']==0,metrics # explicit abort/disconnect is not a deadline
    completed.append('bounded_backpressure_disconnect_generator_cleanup')
    assert (await request('/stop'))==(200,b'stopped')
    return {'checks':completed,'metrics':metrics,'slowReader':first}

def main():
    p=argparse.ArgumentParser();p.add_argument('--php',required=True);p.add_argument('--module',required=True);p.add_argument('--out',required=True);p.add_argument('--backend',choices=['epoll','poll'],default='epoll');p.add_argument('--tls',action='store_true');a=p.parse_args()
    root=pathlib.Path(__file__).resolve().parents[2];out=pathlib.Path(a.out).resolve();out.mkdir(parents=True,exist_ok=False)
    certs=certificates(out) if a.tls else None
    cmd=[a.php,'-n','-d','extension='+str(pathlib.Path(a.module).resolve()),str(root/'tests/server/stream_fixture.php')]+([str(certs)] if certs else [])
    result={'command':cmd,'backend':a.backend,'tls':a.tls,'moduleSha256':hashlib.sha256(pathlib.Path(a.module).read_bytes()).hexdigest()};start=time.monotonic()
    with (out/'stderr.log').open('w') as err:
        proc=subprocess.Popen(cmd,stdout=subprocess.PIPE,stderr=err,text=True,env=os.environ|{'ASYNCARA_BACKEND':a.backend})
        (out/'launch.json').write_text(json.dumps(result|{'pid':proc.pid}))
        try:
            line=proc.stdout.readline();assert line.startswith('READY '),line
            result.update(asyncio.run(checks(int(line.strip().rsplit(':',1)[1]),certs)))
            tail=proc.communicate(timeout=6)[0];(out/'stdout.log').write_text(tail);assert proc.returncode==0
            result['status']='PASS'
        except BaseException as e:result.update(status='FAIL',error=repr(e),traceback=traceback.format_exc())
        finally:
            if proc.poll() is None:
                proc.terminate()
                try:tail=proc.communicate(timeout=8)[0]
                except subprocess.TimeoutExpired:proc.kill();tail=proc.communicate()[0]
                (out/'stdout.log').write_text(tail)
            result.update(exit=proc.returncode,wallSeconds=time.monotonic()-start);(out/'report.json').write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result,indent=2));raise SystemExit(result['status']!='PASS')
if __name__=='__main__':main()
