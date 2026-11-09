#!/usr/bin/env python3
"""Local origin/connection identity and peer-controlled pending-body fixture."""
import asyncio, json, sys, socket

async def main():
    release = asyncio.Event(); peers = set(); tasks = set(); sequence = 0
    async def serve(reader, writer):
        nonlocal sequence
        sequence += 1; connection = sequence
        peers.add(writer); tasks.add(asyncio.current_task())
        try:
            while True:
                request = await reader.readuntil(b'\r\n\r\n')
                lines = request.decode('ascii').split('\r\n')
                host = next(v.split(':', 1)[1].strip() for v in lines if v.lower().startswith('host:'))
                path = lines[0].split()[1]
                body = json.dumps({'host': host, 'connection': connection}).encode()
                writer.write(b'HTTP/1.1 200 OK\r\nContent-Length: '+str(len(body)).encode()+b'\r\n\r\n'+body[:-1])
                await writer.drain()
                if path == '/gated':
                    print(json.dumps({'pending': host}), flush=True)
                    await release.wait()
                writer.write(body[-1:]); await writer.drain()
        except (asyncio.IncompleteReadError, ConnectionError):
            pass
        finally:
            peers.discard(writer); tasks.discard(asyncio.current_task())
            writer.close()
    server = await asyncio.start_server(serve, '127.0.0.1', 0, limit=8192)
    # Reserve the IPv6 port without listening: fallback always sees refusal,
    # never an unrelated process that happens to use the same port on ::1.
    refused = socket.socket(socket.AF_INET6, socket.SOCK_STREAM)
    refused.setsockopt(socket.IPPROTO_IPV6, socket.IPV6_V6ONLY, 1)
    refused.bind(('::1', server.sockets[0].getsockname()[1]))
    dns_permits = asyncio.Queue()
    async def dns_gate(reader, writer):
        peers.add(writer); tasks.add(asyncio.current_task())
        try:
            print(json.dumps({'dns': 'waiting'}), flush=True)
            await dns_permits.get()
            writer.write(b'R'); await writer.drain()
        finally:
            peers.discard(writer); tasks.discard(asyncio.current_task()); writer.close()
    dns = await asyncio.start_server(dns_gate, '127.0.0.1', 0)
    print(json.dumps({'port': server.sockets[0].getsockname()[1], 'dnsPort': dns.sockets[0].getsockname()[1]}), flush=True)
    while True:
        command = await asyncio.to_thread(sys.stdin.readline)
        if not command: break
        if command.strip() == 'release': release.set()
        elif command.strip() == 'dns-release': dns_permits.put_nowait(True)
    server.close(); dns.close(); refused.close()
    for peer in list(peers): peer.close()
    for task in list(tasks): task.cancel()
    await asyncio.gather(*tasks, return_exceptions=True)
    await server.wait_closed(); await dns.wait_closed()
asyncio.run(main())
