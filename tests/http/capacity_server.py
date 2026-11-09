#!/usr/bin/env python3
"""Four real response bodies held one byte short until an explicit control.
No timing sleeps release data; the caller must observe native buffer ownership.
"""
import asyncio,json,os
async def main():
    release=asyncio.Event();ready=asyncio.Event();stopped=asyncio.Event();prefixes=0
    loop=asyncio.get_running_loop()
    def control():
        data=os.read(0,1024)
        if data:release.set()
        else:loop.remove_reader(0);stopped.set()
    loop.add_reader(0,control)
    async def client(reader,writer):
        nonlocal prefixes
        try:
            await reader.readuntil(b'\r\n\r\n')
            writer.write(b'HTTP/1.1 200 OK\r\nContent-Length: 524288\r\nConnection: close\r\n\r\n'+b'x'*524287)
            await writer.drain();prefixes+=1
            if prefixes==4:ready.set()
            await ready.wait();await release.wait()
            writer.write(b'x');await writer.drain()
        except (ConnectionError,asyncio.IncompleteReadError):pass
        finally:
            writer.close()
            try:await writer.wait_closed()
            except ConnectionError:pass
    server=await asyncio.start_server(client,'127.0.0.1',0)
    print(json.dumps({'port':server.sockets[0].getsockname()[1]}),flush=True)
    async with server:await stopped.wait()
asyncio.run(main())
