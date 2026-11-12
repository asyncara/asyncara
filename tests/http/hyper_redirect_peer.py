#!/usr/bin/env python3
import asyncio,hashlib,json,pathlib,ssl,sys,urllib.parse
async def main():
    c=pathlib.Path(sys.argv[1]);ports={};peers=set();tasks=set();release=asyncio.Event();stats={'requests':0,'unfinishedRedirectClosed':0}
    async def serve(reader,writer):
        peers.add(writer);tasks.add(asyncio.current_task())
        try:
            while True:
                raw=await reader.readuntil(b'\r\n\r\n');method,target,_=raw.split(b'\r\n',1)[0].decode().split(' ')
                headers=dict(x.split(b':',1)for x in raw.lower().split(b'\r\n')[1:]if b':'in x)
                data=await reader.readexactly(int(headers.get(b'content-length',b'0')))
                uri=urllib.parse.urlsplit(target);q=urllib.parse.parse_qs(uri.query,keep_blank_values=True);path=uri.path;stats['requests']+=1
                code=200;extra=[];body=json.dumps({'method':method,'target':target,'size':len(data),'sha256':hashlib.sha256(data).hexdigest(),'authorization':b'authorization'in headers,'cookie':b'cookie'in headers,'contentType':b'content-type'in headers}).encode()
                if path.startswith('/r/'):
                    code=int(path.rsplit('/',1)[1]);extra=[('Location',q.get('to',['/echo'])[0])];body=b''
                elif path=='/loop':code=302;extra=[('Location','/loop')];body=b''
                elif path=='/ambiguous':code=302;extra=[('Location','/echo'),('Location','/wrong')];body=b''
                elif path=='/large-redirect':
                    writer.write(b'HTTP/1.1 302 Found\r\nLocation: /large\r\nContent-Length: 999999999\r\n\r\nx');await writer.drain()
                    assert await reader.read()==b'';stats['unfinishedRedirectClosed']+=1;return
                elif path.startswith('/timed/'):
                    left=int(path.rsplit('/',1)[1]);await asyncio.sleep(.08)
                    if left:code=302;extra=[('Location','/timed/'+str(left-1))];body=b''
                elif path=='/large':body=bytes(range(256))*8192
                elif path=='/state':body=json.dumps(stats).encode()
                elif path=='/hold':
                    print(json.dumps({'pending':target}),flush=True);await release.wait()
                writer.write(f'HTTP/1.1 {code} Reply\r\nContent-Length: {len(body)}\r\n'.encode()+b''.join((k+': '+v+'\r\n').encode()for k,v in extra)+b'\r\n'+(b''if method=='HEAD'else body));await writer.drain()
        except (ConnectionError,ssl.SSLError,asyncio.IncompleteReadError):pass
        finally:peers.discard(writer);tasks.discard(asyncio.current_task());writer.close()
    servers=[]
    for name in ['http','other','https']:
        ctx=None
        if name=='https':
            ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(c/'server.pem',c/'server.key');ctx.set_alpn_protocols(['http/1.1'])
        s=await asyncio.start_server(serve,'127.0.0.1',0,ssl=ctx);servers.append(s);ports[name]=s.sockets[0].getsockname()[1]
    print(json.dumps(ports),flush=True)
    while line:=await asyncio.to_thread(sys.stdin.readline):
        if line.strip()=='release':release.set()
    for s in servers:s.close()
    for p in list(peers):p.transport.abort()
    for t in list(tasks):t.cancel()
    await asyncio.gather(*tasks,return_exceptions=True)
    for s in servers:await s.wait_closed()
asyncio.run(main())
