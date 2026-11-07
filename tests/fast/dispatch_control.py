"""Causal diagnostic, not acceptance: deliberate interpreter delay during I/O."""
import argparse,http.server,threading,time,pathlib,json,subprocess,os,hashlib,socketserver
p=argparse.ArgumentParser();p.add_argument('--output',required=True);p.add_argument('--php',required=True);p.add_argument('--extension',required=True);p.add_argument('--trace',action='store_true');a=p.parse_args()
root=pathlib.Path(__file__).resolve().parents[2];out=pathlib.Path(a.output);out.mkdir(parents=True,exist_ok=False)
barrier=threading.Barrier(4);gate=out/'peer-gate';rows=[];ready=threading.Event()
class G(socketserver.BaseRequestHandler):
 def handle(self):
  if ready.wait(5):self.request.sendall(b'G')
gates=socketserver.ThreadingTCPServer(('127.0.0.1',0),G);threading.Thread(target=gates.serve_forever,daemon=True).start()
class H(http.server.BaseHTTPRequestHandler):
 def log_message(self,*args):pass
 def do_GET(self):
  start=time.monotonic_ns();barrier.wait(timeout=5);gate.touch();ready.set();time.sleep(.2)
  data=json.dumps({'start':start,'end':time.monotonic_ns()}).encode();self.send_response(200);self.send_header('Content-Length',str(len(data)));self.end_headers();self.wfile.write(data)
s=http.server.ThreadingHTTPServer(('127.0.0.1',0),H);threading.Thread(target=s.serve_forever,daemon=True).start()
try:
 for backend in ['epoll','poll']:
  for mode in ['normal','delay','recovery']:
   gate.unlink(missing_ok=True);barrier=threading.Barrier(4);ready.clear()
   cmd=[a.php,'-n','-d','extension='+str(pathlib.Path(a.extension).resolve()),'tests/fast/dispatch_control.php',f'http://127.0.0.1:{s.server_port}/',str(gate),mode,str(gates.server_address[1])]
   env=os.environ|{'ASYNCARA_BACKEND':backend}
   if a.trace:env['ASYNCARA_TRACE_OUTPUT']=str((out/f'{backend}-{mode}-trace.json').resolve())
   r=subprocess.run(cmd,cwd=root,env=env,capture_output=True,text=True,timeout=10)
   (out/f'{backend}-{mode}.log').write_text(r.stdout+r.stderr)
   assert r.returncode==0,(backend,mode,r.stdout,r.stderr)
   result=json.loads(r.stdout);rows.append({'mode':mode,**result})
 (out/'report.json').write_text(json.dumps({'diagnostic':True,'cases':rows,'meaning':'Injected delay can miss progress; no attribution to historical failure.'},indent=2)+'\n')
 print([(r['backend'],r['mode'],r['ticks'])for r in rows])
finally:s.shutdown();s.server_close();gates.shutdown();gates.server_close()
