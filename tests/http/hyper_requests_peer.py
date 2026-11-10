#!/usr/bin/env python3
"""Independent HTTP/1 request echo, upload barriers and premature response peer."""
import asyncio,hashlib,json,pathlib,socket,ssl,sys,os,time
def trace(**values):
    if os.getenv('ASYNCARA_REQUEST_TEST_TRACE'):print(json.dumps({'time':time.monotonic(),**values}),file=sys.stderr,flush=True)
async def main():
    root=pathlib.Path(sys.argv[1]);tasks=set();peers=set();gates={};sequence=0
    async def serve(reader,writer):
        nonlocal sequence
        sequence+=1;identity=sequence;tasks.add(asyncio.current_task());peers.add(writer)
        try:
            while True:
                raw=await reader.readuntil(b'\r\n\r\n');lines=raw[:-4].split(b'\r\n');method,path,version=lines[0].decode().split(' ')
                trace(connection=identity,phase='head',method=method,path=path)
                assert version=='HTTP/1.1';headers={}
                for line in lines[1:]:
                    k,v=line.split(b':',1);headers.setdefault(k.decode().lower(),[]).append(v.strip().decode('latin1'))
                assert 'transfer-encoding' not in headers
                lengths=headers.get('content-length',['0']);assert len(lengths)==1
                size=int(lengths[0]);assert 0<=size<=16777216
                if path.startswith('/early/'):
                    token=path.rsplit('/',1)[1];result=json.dumps({'early':True,'connection':identity}).encode()
                    writer.write(b'HTTP/1.1 200 OK\r\nContent-Length: '+str(len(result)).encode()+b'\r\n\r\n'+result);await writer.drain()
                    print(json.dumps({'early':token,'bytes':size}),flush=True)
                    await gates.setdefault(token,asyncio.Event()).wait()
                    writer.get_extra_info('socket').setsockopt(socket.SOL_SOCKET,socket.SO_RCVBUF,1048576)
                    body=await reader.readexactly(size);assert body==b'abcd'*(size//4)
                    print(json.dumps({'finished':token}),flush=True);continue
                if path.startswith('/hold/'):
                    token=path.rsplit('/',1)[1];gate=gates.setdefault(token,asyncio.Event())
                    print(json.dumps({'uploadPending':token,'bytes':size}),flush=True);await gate.wait()
                    writer.get_extra_info('socket').setsockopt(socket.SOL_SOCKET,socket.SO_RCVBUF,1048576)
                    trace(connection=identity,phase='released',buffered=len(reader._buffer),rcvbuf=writer.get_extra_info('socket').getsockopt(socket.SOL_SOCKET,socket.SO_RCVBUF))
                if path in ['/reject','/reject-held'] or path.startswith('/reject-held/'):
                    writer.write(b'HTTP/1.1 413 Payload Too Large\r\nContent-Length: 0\r\nConnection: close\r\n\r\n');await writer.drain()
                    if path.startswith('/reject-held'):
                        token='reject-'+path.rsplit('/',1)[1] if path.startswith('/reject-held/') else 'reject-release'
                        await gates.setdefault(token,asyncio.Event()).wait()
                    return
                body=await reader.readexactly(size)
                trace(connection=identity,phase='body',bytes=len(body))
                result=json.dumps({'connection':identity,'method':method,'path':path,'headers':headers,'bytes':len(body),'sha256':hashlib.sha256(body).hexdigest(),'body':body.decode('latin1') if size<=4096 else None}).encode()
                if method=='HEAD':result=b'x'*123
                writer.write(b'HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: '+str(len(result)).encode()+b'\r\n\r\n'+(b'' if method=='HEAD' else result));await writer.drain()
        except (asyncio.IncompleteReadError,ConnectionError,ssl.SSLError):pass
        finally:peers.discard(writer);tasks.discard(asyncio.current_task());writer.close()
    ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(root/'server.pem',root/'server.key');ctx.set_alpn_protocols(['http/1.1'])
    servers=[await asyncio.start_server(serve,'127.0.0.1',0),await asyncio.start_server(serve,'127.0.0.1',0,ssl=ctx,ssl_handshake_timeout=10)]
    for _ in range(2):
        servers += [await asyncio.start_server(serve,'127.0.0.1',0,limit=8192),await asyncio.start_server(serve,'127.0.0.1',0,limit=8192,ssl=ctx,ssl_handshake_timeout=10)]
    # Fixed receive windows before SYN/accept, separate from the ordinary peers.
    # A previously enlarged TCP window must not absorb an entire held upload.
    # Never shrink SO_RCVBUF after application bytes are in flight: that can
    # produce persistent tiny-window/RTO stalls independently of the client.
    for server in servers[2:]:server.sockets[0].setsockopt(socket.SOL_SOCKET,socket.SO_RCVBUF,8192)
    print(json.dumps(dict(zip(['http','https','httpEarly','httpsEarly','httpHold','httpsHold'],[s.sockets[0].getsockname()[1] for s in servers]))),flush=True)
    while True:
        line=await asyncio.to_thread(sys.stdin.readline)
        if not line:break
        gates.setdefault(line.strip(),asyncio.Event()).set()
    for s in servers:s.close()
    for w in list(peers):w.transport.abort()
    for t in list(tasks):t.cancel()
    await asyncio.gather(*tasks,return_exceptions=True)
    for s in servers:await s.wait_closed()
asyncio.run(main())
