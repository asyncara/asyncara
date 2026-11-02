#!/usr/bin/env python3
"""Deliberately slow loopback peers, independent of the PHP scheduler."""
import json,pathlib,socketserver,ssl,sys,threading,time
class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address=True
    daemon_threads=True
    request_queue_size=128
class TCP(socketserver.BaseRequestHandler):
    def handle(self):
        mode=self.request.recv(1)
        time.sleep(.2)
        if mode==b'W':
            total=0
            while total<8*1024*1024:
                chunk=self.request.recv(min(65536,8*1024*1024-total))
                if not chunk:return
                total+=len(chunk)
        try:self.request.sendall(b'x')
        except (BrokenPipeError,ConnectionResetError):pass
class TLS(socketserver.BaseRequestHandler):
    def handle(self):
        time.sleep(.2)
        try:
            with context.wrap_socket(self.request,server_side=True)as stream:stream.sendall(b'x')
        except (ssl.SSLError,BrokenPipeError,ConnectionResetError):pass
class UDP(socketserver.BaseRequestHandler):
    def handle(self):
        data,sock=self.request;time.sleep(.2);sock.sendto(data,self.client_address)
context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);context.load_cert_chain(sys.argv[1],sys.argv[2])
servers={'tcp':Server(('127.0.0.1',0),TCP),'tls':Server(('127.0.0.1',0),TLS),'udp':socketserver.ThreadingUDPServer(('127.0.0.1',0),UDP)}
try:
    for server in servers.values():threading.Thread(target=server.serve_forever,daemon=True).start()
    print(json.dumps({name:server.server_address[1]for name,server in servers.items()}),flush=True)
    sys.stdin.buffer.read()
finally:
    for server in servers.values():server.shutdown();server.server_close()
