import http.server, threading, time, json, sys, ssl, urllib.parse, gzip
concurrent_barrier=threading.Barrier(6,timeout=5)
concurrent_release=threading.Event()
concurrent_lock=threading.Lock()
concurrent_arrivals=0
class Handler(http.server.BaseHTTPRequestHandler):
    protocol_version='HTTP/1.1'
    def log_message(self,*args): pass
    def do_HEAD(self):
        self.send_response(200); self.send_header("Content-Length","14"); self.end_headers()
    def do_POST(self): self.do_GET()
    def do_GET(self):
        global concurrent_arrivals
        path=urllib.parse.urlparse(self.path)
        query=urllib.parse.parse_qs(path.query)
        if path.path=='/slow': time.sleep(float(query.get('delay',['0.05'])[0]))
        body=b'hello asyncara'; status=200; extra={}
        if path.path=='/concurrent':
            with concurrent_lock:concurrent_arrivals+=1
            try:concurrent_barrier.wait()
            except threading.BrokenBarrierError:
                self.send_error(503,'Six simultaneous requests did not arrive');return
            if not concurrent_release.wait(timeout=5):
                self.send_error(503,'Timer task did not release pending requests');return
        if path.path=='/concurrent-status':
            with concurrent_lock:body=str(concurrent_arrivals).encode()
        if path.path=='/concurrent-release':concurrent_release.set()
        if path.path=='/echo': body=self.rfile.read(int(self.headers.get('Content-Length',0)))
        if path.path=='/status': status=503
        if path.path=='/redirect': status=302;extra['Location']='/ok'
        if path.path=='/large': body=b'x'*int(query.get('bytes',['1000000'])[0])
        if path.path=='/gzip': body=gzip.compress(body); extra['Content-Encoding']='gzip'
        if path.path=='/broken':
            self.send_response(200);self.send_header('Content-Length','10000');self.end_headers();self.wfile.write(b'x');self.close_connection=True;return
        try:
            self.send_response(status)
            for key,value in extra.items():self.send_header(key,value)
            if path.path=='/chunked':
                self.send_header('Transfer-Encoding','chunked');self.end_headers();self.wfile.write(b'5\r\nhello\r\n9\r\n asyncara\r\n0\r\n\r\n')
            else:
                self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
        except (BrokenPipeError,ConnectionResetError,ssl.SSLError): pass
plain=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler)
secure=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler)
ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(sys.argv[1],sys.argv[2]);secure.socket=ctx.wrap_socket(secure.socket,server_side=True)
for server in (plain,secure):threading.Thread(target=server.serve_forever,daemon=True).start()
print(json.dumps({'http':plain.server_port,'https':secure.server_port}),flush=True)
sys.stdin.read()
plain.shutdown();secure.shutdown()
