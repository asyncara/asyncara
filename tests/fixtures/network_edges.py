#!/usr/bin/env python3
"""Deterministic IPv4/IPv6 TLS peer; certificates remain local test material."""
import asyncio,hashlib,json,ssl,sys
sni={}
async def peer(reader,writer):
    try:
        command=(await asyncio.wait_for(reader.readline(),10)).decode().strip()
        if command=='info':
            tls=writer.get_extra_info('ssl_object')
            writer.write(json.dumps({'version':tls.version(),'alpn':tls.selected_alpn_protocol(),'sni':sni.pop(id(tls),None)}).encode()+b'\n');await writer.drain()
        elif command.startswith('upload '):
            length=int(command.split()[1]);digest=hashlib.sha256()
            while length:
                data=await asyncio.wait_for(reader.read(min(4093,length)),10)
                if not data:raise ConnectionError('short upload')
                length-=len(data);digest.update(data);await asyncio.sleep(.0001)
            writer.write(digest.hexdigest().encode()+b'\n');await writer.drain()
        elif command=='download':
            for _ in range(256):writer.write(b'z'*4093);await writer.drain();await asyncio.sleep(.0001)
        elif command=='disconnect':writer.write(b'prefix');await writer.drain();writer.transport.abort();return
        elif command=='idle':await asyncio.sleep(.3)
    except (ConnectionError,asyncio.TimeoutError,ssl.SSLError):pass
    finally:
        tls=writer.get_extra_info('ssl_object');sni.pop(id(tls),None)
        writer.close()
        try:await writer.wait_closed()
        except (ConnectionError,ssl.SSLError):pass
async def stalled(reader,writer):
    try:await reader.read(65536);await asyncio.sleep(.3)
    finally:writer.close();await writer.wait_closed()
def context(version):
    c=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);c.load_cert_chain(sys.argv[1],sys.argv[2]);c.minimum_version=c.maximum_version=version
    c.set_alpn_protocols(['http/1.1']);c.set_servername_callback(lambda sock,name,ctx:sni.__setitem__(id(sock),name));return c
async def main():
    servers={}
    for name,host,c in [('v4','127.0.0.1',None),('v6','::1',None),('tls12','127.0.0.1',context(ssl.TLSVersion.TLSv1_2)),('tls13','::1',context(ssl.TLSVersion.TLSv1_3))]:servers[name]=await asyncio.start_server(peer,host,0,ssl=c)
    servers['stall']=await asyncio.start_server(stalled,'127.0.0.1',0)
    print(json.dumps({k:v.sockets[0].getsockname()[1]for k,v in servers.items()}),flush=True)
    await asyncio.to_thread(sys.stdin.read)
    for s in servers.values():s.close()
    for s in servers.values():await s.wait_closed()
asyncio.run(main())
