#!/usr/bin/env python3
"""Read-only independent peer for opt-in demand-driven request streaming."""
import argparse,asyncio,hashlib,json,os,pathlib,ssl,subprocess,time,traceback
from run import response
from tls import certificates

async def check(port, context):
    results=[]
    async def connect():
        return await asyncio.open_connection('127.0.0.1',port,ssl=context,server_hostname='localhost' if context else None)
    async def close(w):
        w.close()
        try:await w.wait_closed()
        except (ConnectionError,ssl.SSLError):pass
    async def request(path,body=b'',method='GET'):
        r,w=await connect()
        try:
            w.write(f'{method} {path} HTTP/1.1\r\nHost: localhost\r\nContent-Length: {len(body)}\r\n\r\n'.encode()+body);await w.drain();return await response(r)
        finally:await close(w)
    async def metrics():return json.loads((await request('/metrics'))[2])
    r,w=await connect();w.write(b'POST /upload HTTP/1.1\r\nHost: localhost\r\nContent-Length: 9\r\n\r\nfirst');await w.drain();pending=asyncio.create_task(response(r))
    for _ in range(100):
        m=await metrics()
        if m['seen']==5 and m['ticks']>=2:break
        await asyncio.sleep(.002)
    assert m['seen']==5 and m['ticks']>=2 and not pending.done(),m
    w.write(b'last');await w.drain();s,h,b=await pending;assert s==200 and json.loads(b)['sha256']==hashlib.sha256(b'firstlast').hexdigest()
    # Fully consumed body permits the same connection's next request.
    w.write(b'GET /metrics HTTP/1.1\r\nHost: localhost\r\n\r\n');await w.drain();assert (await response(r))[0]==200;await close(w)
    results.append('early_chunk_and_two_ticks_before_peer_release_and_keepalive')
    for path in ['/upload','/slow','/owner']:
        payload=(bytes(range(256))*32768) if path!='/owner' else b'owned'
        started=time.monotonic()
        try:
            s,h,b=await request(path,payload,'POST');assert s==200,(path,s,h,b[:256]);value=json.loads(b);assert s==200 and value['bytes']==len(payload) and value['sha256']==hashlib.sha256(payload).hexdigest(),(s,value)
        except Exception:
            print(json.dumps({'failedPath':path,'elapsed':time.monotonic()-started,'metrics':await metrics()}),flush=True)
            raise
        print(json.dumps({'path':path,'elapsed':time.monotonic()-started,'chunks':value['chunks']}),flush=True)
    results.append('eight_mib_through_256k_budget_slow_consumer_and_task_owner')
    assert json.loads((await request('/json',b'{"a":42}','POST'))[2])==[{'a':42},{'a':42}]
    assert (await request('/json',b'{bad','POST'))[0]==400
    results.append('json_explicit_collection_and_invalid_json')
    r,w=await connect();w.write(b'POST /upload HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n3\r\nabc\r\n2\r\nde\r\n0\r\n\r\n');await w.drain();s,h,b=await response(r);assert s==200 and json.loads(b)['bytes']==5;await close(w)
    results.append('chunked_input')
    for path,expected in [('/early',403),('/throw',500)]:
        r,w=await connect();w.write(f'POST {path} HTTP/1.1\r\nHost: localhost\r\nContent-Length: 100\r\n\r\nx'.encode());await w.drain();s,h,b=await response(r);assert s==expected and h.get(b'connection')==b'close',(s,h,b);assert await asyncio.wait_for(r.read(),2)==b'';await close(w)
    results.append('early_response_and_exception_close_incomplete_input')
    r,w=await connect();w.write(b'POST /upload HTTP/1.1\r\nHost: localhost\r\nContent-Length: 8388609\r\n\r\n');await w.drain();assert (await response(r))[0]==413;await close(w)
    # Limit a chunked body whose total is not known from its head.
    r,w=await connect();w.write(b'POST /upload HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n');await w.drain()
    for _ in range(129):
        w.write(b'10000\r\n'+b'x'*65536+b'\r\n');await w.drain()
    s,h,b=await response(r);assert s==413 and h.get(b'connection')==b'close',(s,h,b);await close(w)
    results.append('declared_and_chunked_size_limits')
    r,w=await connect();w.write(b'POST /upload HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\nZ\r\nbad\r\n');await w.drain()
    try:
        s,h,b=await response(r);assert s==400,(s,h,b)
    except asyncio.IncompleteReadError:pass
    assert await asyncio.wait_for(r.read(),2)==b'';await close(w)
    results.append('malformed_input_never_success_and_connection_closed')
    r,w=await connect();w.write(b'POST /upload HTTP/1.1\r\nHost: localhost\r\nContent-Length: 100\r\n\r\nx');await w.drain();await close(w)
    r,w=await connect();w.write(b'POST /upload HTTP/1.1\r\nHost: localhost\r\nContent-Length: 100\r\n\r\nx');await w.drain();assert await asyncio.wait_for(r.read(),4)==b'';await close(w)
    for _ in range(100):
        m=await metrics()
        if m['server']['connections']<=2 and m['server']['bufferBytes']==0:break
        await asyncio.sleep(.002)
    assert m['server']['connections']<=2 and m['server']['bufferBytes']==0 and m['server']['timedOut']>=1,m
    results.append('disconnect_deadline_payload_cleanup')
    assert (await request('/stop'))[2]==b'stop'
    return results,m

