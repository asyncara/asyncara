#!/usr/bin/env python3
"""Independent RFC1928/1929 loopback oracle, controlled pending handshakes.

Only fixture-owned ports are reachable. Credentials and payloads are synthetic;
the report records protocol metadata and errors, never password values.
"""
import asyncio, hashlib, ipaddress, json, ssl, sys

config = json.load(open(sys.argv[1]))
modes = ['plain', 'auth', 'held', 'stall', 'v6reply', 'domainreply', 'denied',
         'version', 'method', 'downgrade', 'authversion', 'authdenied',
         'replyversion', 'reserved', 'atyp', 'emptydomain', 'short', 'shortauth', 'shortaddr']
counts = {m: 0 for m in modes}
stats = {'connect': 0, 'targets': [], 'auth': 0, 'rejected': 0, 'errors': [],
         'originRequests': 0, 'leaked': 0, 'ipv6': False}
active, tasks = set(), set()
gate = asyncio.Event()
held = 0
fault_mode = 'short'
ports = set(config.get('ports', []))

async def send(writer, data):
    # Split handshake writes independently of client read boundaries.
    for offset in range(0, len(data), 3):
        writer.write(data[offset:offset+3]); await writer.drain(); await asyncio.sleep(0)

async def pump(reader, writer):
    try:
        while data := await reader.read(16384):
            writer.write(data); await writer.drain()
    except (OSError, ConnectionError): pass

async def proxy(reader, writer, mode):
    global held
    task = asyncio.current_task(); tasks.add(task); active.add(writer); remote = None
    try:
        greeting = await reader.readexactly(2)
        assert greeting[0] == 5 and 1 <= greeting[1] <= 2, 'Invalid greeting'
        methods = await reader.readexactly(greeting[1]); counts[mode] += 1
        auth = mode in ['auth', 'downgrade', 'authversion', 'authdenied', 'shortauth']
        method = 2 if auth else 0
        if mode == 'version': await send(writer, b'\x04\x00'); return
        if mode == 'method': await send(writer, b'\x05\x01'); return
        if mode == 'denied': await send(writer, b'\x05\xff'); return
        if mode == 'downgrade': await send(writer, b'\x05\x00'); await reader.read(1); return
        if method not in methods:
            stats['rejected'] += 1; await send(writer, b'\x05\xff'); return
        await send(writer, bytes([5, method]))
        if auth:
            version, size = await reader.readexactly(2); user = await reader.readexactly(size)
            size = (await reader.readexactly(1))[0]; password = await reader.readexactly(size)
            valid = version == 1 and (user, password) in [(b'user:name', b'synthetic-secret'), (b'u'*255, b'p'*255), ('مستخدم'.encode(), 'كلمة'.encode())]
            if mode == 'shortauth': await send(writer, b'\x01'); return
            if not valid or mode == 'authdenied':
                stats['rejected'] += 1; await send(writer, b'\x01\x01'); return
            await send(writer, b'\x02\x00' if mode == 'authversion' else b'\x01\x00')
            if mode == 'authversion': return
            stats['auth'] += 1
        head = await reader.readexactly(4)
        assert head[:3] == b'\x05\x01\x00', 'Not CONNECT'
        atyp = head[3]
        if atyp == 3:
            size = (await reader.readexactly(1))[0]; host = (await reader.readexactly(size)).decode('ascii')
        else:
            assert atyp in [1, 4], 'Invalid request address type'
            host = str(ipaddress.ip_address(await reader.readexactly(4 if atyp == 1 else 16)))
        port = int.from_bytes(await reader.readexactly(2), 'big')
        stats['targets'].append({'type': atyp, 'host': host, 'port': port, 'mode': mode})
        stats['connect'] += 1
        assert port in ports and host in ['localhost', 'proxy-only.invalid', '127.0.0.1', '127.0.0.2', '::1'], 'Nonfixture target'
        if mode in ['held', 'stall']:
            held += 1
            waits = [asyncio.create_task(reader.read(1))]
            if mode == 'held': waits.append(asyncio.create_task(gate.wait()))
            try:
                done, _ = await asyncio.wait(waits, return_when=asyncio.FIRST_COMPLETED)
                if waits[0] in done: return
            finally:
                held -= 1
                for wait in waits: wait.cancel()
                await asyncio.gather(*waits, return_exceptions=True)
        bad = {'replyversion': b'\x04\x00\x00\x01', 'reserved': b'\x05\x00\x01\x01',
               'atyp': b'\x05\x00\x00\x09', 'emptydomain': b'\x05\x00\x00\x03\x00',
               'short': b'\x05\x00', 'shortaddr': b'\x05\x00\x00\x04\x00'}
        if mode in bad: await send(writer, bad[mode]); return
        target = '127.0.0.1' if atyp == 3 else host
        try: upstream, remote = await asyncio.open_connection(target, port)
        except OSError: await send(writer, b'\x05\x05\x00\x01\0\0\0\0\0\0'); return
        reply = b'\x05\x00\x00\x01\x7f\x00\x00\x01\x00\x00'
        if mode == 'v6reply': reply = b'\x05\x00\x00\x04' + b'\0'*16 + b'\0\0'
        if mode == 'domainreply': reply = b'\x05\x00\x00\x03\x09localhost\0\0'
        await send(writer, reply)
        transfer = [asyncio.create_task(pump(reader, remote)), asyncio.create_task(pump(upstream, writer))]
        try: await asyncio.wait(transfer, return_when=asyncio.FIRST_COMPLETED)
        finally:
            for child in transfer: child.cancel()
            await asyncio.gather(*transfer, return_exceptions=True)
    except (OSError, ConnectionError, asyncio.IncompleteReadError): pass
    except Exception as error: stats['errors'].append(repr(error))
    finally:
        if remote: remote.close()
        writer.close(); active.discard(writer); tasks.discard(task)

