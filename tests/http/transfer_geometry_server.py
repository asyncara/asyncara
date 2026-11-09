#!/usr/bin/env python3
"""Raw HTTP framing boundaries; stdin EOF owns fixture shutdown."""
import asyncio
import json
import os
import sys


async def main():
    stopped = asyncio.Event()
    clients = set()
    released = asyncio.Event()
    gated = 0
    ready_count = int(sys.argv[1]) if len(sys.argv) > 1 else 0
    assert 0 <= ready_count <= 256
    loop = asyncio.get_running_loop()

    def control():
        if not os.read(0, 1024):
            loop.remove_reader(0)
            stopped.set()
        else:
            released.set()

    loop.add_reader(0, control)

    async def client(reader, writer):
        nonlocal gated
        clients.add(asyncio.current_task())
        try:
            while True:
                request = await reader.readuntil(b'\r\n\r\n')
                path = request.split(b' ', 2)[1].decode('ascii')
                kind, number = path.strip('/').split('/')
                size = int(number)
                if not 0 <= size <= 262144:
                    raise ValueError('Fixture bound')
                body = (bytes(range(251)) * (size // 251 + 1))[:size]
                if kind == 'head':
                    writer.write(b'HTTP/1.1 200 OK\r\nX-Pad: ' + b'p' * size +
                                 b'\r\nContent-Length: 1\r\n\r\nx')
                elif kind == 'chunked':
                    writer.write(b'HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n')
                    for start in range(0, size, 16381):
                        part = body[start:start + 16381]
                        writer.write(f'{len(part):x}\r\n'.encode() + part + b'\r\n')
                    writer.write(b'0\r\nX-End: yes\r\n\r\n')
                else:
                    writer.write(f'HTTP/1.1 200 OK\r\nContent-Length: {size}\r\n\r\n'.encode())
                    writer.write(body[:-1] if kind in ('truncated', 'held', 'gated') else body)
                await writer.drain()
                if kind == 'gated':
                    gated += 1
                    if gated == ready_count:
                        print(json.dumps({'prefixes': gated}), flush=True)
                    await released.wait()
                    writer.write(body[-1:])
                    await writer.drain()
                if kind == 'held':
                    await reader.read()  # Only owner cancellation releases this peer.
                    break
                if kind in ('truncated', 'head'):
                    break
        except (ConnectionError, asyncio.IncompleteReadError):
            pass
        finally:
            writer.close()
            try:
                await writer.wait_closed()
            except ConnectionError:
                pass
            clients.discard(asyncio.current_task())

    server = await asyncio.start_server(client, '127.0.0.1', 0)
    print(json.dumps({'port': server.sockets[0].getsockname()[1]}), flush=True)
    await stopped.wait()
    server.close()
    for task in list(clients):
        task.cancel()
    await asyncio.gather(*list(clients), return_exceptions=True)
    await server.wait_closed()


asyncio.run(main())
