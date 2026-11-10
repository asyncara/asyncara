#!/usr/bin/env python3
"""Independent gzip/zlib wire producer; no PHP compression extension required."""
import gzip, hashlib, http.server, json, ssl, sys, threading, urllib.parse, zlib
DATA = bytes(range(256)) * 256
GATE = threading.Semaphore(0)
WAITING = threading.Event()
class Handler(http.server.BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'
    def log_message(self,*args): pass
    def do_HEAD(self): self.reply(head=True)
    def do_GET(self): self.reply()
    def reply(self,head=False):
        path=urllib.parse.urlparse(self.path).path
        try:
            if path=='/release':
                GATE.release();data=b'';coding=None
            elif path=='/waiting':data=b'1' if WAITING.is_set() else b'0';coding=None
            elif path=='/echo':data=self.headers.get('Accept-Encoding','').encode();coding=None
            else:
                coding='gzip';data=gzip.compress(DATA,mtime=0)
                if path=='/deflate':coding='deflate';data=zlib.compress(DATA)
                elif path=='/empty':data=gzip.compress(b'',mtime=0)
                elif path=='/concat':data+=gzip.compress(DATA,mtime=0)
                elif path=='/truncated':data=data[:-5]
                elif path=='/crc':data=data[:-8]+bytes([data[-8]^1])+data[-7:]
                elif path=='/junk':data+=b'invalid trailing data'
                elif path=='/raw-deflate':coding='deflate';data=zlib.compress(DATA,wbits=-15)
                elif path=='/unknown':coding='br'
                elif path=='/stacked':coding='gzip, deflate'
                elif path=='/bomb':data=gzip.compress(b'x'*(17*1024*1024),mtime=0)
                elif path=='/case':coding='GZip'
                elif path=='/identity':coding='identity';data=DATA
            self.send_response(304 if path=='/not-modified' else 200)
            if coding:self.send_header('Content-Encoding',coding)
            if path=='/duplicate':self.send_header('Content-Encoding','gzip')
            if path=='/byte-chunks':self.send_header('Transfer-Encoding','chunked')
            else:self.send_header('Content-Length',str(len(data)+(7 if path=='/wire-truncated' else 0)))
            self.end_headers()
            if head or path=='/not-modified':return
            if path=='/held':
                cut=len(data)//2;self.wfile.write(data[:cut]);self.wfile.flush();WAITING.set()
                if not GATE.acquire(timeout=10):self.close_connection=True;return
                WAITING.clear();self.wfile.write(data[cut:]);return
            if path=='/byte-chunks':
                for value in data:self.wfile.write(b'1\r\n'+bytes([value])+b'\r\n')
                self.wfile.write(b'0\r\n\r\n');return
            self.wfile.write(data)
            if path=='/wire-truncated':self.close_connection=True
        except (BrokenPipeError,ConnectionResetError,ssl.SSLError):self.close_connection=True
servers=[]
for secure in [False,True]:
    s=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler);s.daemon_threads=True
    if secure:
        context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);context.load_cert_chain(sys.argv[1],sys.argv[2]);s.socket=context.wrap_socket(s.socket,server_side=True)
    servers.append(s);threading.Thread(target=s.serve_forever,daemon=True).start()
print(json.dumps({'http':servers[0].server_port,'https':servers[1].server_port,'decoded':hashlib.sha256(DATA).hexdigest(),'encoded':hashlib.sha256(gzip.compress(DATA,mtime=0)).hexdigest()}),flush=True)
sys.stdin.read()
for s in servers:s.shutdown();s.server_close()