def main():
    p=argparse.ArgumentParser();p.add_argument('--php',required=True);p.add_argument('--module',required=True);p.add_argument('--out',required=True);p.add_argument('--backend',choices=['epoll','poll'],default='epoll');p.add_argument('--tls',action='store_true');a=p.parse_args()
    root=pathlib.Path(__file__).resolve().parents[2];out=pathlib.Path(a.out).resolve();out.mkdir(parents=True,exist_ok=False);context=None;certs=None
    if a.tls:certs=certificates(out);context=ssl.create_default_context(cafile=str(certs/'ca.pem'))
    cmd=[a.php,'-n','-d','extension='+str(pathlib.Path(a.module).resolve()),str(root/'tests/server/input_fixture.php')]+([str(certs)] if certs else [])
    start=time.monotonic();result={'command':cmd,'backend':a.backend,'tls':a.tls,'moduleSha256':hashlib.sha256(pathlib.Path(a.module).read_bytes()).hexdigest()}
    with (out/'stderr.log').open('w') as err:
        proc=subprocess.Popen(cmd,stdout=subprocess.PIPE,stderr=err,text=True,env=os.environ|{'ASYNCARA_BACKEND':a.backend});(out/'launch.json').write_text(json.dumps(result|{'pid':proc.pid}))
        try:
            line=proc.stdout.readline();assert line.startswith('READY '),line;port=int(line.strip().rsplit(':',1)[1]);result['checks'],result['metrics']=asyncio.run(check(port,context));tail=proc.communicate(timeout=8)[0];(out/'stdout.log').write_text(tail);assert proc.returncode==0,(proc.returncode,tail);result['finalRuntime']=json.loads(tail.strip().splitlines()[-1])['runtime'];assert all(result['finalRuntime'][k]==0 for k in ['activeTasks','activeTimers','pendingFutures','pendingNative','ioWatchers','readyTasks']),result['finalRuntime'];result['status']='PASS'
        except BaseException as ex:result.update(status='FAIL',error=repr(ex),traceback=traceback.format_exc())
        finally:
            if proc.poll() is None:
                proc.terminate()
                try:proc.wait(8)
                except subprocess.TimeoutExpired:proc.kill();proc.wait()
            result.update(exit=proc.returncode,wallSeconds=time.monotonic()-start);(out/'report.json').write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result,indent=2));raise SystemExit(result['status']!='PASS')
if __name__=='__main__':main()
