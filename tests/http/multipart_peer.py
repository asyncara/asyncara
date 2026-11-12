#!/usr/bin/env python3
"""Independent email/MIME decoder and explicitly gated HTTP(S) upload peer."""
import asyncio, email.parser, email.policy, hashlib, json, pathlib, ssl, sys, re, time, os, resource

def decode_parts(content_type, body):
    # Only MIME headers are textual. Sending a 32 MiB binary body through the
    # email package's line/string parser needlessly decodes millions of lines.
    # Retain independent header interpretation and exact binary payload hashes.
    envelope = email.parser.BytesHeaderParser(policy=email.policy.default).parsebytes(b'Content-Type: '+content_type+b'\r\n\r\n')
    assert envelope.get_content_type() == 'multipart/form-data'
    boundary = envelope.get_boundary().encode('ascii'); assert 1 <= len(boundary) <= 128
    delimiter = re.compile(rb'(?:\A|\r\n)--'+re.escape(boundary)+rb'(?:(\r\n)|(--\r\n\Z))')
    markers = list(delimiter.finditer(body)); assert 2 <= len(markers) <= 81
    assert markers[0].start() == 0 and markers[0].group(1) and markers[-1].group(2) and markers[-1].end() == len(body)
    parts = []; view = memoryview(body)
    for left, right in zip(markers, markers[1:]):
        start, end = left.end(), right.start(); stop = body.find(b'\r\n\r\n', start, end)
        assert stop >= start and stop-start <= 4096
        part = email.parser.BytesHeaderParser(policy=email.policy.default).parsebytes(body[start:stop]+b'\r\n\r\n')
        assert not part.defects and part.get_content_disposition() == 'form-data'
        data = view[stop+4:end]
        parts.append({'field':part.get_param('name',header='content-disposition'),'filename':part.get_filename(),
                      'type':part.get_content_type(),'bytes':len(data),'sha256':hashlib.sha256(data).hexdigest()})
    return parts

def observe(phase, **values):
    path = os.environ.get("ASYNCARA_MULTIPART_PEER_DIAGNOSTIC")
    if path:
        with open(path, "a") as out:
            out.write(json.dumps({"phase":phase,"monotonic":time.monotonic(),"cpu":resource.getrusage(resource.RUSAGE_SELF).ru_utime,**values})+"\n")

async def main():
    cert = pathlib.Path(sys.argv[1]); gates = {}; tasks = set(); writers = set(); errors = []
    async def serve(reader, writer):
        tasks.add(asyncio.current_task()); writers.add(writer)
        try:
            while True:
                raw = await reader.readuntil(b'\r\n\r\n')
                method, path, _ = raw.split(b'\r\n', 1)[0].split(b' ')
                headers = {k.lower(): v.strip() for k, v in (line.split(b':', 1) for line in raw.split(b'\r\n')[1:] if b':' in line)}
                if path.startswith((b'/hold/', b'/reject/')):
                    name = path.rsplit(b'/', 1)[1].decode(); event = gates.setdefault(name, asyncio.Event())
                    print(json.dumps({'pending': name}), flush=True)
                    if path.startswith(b'/reject/'):
                        writer.write(b'HTTP/1.1 413 Content Too Large\r\nContent-Length: 0\r\nConnection: close\r\n\r\n'); await writer.drain()
                    await event.wait()
                    if path.startswith(b'/reject/'): break
                observe('body-start', path=path.decode()); read_started = time.monotonic(); checkpoint = 0
                body = bytearray()
                if headers.get(b'transfer-encoding') == b'chunked':
                    while True:
                        line = await reader.readline()
                        if not line: raise asyncio.IncompleteReadError(b'', 1)
                        length = int(line.split(b';', 1)[0], 16)
                        if not length:
                            assert await reader.readline() == b'\r\n'; break
                        assert len(body) + length <= 40 * 1048576
                        body.extend(await reader.readexactly(length)); assert await reader.readexactly(2) == b'\r\n'
                        if len(body)//(8*1048576) > checkpoint:
                            checkpoint = len(body)//(8*1048576); observe('body-progress', bytes=len(body))
                else:
                    length = int(headers.get(b'content-length', b'0')); assert length <= 40 * 1048576
                    body.extend(await reader.readexactly(length))
                read_seconds = time.monotonic()-read_started; parse_started = time.monotonic()
                observe('parse-start', bytes=len(body)); parts = decode_parts(headers[b'content-type'], bytes(body))
                observe('parse-end', bytes=len(body)); response = json.dumps({'method': method.decode(), 'parts': parts, 'readSeconds':read_seconds, 'parseSeconds':time.monotonic()-parse_started}).encode()
                writer.write(b'HTTP/1.1 200 OK\r\nContent-Length: '+str(len(response)).encode()+b'\r\n\r\n'+response); await writer.drain()
        except (ConnectionError, ssl.SSLError, asyncio.IncompleteReadError) as error: observe('peer-disconnect', error=type(error).__name__)
        except Exception as error:
            errors.append(repr(error)); print(repr(error), file=sys.stderr, flush=True)
        finally:
            writers.discard(writer); tasks.discard(asyncio.current_task()); writer.transport.abort()
    tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); tls.load_cert_chain(cert/'server.pem', cert/'server.key')
    tls.set_alpn_protocols(['http/1.1'])
    servers = [await asyncio.start_server(serve, '127.0.0.1', 0, ssl=ctx) for ctx in [None, tls]]
    print(json.dumps(dict(zip(['http', 'https'], [s.sockets[0].getsockname()[1] for s in servers]))), flush=True)
    while line := await asyncio.to_thread(sys.stdin.readline):
        command, name = line.strip().split(' ', 1); assert command == 'release'
        gates.setdefault(name, asyncio.Event()).set()
    for s in servers: s.close()
    for w in list(writers): w.transport.abort()
    for t in list(tasks): t.cancel()
    await asyncio.gather(*tasks, return_exceptions=True)
    for s in servers: await s.wait_closed()
    assert not errors, errors

if __name__ == '__main__': asyncio.run(main())
