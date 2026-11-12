#!/usr/bin/env python3
"""Bounded loopback CONNECT oracle; deterministic held headers, malformed peers."""
import asyncio,json,sys
port=int(sys.argv[1]);modes=['held','informational','framing','denied','upgrade','malformed','headers','oversize','short','prefix','storm','stall']
counts={m:0 for m in modes};active=set();tasks=set();release=asyncio.Event();held=0
async def tunnel(reader,writer):
    try:
        while data:=await reader.read(16384):writer.write(data);await writer.drain()
    except (OSError,ConnectionError):pass
async def proxy(reader,writer,mode):
    global held
    active.add(writer);tasks.add(asyncio.current_task());remote=None
    try:
        head=await asyncio.wait_for(reader.readuntil(b'\r\n\r\n'),3)
        if len(head)>16384 or head.split(b'\r\n',1)[0]!=f'CONNECT localhost:{port} HTTP/1.1'.encode():raise RuntimeError('Invalid CONNECT target')
        counts[mode]+=1
        if mode in ['held','stall']:
            held+=1
            try:
                if mode=='stall':await reader.read();return
                await release.wait()
            finally:held-=1
        response=b'HTTP/1.1 200 Connection established\r\n\r\n'
        bad={
            'denied':b'HTTP/1.1 407 Denied\r\nContent-Length: 0\r\n\r\n',
            'upgrade':b'HTTP/1.1 101 Switch\r\n\r\n',
            'malformed':b'HTTP/1.1 not-a-status\r\n\r\n',
            'headers':b'HTTP/1.1 200 OK\r\n'+b'x-a: b\r\n'*257+b'\r\n',
            'oversize':b'HTTP/1.1 200 OK\r\nx-long: '+b'x'*65536+b'\r\n\r\n',
            'short':b'HTTP/1.1 200 OK\r\nx-incomplete:',
            'prefix':response+b'not a TLS record',
            'storm':b'HTTP/1.1 100 Continue\r\n\r\n'*9+response,
        }
        if mode in bad:writer.write(bad[mode]);await writer.drain();return
        if mode=='informational':response=b'HTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 103 Early Hints\r\n\r\n'+response
        if mode=='framing':response=b'HTTP/1.1 200 OK\r\nContent-Length: 999\r\nTransfer-Encoding: chunked\r\n\r\n'
        # Partial writes are a peer property, not sleeps used to claim progress.
        for offset in range(0,len(response),7):writer.write(response[offset:offset+7]);await writer.drain();await asyncio.sleep(0)
        remote_reader,remote=await asyncio.open_connection('127.0.0.1',port)
        forward=[asyncio.create_task(tunnel(reader,remote)),asyncio.create_task(tunnel(remote_reader,writer))]
        try:
            await asyncio.wait(forward,return_when=asyncio.FIRST_COMPLETED)
        finally:
            for task in forward:task.cancel()
            await asyncio.gather(*forward,return_exceptions=True)
    except (OSError,ConnectionError,asyncio.IncompleteReadError,asyncio.LimitOverrunError):pass
    finally:
        if remote:remote.close()
        writer.close();active.discard(writer);tasks.discard(asyncio.current_task())
async def control(reader,writer):
    try:
        request=await reader.readuntil(b'\r\n\r\n');path=request.split(b' ')[1]
        if path==b'/release':release.set()
        if path==b'/reset':release.clear()
        data=json.dumps({'held':held,'counts':counts,'active':len(active)}).encode()
        writer.write(b'HTTP/1.1 200 OK\r\nConnection: close\r\nContent-Length: '+str(len(data)).encode()+b'\r\n\r\n'+data);await writer.drain()
    finally:writer.close()
async def main():
    servers=[];config={}
    for mode in modes:
        server=await asyncio.start_server(lambda r,w,m=mode:proxy(r,w,m),'127.0.0.1',0,limit=16384);servers.append(server);config[mode]='http://127.0.0.1:'+str(server.sockets[0].getsockname()[1])
    server=await asyncio.start_server(control,'127.0.0.1',0);servers.append(server);config['control']='http://127.0.0.1:'+str(server.sockets[0].getsockname()[1]);print(json.dumps(config),flush=True)
    await asyncio.to_thread(sys.stdin.read)
    for server in servers:server.close();await server.wait_closed()
    for writer in list(active):writer.close()
    for task in list(tasks):task.cancel()
    await asyncio.gather(*tasks,return_exceptions=True);print(json.dumps({'counts':counts,'held':held,'active':len(active)}),flush=True)
asyncio.run(main())
