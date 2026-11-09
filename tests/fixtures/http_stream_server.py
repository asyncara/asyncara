#!/usr/bin/env python3
"""Deterministic streaming server: tiny writes, stalled/truncated transfers, TLS."""
import email.parser, email.policy, gzip, hashlib, http.server, json, socket, ssl, sys, threading, time, urllib.parse

BLOCK = bytes(range(256)) * 256
# One release per body, including a control handled before its body request.
# Clearing an Event on request arrival lost an earlier release on another
# connection and produced a spurious truncated response after the 15s guard.
HEADER_GATE = threading.Semaphore(0)

class Handler(http.server.BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'
    def log_message(self, *args): pass
    def do_HEAD(self):
        # Metadata describes the corresponding body; HEAD sends none.
        self.send_response(200)
        self.send_header('Content-Length', str(len(BLOCK)))
        self.send_header('X-Method', 'HEAD')
        self.end_headers()
    def do_PUT(self): self.do_POST()
    def do_POST(self):
        try:
            if self.path == '/reject':
                self.send_response(413);self.send_header('Content-Length','0');self.send_header('Connection','close');self.end_headers();self.close_connection=True;return
            digest=hashlib.sha256();total=0;multipart=[]
            def accept(data):
                nonlocal total
                total+=len(data)
                if total > 64*1024*1024: raise ValueError('fixture upload cap')
                digest.update(data)
                if self.path == '/multipart': multipart.append(data)
                if self.path == '/slow-upload': time.sleep(.001)
            if self.headers.get('Transfer-Encoding','').lower() == 'chunked':
                while True:
                    line=self.rfile.readline(128)
                    if not line:return
                    size=int(line.strip().split(b';')[0],16)
                    if not size:
                        if self.rfile.readline(128)!=b'\r\n':raise ValueError('trailer')
                        break
                    if size>65536:raise ValueError('chunk cap')
                    data=self.rfile.read(size)
                    if len(data)!=size or self.rfile.read(2)!=b'\r\n':return
                    accept(data)
            else:
                left=int(self.headers.get('Content-Length','0'))
                while left:
                    data=self.rfile.read(min(left,65536))
                    if not data:return
                    left-=len(data);accept(data)
            result={'bytes':total,'sha256':digest.hexdigest(),'method':self.command}
            if multipart:
                message=email.parser.BytesParser(policy=email.policy.default).parsebytes(
                    ('Content-Type: '+self.headers['Content-Type']+'\r\nMIME-Version: 1.0\r\n\r\n').encode()+b''.join(multipart))
                result['parts']=[{'name':p.get_param('name',header='content-disposition'),'filename':p.get_filename(),
                    'bytes':len(p.get_payload(decode=True)),'sha256':hashlib.sha256(p.get_payload(decode=True)).hexdigest()} for p in message.iter_parts()]
            if self.path == '/post-redirect':
                self.send_response(303);self.send_header('Location','/echo-headers');self.send_header('Content-Length','0');self.end_headers();return
            body=json.dumps(result).encode();self.send_response(200);self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
        except (BrokenPipeError,ConnectionResetError,ssl.SSLError):self.close_connection=True
    def do_GET(self):
        path = urllib.parse.urlparse(self.path).path
        try:
            if path == '/echo-headers':
                body=json.dumps({'method':self.command,'marker':self.headers.get('X-Marker'),'cookie':self.headers.get('Cookie'),'length':self.headers.get('Content-Length')}).encode()
                self.send_response(200);self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body);return
            if path == '/status':
                self.send_response(503);self.send_header('Content-Length','4');self.end_headers();self.wfile.write(b'fail');return
            if path == '/redirect-body':
                self.send_response(302);self.send_header('Location','/small');self.send_header('Content-Length','12');self.end_headers();self.wfile.write(b'intermediate');return
            if path == '/redirect-loop':
                self.send_response(302);self.send_header('Location','/redirect-loop');self.send_header('Content-Length','0');self.end_headers();return
            if path == '/redirect-file':
                self.send_response(302);self.send_header('Location','file:///etc/passwd');self.send_header('Content-Length','0');self.end_headers();return
            if path == '/redirect':
                self.send_response(302); self.send_header('Location', '/small'); self.send_header('Content-Length', '0'); self.end_headers(); return
            if path == '/stall':
                time.sleep(.5)
            if path == '/gzip':
                data = gzip.compress(BLOCK, mtime=0)
                self.send_response(200); self.send_header('Content-Encoding', 'gzip'); self.send_header('Content-Length', str(len(data))); self.end_headers(); self.wfile.write(data); return
            if path == '/headers-first':
                self.send_response(200);self.send_header('Content-Length','1');self.end_headers();self.wfile.flush()
                if not HEADER_GATE.acquire(timeout=15):self.close_connection=True;return
                self.wfile.write(b'x');return
            if path == '/release-body':
                HEADER_GATE.release();self.send_response(200);self.send_header('Content-Length','0');self.end_headers();return
            if path == '/empty':
                self.send_response(204);self.send_header('Content-Length','0');self.end_headers();return
            if path == '/chunks':
                self.send_response(200); self.send_header('Transfer-Encoding', 'chunked'); self.end_headers()
                for n in [1, 7, 61, 2048, 65536]:
                    data = BLOCK[:n]
                    frame = f'{n:x}\r\n'.encode()+data+b'\r\n'
                    for start in range(0, len(frame), 137): self.wfile.write(frame[start:start+137])
                self.wfile.write(b'0\r\nTrailer: value\r\n\r\n'); return
            count = 512 if path in ['/large', '/broken'] else (128 if path == '/medium' else 1)
            self.send_response(200); self.send_header('Content-Length', str(len(BLOCK)*count)); self.end_headers()
            if path == '/broken':
                self.wfile.write(BLOCK[:17]); self.close_connection = True; return
            for _ in range(count):
                self.wfile.write(BLOCK)
                if path == '/slow': time.sleep(.005)
        except (BrokenPipeError, ConnectionResetError, ssl.SSLError):
            self.close_connection = True

servers=[]
for secure in [False, True]:
    server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler)
    server.daemon_threads=True
    if secure:
        ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(sys.argv[1],sys.argv[2])
        server.socket=ctx.wrap_socket(server.socket,server_side=True)
    servers.append(server);threading.Thread(target=server.serve_forever,daemon=True).start()
print(json.dumps({'http':servers[0].server_port,'https':servers[1].server_port}),flush=True)
sys.stdin.read()
for server in servers:server.shutdown();server.server_close()
