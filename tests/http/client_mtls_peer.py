#!/usr/bin/env python3
"""Private OpenSSL peer: report actual certificate identity, never key material."""
import asyncio, hashlib, json, pathlib, ssl, sys

async def main():
    certs = pathlib.Path(sys.argv[1]); release = asyncio.Event(); peers = set(); tasks = set(); sequence = 0
    async def serve(reader, writer):
        nonlocal sequence
        sequence += 1; connection = sequence; peers.add(writer); tasks.add(asyncio.current_task())
        try:
            while True:
                raw = await reader.readuntil(b'\r\n\r\n'); method, path, _ = raw.split(b'\r\n', 1)[0].split(b' ')
                headers = dict(x.split(b':', 1) for x in raw.lower().split(b'\r\n')[1:] if b':' in x)
                body = b''
                if b'content-length' in headers: body = await reader.readexactly(int(headers[b'content-length']))
                elif headers.get(b'transfer-encoding', b'').strip() == b'chunked':
                    while True:
                        size = int((await reader.readline()).strip(), 16)
                        if size == 0: assert await reader.readline() == b'\r\n'; break
                        body += await reader.readexactly(size); assert await reader.readexactly(2) == b'\r\n'
                tls = writer.get_extra_info('ssl_object')
                if path in [b'/redirect', b'/cross']:
                    location = '/' if path == b'/redirect' else 'https://127.0.0.1:' + str(ports['TLSv1_2']) + '/'
                    writer.write(b'HTTP/1.1 302 Found\r\nContent-Length: 0\r\nLocation: ' + location.encode() + b'\r\n\r\n'); await writer.drain(); continue
                result = {'certificate': hashlib.sha256(tls.getpeercert(binary_form=True)).hexdigest(),
                          'connection': connection, 'version': tls.version(), 'method': method.decode(),
                          'size': len(body), 'sha256': hashlib.sha256(body).hexdigest()}
                payload = json.dumps(result).encode() if path != b'/large' else bytes(range(256)) * 4096
                writer.write(b'HTTP/1.1 200 OK\r\nContent-Length: ' + str(len(payload)).encode() + b'\r\n\r\n' + payload[:-1]); await writer.drain()
                if path == b'/hold':
                    print(json.dumps({'pending': connection}), flush=True); await release.wait()
                writer.write(payload[-1:]); await writer.drain()
        except (ConnectionError, ssl.SSLError, asyncio.IncompleteReadError): pass
        finally:
            peers.discard(writer); tasks.discard(asyncio.current_task()); writer.close()
    servers = []; ports = {}
    for version in [ssl.TLSVersion.TLSv1_2, ssl.TLSVersion.TLSv1_3]:
        ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); ctx.minimum_version = ctx.maximum_version = version
        ctx.load_cert_chain(certs/'server.pem', certs/'server.key'); ctx.load_verify_locations(cafile=str(certs/'ca.pem'))
        ctx.verify_mode = ssl.CERT_REQUIRED; ctx.set_alpn_protocols(['http/1.1'])
        s = await asyncio.start_server(serve, '127.0.0.1', 0, ssl=ctx, ssl_handshake_timeout=3)
        servers.append(s); ports[version.name] = s.sockets[0].getsockname()[1]
    print(json.dumps(ports), flush=True)
    while True:
        line = await asyncio.to_thread(sys.stdin.readline)
        if not line: break
        if line.strip() == 'release': release.set()
    for s in servers: s.close()
    for p in list(peers): p.transport.abort()
    for t in list(tasks): t.cancel()
    await asyncio.gather(*tasks, return_exceptions=True)
    for s in servers: await s.wait_closed()

asyncio.run(main())
