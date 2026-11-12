#!/usr/bin/env python3
"""Private TLS proxy peer with bounded loopback-only forwarding."""
import http.client,http.server,json,pathlib,socket,ssl,sys,threading,urllib.parse
from https_proxy_io import ready_for_tunnel
cfg=json.loads(pathlib.Path(sys.argv[1]).read_text());stop=threading.Event();lock=threading.Lock();stats={'http':0,'connect':0,'headers':0}
class Proxy(http.server.BaseHTTPRequestHandler):
    protocol_version='HTTP/1.1'
    def log_message(self,*args):pass
    def do_CONNECT(self):
        if self.path!='localhost:'+str(cfg['https']):self.send_error(403);return
        remote=socket.create_connection(('127.0.0.1',cfg['https']),timeout=3)
        remote.setsockopt(socket.IPPROTO_TCP,socket.TCP_NODELAY,1)
        self.connection.setsockopt(socket.IPPROTO_TCP,socket.TCP_NODELAY,1)
        with lock:stats['connect']+=1
        self.send_response(200);self.send_header('X-Proxy-Only','outer');self.end_headers();self.wfile.flush();self.close_connection=True
        self.connection.settimeout(3)
        try:
            while not stop.is_set():
                ready=ready_for_tunnel(remote,self.connection)
                for source in ready:
                    data=source.recv(65536)
                    if not data:return
                    (remote if source is self.connection else self.connection).sendall(data)
        except OSError:pass
        finally:remote.close()
    def forward(self):
        url=urllib.parse.urlsplit(self.path)
        if url.scheme!='http' or url.hostname!='proxy-target.invalid':self.send_error(403);return
        if self.headers.get('Transfer-Encoding'):self.send_error(400);return
        length=int(self.headers.get('Content-Length','0'))
        if length<0 or length>1048576:self.send_error(413);return
        body=self.rfile.read(length)
        if len(body)!=length:return
        origin=http.client.HTTPConnection('127.0.0.1',cfg['http'],timeout=3)
        try:
            origin.request(self.command,urllib.parse.urlunsplit(('', '', url.path or '/',url.query,'')),body=body if length else None)
            response=origin.getresponse();data=response.read(2097153)
            if len(data)>2097152:self.send_error(502);return
            with lock:stats['http']+=1
            self.send_response(response.status);self.send_header('Content-Length',str(len(data)));self.end_headers();self.wfile.write(data)
        except OSError:self.close_connection=True
        finally:origin.close()
    do_GET=forward
    do_POST=forward
    do_PUT=forward
servers=[]
for kind in ['valid','mismatch']:
    server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Proxy);server.daemon_threads=True
    ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(cfg[kind+'Cert'],cfg['key'])
    ctx.set_alpn_protocols(['http/1.1']);server.socket=ctx.wrap_socket(server.socket,server_side=True)
    servers.append(server);threading.Thread(target=server.serve_forever,daemon=True).start()
print(json.dumps({'proxy':servers[0].server_port,'mismatch':servers[1].server_port}),flush=True)
sys.stdin.read();stop.set()
for s in servers:s.shutdown();s.server_close()
print(json.dumps(stats),flush=True)
