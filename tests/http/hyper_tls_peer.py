#!/usr/bin/env python3
"""Independent TLS1.2/1.3 origins, held bodies and incomplete handshakes."""
import asyncio,json,ssl,sys,pathlib
async def main():
    certs=pathlib.Path(sys.argv[1]);release=asyncio.Event();tasks=set();peers=set();sequence=0;sni={}
    async def serve(reader,writer):
        nonlocal sequence
        sequence+=1;connection=sequence;tasks.add(asyncio.current_task());peers.add(writer)
        try:
            while True:
                head=await reader.readuntil(b'\r\n\r\n');path=head.split(b' ')[1];tls=writer.get_extra_info('ssl_object')
                host=next(x.split(b':',1)[1].strip().decode() for x in head.split(b'\r\n') if x.lower().startswith(b'host:'))
                body=json.dumps({'connection':connection,'host':host,'sni':sni.get(id(tls)),'version':tls.version(),'alpn':tls.selected_alpn_protocol()}).encode() if path!=b'/large' else bytes(range(256))*4096
                writer.write(b'HTTP/1.1 200 OK\r\nContent-Length: '+str(len(body)).encode()+b'\r\n\r\n'+body[:-1]);await writer.drain()
                if path==b'/hold':
                    print(json.dumps({'pending':connection}),flush=True);await release.wait()
                writer.write(body[-1:]);await writer.drain()
        except (asyncio.IncompleteReadError,ConnectionError,ssl.SSLError):pass
        finally:
            peers.discard(writer);tasks.discard(asyncio.current_task());writer.close()
    servers=[];ports={}
    for label,version,identity in [('tls12',ssl.TLSVersion.TLSv1_2,'server'),('tls13',ssl.TLSVersion.TLSv1_3,'server'),('dns',ssl.TLSVersion.TLSv1_3,'dns'),('expired',ssl.TLSVersion.TLSv1_3,'expired'),('usage',ssl.TLSVersion.TLSv1_3,'client'),('mtls',ssl.TLSVersion.TLSv1_3,'server')]:
        ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.minimum_version=ctx.maximum_version=version
        ctx.load_cert_chain(certs/(identity+'.pem'),certs/(('server' if identity=='expired' else identity)+'.key'));ctx.set_alpn_protocols(['http/1.1'])
        if label=='mtls':ctx.verify_mode=ssl.CERT_REQUIRED;ctx.load_verify_locations(cafile=str(certs/'ca.pem'))
        ctx.set_servername_callback(lambda sock,name,_ctx:sni.__setitem__(id(sock),name))
        server=await asyncio.start_server(serve,'127.0.0.1',0,ssl=ctx,ssl_handshake_timeout=3);servers.append(server);ports[label]=server.sockets[0].getsockname()[1]
    async def stall(reader,writer):
        tasks.add(asyncio.current_task());peers.add(writer)
        try:
            assert await reader.read(65536);print(json.dumps({'handshake':'pending'}),flush=True)
            await reader.read()
        finally:peers.discard(writer);tasks.discard(asyncio.current_task());writer.close()
    server=await asyncio.start_server(stall,'127.0.0.1',0);servers.append(server);ports['stall']=server.sockets[0].getsockname()[1]
    print(json.dumps(ports),flush=True)
    while True:
        line=await asyncio.to_thread(sys.stdin.readline)
        if not line:break
        if line.strip()=='release':release.set()
    for server in servers:server.close()
    # Controller EOF ends the fixture. Idle client pools need not initiate a
    # TLS close handshake before PHP reaches its own request shutdown.
    for peer in list(peers):peer.transport.abort()
    for task in list(tasks):task.cancel()
    await asyncio.gather(*tasks,return_exceptions=True)
    for server in servers:await server.wait_closed()
asyncio.run(main())
