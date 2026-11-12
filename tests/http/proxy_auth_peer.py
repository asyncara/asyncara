#!/usr/bin/env python3
"""Loopback-only Basic proxy/origin oracle. Records counts, never credentials."""
import base64, hashlib, http.client, http.server, json, pathlib, select, socket, ssl, sys, threading, urllib.parse

cfg = json.loads(pathlib.Path(sys.argv[1]).read_text())
lock = threading.Lock()
release = threading.Event()
stop = threading.Event()
stats = dict(authorized=0, rejected=0, connect=0, forward=0, origin=0, leaked=0, held=0, originAuthOnConnect=0, proxyClientIdentities=0, originClientIdentities=0)
expected = 'Basic ' + base64.b64encode(b'asyncara-test:synthetic:proxy-secret').decode()

class Quiet(http.server.BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'
    def log_message(self, *args): pass
    def reply(self, code, data=b'', headers=()):
        self.send_response(code)
        self.send_header('Content-Length', str(len(data)))
        for name, value in headers: self.send_header(name, value)
        self.end_headers()
        if data: self.wfile.write(data)

class Origin(Quiet):
    def do_GET(self):
        try:
            with lock:
                stats['origin'] += 1
                stats['leaked'] += int(self.headers.get('Proxy-Authorization') is not None)
            if self.path == '/client-identity':
                certificate=self.connection.getpeercert(binary_form=True) if isinstance(self.connection,ssl.SSLSocket) else None
                if not certificate:self.reply(403);return
                with lock:stats['originClientIdentities']+=1
                self.reply(200,hashlib.sha256(certificate).hexdigest().encode());return
            if self.path == '/hold':
                with lock: stats['held'] += 1
                try:
                    if not release.wait(10): raise RuntimeError('Peer release not received')
                finally:
                    with lock: stats['held'] -= 1
            if self.path == '/redirect':
                self.reply(302, headers=[('Location', '/ok')]); return
            if self.path == '/stream':
                self.reply(200, b'x' * (512 * 1024)); return
            self.reply(200, b'origin-ok')
        except (OSError, ssl.SSLError): pass
    def do_POST(self):
        try:
            with lock:
                stats['origin'] += 1
                stats['leaked'] += int(self.headers.get('Proxy-Authorization') is not None)
            length = int(self.headers.get('Content-Length', '0'))
            if not 0 <= length <= 1048576: self.reply(413); return
            data = self.rfile.read(length)
            self.reply(200, json.dumps({'bytes':len(data), 'sha256':hashlib.sha256(data).hexdigest()}).encode())
        except OSError: pass

def start(handler, tls=False):
    server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), handler)
    server.daemon_threads = True
    if tls:
        ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        ctx.load_cert_chain(cfg['cert'], cfg['key'])
        ctx.set_alpn_protocols(['http/1.1'])
        if cfg.get('clientCa'):
            ctx.load_verify_locations(cafile=cfg['clientCa']);ctx.verify_mode=ssl.CERT_OPTIONAL
        server.socket = ctx.wrap_socket(server.socket, server_side=True)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    return server

origin = start(Origin); secure = start(Origin, True)

class Proxy(Quiet):
    def authenticated(self):
        if isinstance(self.connection,ssl.SSLSocket) and self.connection.getpeercert(binary_form=True):
            with lock:stats['proxyClientIdentities']+=1
        ok = self.headers.get('Proxy-Authorization') == expected
        with lock: stats['authorized' if ok else 'rejected'] += 1
        if not ok:
            self.close_connection = True
            self.reply(407, headers=[('Proxy-Authenticate', 'Basic realm="private-test"'), ('Connection','close')])
        return ok
    def do_CONNECT(self):
        if not self.authenticated(): return
        target = cfg.get('targetTlsPort', secure.server_port)
        if self.path != 'localhost:' + str(target): self.reply(403); return
        remote = socket.create_connection(('127.0.0.1', target), timeout=3)
        # Match the independent asyncio origin's TCP policy. Small HTTP/2
        # WINDOW_UPDATE records must not incur Nagle/delayed-ACK pauses at
        # either tunnel endpoint. Workload sizes/deadlines remain unchanged.
        remote.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
        self.connection.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
        with lock:
            stats['connect'] += 1
            stats['originAuthOnConnect'] += int(self.headers.get('Authorization') is not None)
        self.reply(200); self.wfile.flush(); self.close_connection = True
        self.connection.settimeout(3)
        try:
            while not stop.is_set():
                buffered = isinstance(self.connection, ssl.SSLSocket) and self.connection.pending() > 0
                ready, _, _ = select.select([remote, self.connection], [], [], 0 if buffered else .05)
                if buffered and self.connection not in ready: ready.append(self.connection)
                for source in ready:
                    data = source.recv(65536)
                    if not data: return
                    (remote if source is self.connection else self.connection).sendall(data)
        except OSError: pass
        finally: remote.close()
    def forward(self):
        if not self.authenticated(): return
        uri = urllib.parse.urlsplit(self.path)
        if uri.scheme != 'http' or uri.hostname != 'proxy-target.invalid': self.reply(403); return
        if self.headers.get('Transfer-Encoding'): self.reply(400); return
        length = int(self.headers.get('Content-Length','0'))
        if not 0 <= length <= 1048576: self.reply(413); return
        body = self.rfile.read(length)
        connection = http.client.HTTPConnection('127.0.0.1', origin.server_port, timeout=10)
        try:
            # Hop authentication terminates here. All other received headers
            # are forwarded, so origin leak accounting tests CONNECT separately.
            headers = {k:v for k,v in self.headers.items() if k.lower() not in ['proxy-authorization','host','connection']}
            connection.request(self.command, uri.path or '/', body=body, headers=headers)
            response = connection.getresponse(); data = response.read(1048577)
            if len(data) > 1048576: self.reply(502); return
            with lock: stats['forward'] += 1
            self.reply(response.status, data, [(k,v) for k,v in response.getheaders() if k.lower()=='location'])
        except OSError: pass
        finally: connection.close()
    do_GET = forward
    do_POST = forward

plain = start(Proxy); tls = start(Proxy, True)
class Control(Quiet):
    def do_GET(self):
        if self.path == '/release': release.set()
        if self.path == '/reset': release.clear()
        with lock: data = json.dumps(stats).encode()
        self.reply(200, data)
control = start(Control)
print(json.dumps({'httpProxy':'http://127.0.0.1:'+str(plain.server_port),
    'httpsProxy':'https://localhost:'+str(tls.server_port),
    'origin':'https://localhost:'+str(secure.server_port),
    'direct':'http://127.0.0.1:'+str(origin.server_port),
    'control':'http://127.0.0.1:'+str(control.server_port)}), flush=True)
sys.stdin.read(); stop.set(); release.set()
for server in [plain,tls,origin,secure,control]: server.shutdown(); server.server_close()
print(json.dumps(stats), flush=True)
