#!/usr/bin/env python3
"""Isolated HTTP proxy + CONNECT relay and a deliberately silent TLS peer."""
import http.server,http.client,json,select,socket,socketserver,sys,threading,urllib.parse
ports=json.loads(sys.argv[1]);stop=threading.Event();stats={'http':0,'connect':0,'silent':0}
class Proxy(http.server.BaseHTTPRequestHandler):
 protocol_version='HTTP/1.1'
 def log_message(self,*args):pass
 def do_CONNECT(self):
  assert self.path=='localhost:'+str(ports['https']);stats['connect']+=1
  remote=socket.create_connection(('127.0.0.1',ports['https']),timeout=2)
  self.send_response(200);self.end_headers();self.close_connection=True
  try:
   while not stop.is_set():
    ready,_,_=select.select([remote,self.connection],[],[],.1)
    for source in ready:
     data=source.recv(65536)
     if not data:return
     (remote if source is self.connection else self.connection).sendall(data)
  except (OSError,ConnectionResetError):pass
  finally:remote.close()
 def do_GET(self):
  stats['http']+=1;url=urllib.parse.urlsplit(self.path)
  assert url.scheme=='http'and url.hostname=='proxy-target.invalid'
  origin=http.client.HTTPConnection('127.0.0.1',ports['http'],timeout=3)
  try:
   origin.request('GET',url.path or '/');r=origin.getresponse();body=r.read();self.send_response(r.status);self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
  finally:origin.close()
class Silent(socketserver.BaseRequestHandler):
 def handle(self):stats['silent']+=1;stop.wait(3)
class Server(socketserver.ThreadingTCPServer):daemon_threads=True;allow_reuse_address=True
proxy=http.server.ThreadingHTTPServer(('127.0.0.1',0),Proxy);proxy.daemon_threads=True
silent=Server(('127.0.0.1',0),Silent)
for s in [proxy,silent]:threading.Thread(target=s.serve_forever,daemon=True).start()
print(json.dumps({'proxy':proxy.server_port,'silent':silent.server_address[1]}),flush=True)
sys.stdin.read();stop.set()
for s in [proxy,silent]:s.shutdown();s.server_close()
print(json.dumps(stats),flush=True)