async def origin(reader, writer):
    try:
        while True:
            header = await reader.readuntil(b'\r\n\r\n')
            line, *fields = header.decode('latin1').split('\r\n')
            method, path, version = line.split(' ')
            assert path.startswith('/') and version == 'HTTP/1.1', 'SOCKS tunnel used absolute form'
            headers = dict(field.lower().split(':', 1) for field in fields if ':' in field)
            if 'proxy-authorization' in headers: stats['leaked'] += 1
            stats['originRequests'] += 1
            body = bytearray()
            if headers.get('transfer-encoding', '').strip() == 'chunked':
                while True:
                    size = int((await reader.readline()).strip(), 16)
                    assert size <= 65536
                    if not size: assert await reader.readline() == b'\r\n'; break
                    body.extend(await reader.readexactly(size)); assert await reader.readexactly(2) == b'\r\n'
                    assert len(body) <= 1048576
            else:
                size = int(headers.get('content-length', '0')); assert size <= 1048576
                body.extend(await reader.readexactly(size))
            payload = json.dumps({'host': headers.get('host', '').strip(), 'bytes': len(body), 'sha256': hashlib.sha256(body).hexdigest()}).encode()
            extra = b''
            if path == '/stream': payload = b'x'*524288
            if path == '/redirect': payload = b''; extra = b'Location: /ok\r\n'
            writer.write((b'HTTP/1.1 302 Found\r\n' if path == '/redirect' else b'HTTP/1.1 200 OK\r\n') + extra + b'Content-Length: ' + str(len(payload)).encode() + b'\r\n\r\n' + payload)
            await writer.drain()
    except (OSError, ConnectionError, asyncio.IncompleteReadError): pass
    except Exception as error: stats['errors'].append(repr(error))
    finally: writer.close()

async def control(reader, writer):
    global fault_mode
    try:
        path = (await reader.readuntil(b'\r\n\r\n')).split(b' ')[1]
        if path == b'/reset': gate.clear()
        if path == b'/release': gate.set()
        if path.startswith(b'/mode/'):
            fault_mode = path[6:].decode('ascii'); assert fault_mode in modes
        data = json.dumps(stats | {'held': held, 'active': len(active), 'counts': counts}).encode()
        writer.write(b'HTTP/1.1 200 OK\r\nConnection: close\r\nContent-Length: '+str(len(data)).encode()+b'\r\n\r\n'+data); await writer.drain()
    finally: writer.close()

async def main():
    servers, info = [], {}
    async def start(handler, host='127.0.0.1', tls=None):
        server = await asyncio.start_server(handler, host, 0, ssl=tls, limit=65536)
        servers.append(server); return server.sockets[0].getsockname()[1]
    info['http'] = await start(origin); ports.add(info['http'])
    if config.get('cert'):
        tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); tls.load_cert_chain(config['cert'], config['key']); tls.set_alpn_protocols(['http/1.1'])
        info['https'] = await start(origin, tls=tls); ports.add(info['https'])
    try: info['ipv6'] = await start(origin, '::1'); ports.add(info['ipv6']); stats['ipv6'] = True
    except OSError: info['ipv6'] = None
    for mode in modes:
        port = await start(lambda r, w, m=mode: proxy(r, w, m)); info[mode] = 'socks5h://127.0.0.1:'+str(port)
    info['fault'] = 'socks5h://127.0.0.1:'+str(await start(lambda r,w:proxy(r,w,fault_mode)))
    info['control'] = 'http://127.0.0.1:'+str(await start(control))
    print(json.dumps(info), flush=True); await asyncio.to_thread(sys.stdin.read)
    for server in servers: server.close(); await server.wait_closed()
    for writer in list(active): writer.close()
    pending = list(tasks)
    for task in pending: task.cancel()
    await asyncio.gather(*pending, return_exceptions=True)
    print(json.dumps(stats | {'held': held, 'active': len(active), 'counts': counts}), flush=True)

if __name__ == '__main__': asyncio.run(main())
