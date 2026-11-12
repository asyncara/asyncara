#!/usr/bin/env python3
"""Known oversized head with no body; real transport, no timed release."""
import asyncio, json, sys
LIMIT=16*1024*1024
stats={'announced':0,'closed':0,'bodyBytesOnAnnounced':0,'requests':0}
async def client(reader,writer):
    announced=False
    try:
        head=await reader.readuntil(b'\r\n\r\n')
        method,path,_=head.split(b'\r\n',1)[0].split(b' ');stats['requests']+=1
        if path==b'/state':
            data=json.dumps(stats).encode();writer.write(b'HTTP/1.1 200 OK\r\nContent-Length: '+str(len(data)).encode()+b'\r\nConnection: close\r\n\r\n'+data)
        elif path in [b'/announced',b'/head',b'/not-modified']:
            status=b'304 Not Modified'if path==b'/not-modified'else b'200 OK'
            writer.write(b'HTTP/1.1 '+status+b'\r\nContent-Length: '+str(LIMIT+1).encode()+b'\r\nConnection: close\r\n\r\n');await writer.drain()
            if path==b'/announced':
                announced=True;stats['announced']+=1;await reader.read()
        elif path in [b'/exact',b'/stream',b'/unknown']:
            size=LIMIT if path==b'/exact'else LIMIT+1
            writer.write(b'HTTP/1.1 200 OK\r\n'+(b'Content-Length: '+str(size).encode()+b'\r\n'if path!=b'/unknown'else b'')+b'Connection: close\r\n\r\n')
            while size:
                n=min(size,65536);writer.write(b'x'*n);await writer.drain();size-=n
        else:raise AssertionError(path)
        await writer.drain()
    except (ConnectionError,asyncio.IncompleteReadError):pass
    finally:
        if announced:stats['closed']+=1
        writer.close()
        try:await writer.wait_closed()
        except ConnectionError:pass
async def main():
    server=await asyncio.start_server(client,'127.0.0.1',0)
    print(json.dumps({'url':'http://127.0.0.1:'+str(server.sockets[0].getsockname()[1])}),flush=True)
    await asyncio.get_running_loop().run_in_executor(None,sys.stdin.read)
    server.close();await server.wait_closed();print(json.dumps(stats),flush=True)
asyncio.run(main())
