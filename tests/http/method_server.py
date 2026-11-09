"""HEAD method/body and reuse fixture; release is an independent HTTP request."""
import http.server, json, sys, threading

release = threading.Event()
lock = threading.Lock()
records = []

class Handler(http.server.BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'
    def log_message(self, *args): pass
    def do_HEAD(self):
        with lock:
            records.append({'method': self.command, 'length': self.headers.get('Content-Length'),
                            'transfer': self.headers.get('Transfer-Encoding')})
        if self.path == '/pending' and not release.wait(5):
            self.close_connection = True
            return
        self.send_response(200)
        self.send_header('Content-Length', '19')
        self.send_header('X-Method', self.command)
        self.end_headers()  # HEAD has metadata, never a response body.
    def do_GET(self):
        if self.path == '/release': release.set()
        with lock: body = json.dumps(records if self.path == '/records' else {'method': self.command}).encode()
        self.send_response(200)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        try: self.wfile.write(body)
        except (BrokenPipeError, ConnectionResetError): pass

server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
server.daemon_threads = True
threading.Thread(target=server.serve_forever, daemon=True).start()
print(server.server_port, flush=True)
sys.stdin.read()
release.set()
server.shutdown()
server.server_close()
