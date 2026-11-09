#!/usr/bin/env python3
"""Isolated nginx fixture removes Python-server throughput from the comparison.
Hyper, when supplied, is explicitly a Rust-only engine experiment.
"""
import argparse, hashlib, json, os, pathlib, resource, signal, socket, subprocess, time
p=argparse.ArgumentParser()
for name in ['php','extension','output']:p.add_argument('--'+name,required=True)
p.add_argument('--baseline-extension');p.add_argument('--baseline-source-root')
p.add_argument('--jit',action='store_true',help='Enable identical tracing-JIT settings for every PHP client; separate configuration from no-JIT evidence')
p.add_argument('--server-workers',type=int,default=1,help='Isolated nginx worker count; identical for every client in this run')
p.add_argument('--compare-curl',action='store_true',help='Compare the same public PHP client against its default curl backend')
p.add_argument('--hyper');p.add_argument('--raw',action='store_true');p.add_argument('--samples',type=int,default=5);p.add_argument('--count',type=int,default=8000)
p.add_argument('--concurrency',type=int,default=8);a=p.parse_args()
if bool(a.baseline_extension)!=bool(a.baseline_source_root):p.error('Both baseline arguments required')
if a.server_workers<1 or a.server_workers>4:p.error('Server worker count must be 1..4')
if a.samples<3 or a.count<1 or a.concurrency<1 or a.concurrency>256 or a.count%a.concurrency:p.error('Invalid sample/count/concurrency')
if a.baseline_extension and a.concurrency!=8:p.error('Historical baseline has fixed 8-per-host admission')
root=pathlib.Path(__file__).resolve().parents[2];out=pathlib.Path(a.output).resolve();out.mkdir(parents=True,exist_ok=False)
www=out/'www';www.mkdir()
for size in [1024,65536]:(www/(str(size)+'.bin')).write_bytes(b'x'*size)
with socket.socket()as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
cfg=out/'nginx.conf';cfg.write_text(f'''daemon off;
master_process {'on'if a.server_workers>1 else'off'};
worker_processes {a.server_workers};
pid {out}/nginx.pid;
error_log {out}/nginx-error.log warn;
events {{ worker_connections 1024; }}
http {{
 access_log off;
 client_body_temp_path {out}/client_temp;
 proxy_temp_path {out}/proxy_temp;
 fastcgi_temp_path {out}/fastcgi_temp;
 uwsgi_temp_path {out}/uwsgi_temp;
 scgi_temp_path {out}/scgi_temp;
 sendfile on;
 gzip off;
 keepalive_timeout 60;
 keepalive_requests 1000000;
 server {{ listen 127.0.0.1:{port}; root {www};
   location = /body {{ try_files /$arg_size.bin =404; }}
 }}
}}
''')
sha=lambda f:hashlib.sha256(pathlib.Path(f).read_bytes()).hexdigest()
identity={'arguments':vars(a),'source':subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip(),
          'moduleSha256':sha(a.extension),'nginx':subprocess.check_output(['nginx','-v'],stderr=subprocess.STDOUT,text=True).strip(),
          'backendEnvironment':{k:os.environ.get(k) for k in ['ASYNCARA_BACKEND','ASYNCARA_HTTP_BACKEND']},
          'scope':'HTTP/1.1 plaintext keepalive, checked identical full bodies, 10 warmups/connection, serialized rotated samples',
          'manifest':{str(x.relative_to(root)):sha(x)for d in ['ext','src','benchmarks/http']for x in(root/d).rglob('*')if x.is_file()and '__pycache__'not in str(x)}}
if a.baseline_extension:identity['baseline']={'moduleSha256':sha(a.baseline_extension),'source':subprocess.check_output(['git','-C',a.baseline_source_root,'rev-parse','HEAD'],text=True).strip()}
if a.hyper:identity['hyperStandaloneSha256']=sha(a.hyper)
(out/'identity.json').write_text(json.dumps(identity,indent=2)+'\n')
nginx=['nginx','-p',str(out),'-e',str(out/'nginx-bootstrap.log'),'-c',str(cfg)]
with(out/'nginx-check.log').open('xb')as f:subprocess.run(nginx+['-t'],stdout=f,stderr=subprocess.STDOUT,check=True)
log=(out/'nginx-start.log').open('xb');server=subprocess.Popen(nginx,stdout=log,stderr=log)
rows=[]
def server_processes():
 children=pathlib.Path(f'/proc/{server.pid}/task/{server.pid}/children').read_text().split()
 return [server.pid]+list(map(int,children))
def server_cpu():
 return sum(sum(map(int,pathlib.Path(f'/proc/{pid}/stat').read_text().split()[13:15]))for pid in server_processes())/os.sysconf('SC_CLK_TCK')
try:
 deadline=time.monotonic()+10
 while True:
  if server.poll()is not None:raise RuntimeError('nginx startup failed')
  try:
   with socket.create_connection(('127.0.0.1',port),timeout=.1):break
  except OSError:
   if time.monotonic()>deadline:raise
   time.sleep(.01)
 (out/'server-processes.json').write_text(json.dumps({'pids':server_processes(),'workerCount':a.server_workers}))
 for size in [1024,65536]:
  for sample in range(a.samples):
   modes=['asyncara','curl_multi','swoole']+(['asyncara_curl']if a.compare_curl else[])+(['asyncara_baseline']if a.baseline_extension else[])+(['hyper_standalone']if a.hyper else[])+(['asyncara_raw']if a.raw else[])
   pos=sample%len(modes);modes=modes[pos:]+modes[:pos]
   for mode in modes:
    env=os.environ.copy();env['ASYNCARA_SOURCE_ROOT']=a.baseline_source_root if mode=='asyncara_baseline'else str(root)
    if mode in ['asyncara_curl','asyncara_baseline']:env['ASYNCARA_HTTP_BACKEND']='curl'
    url=f'http://127.0.0.1:{port}/body?size={size}'
    if mode=='hyper_standalone':cmd=[a.hyper,url,str(a.count),str(a.concurrency),str(size)]
    else:
     cmd=[a.php,'-n','-d','memory_limit=512M','-d','extension=curl']
     if a.jit:cmd+=['-d','opcache.enable_cli=1','-d','opcache.jit=tracing','-d','opcache.jit_buffer_size=128M']
     if mode.startswith('asyncara'):cmd+=['-d','extension='+str(pathlib.Path(a.baseline_extension if mode=='asyncara_baseline'else a.extension).resolve())]
     if mode=='swoole':cmd+=[item for ext in ['pdo','mysqlnd','sockets','swoole']for item in ['-d','extension='+ext]]
     cmd+=[str(root/'benchmarks/http/client.php'),'asyncara'if mode in ['asyncara_baseline','asyncara_curl']else mode,url,str(a.count),str(a.concurrency),str(size)]
    path=out/f'{size}-{sample}-{mode}.log';cpu_before=server_cpu();pressure=pathlib.Path('/proc/pressure/cpu').read_text();start=time.monotonic()
    with path.open('xb')as f:
     child=subprocess.Popen(cmd,cwd=root,env=env,stdout=f,stderr=subprocess.STDOUT)
     (out/'active.json').write_text(json.dumps({'serverPid':server.pid,'clientPid':child.pid,'command':cmd,'status':'RUNNING'}))
     try:code=child.wait(timeout=120)
     except subprocess.TimeoutExpired:child.terminate();child.wait(timeout=10);raise
    if code:raise RuntimeError(str(path)+': '+path.read_text())
    row=json.loads(path.read_text())|{'mode':mode,'sample':sample,'serverCpuSeconds':server_cpu()-cpu_before,'processWallSeconds':time.monotonic()-start,
       'cpuPressureBefore':pressure,'command':cmd,'logSha256':sha(path)}
    if a.jit and mode!='hyper_standalone' and not (row.get('jit') or {}).get('on'):raise RuntimeError('JIT not active for '+mode)
    if mode.startswith('asyncara') and env.get('ASYNCARA_HTTP_BACKEND')=='hyper-prototype':
     native=row['native'];expected=a.count+10*a.concurrency
     if native.get('httpBackend')!='hyper-prototype' or native.get('hyperSubmitted')!=expected or native.get('hyperCompleted')!=expected or native['pendingOperations'] or native['nativeMemoryBytes'] or native['nativeWorkers']:raise RuntimeError('Prototype path/completion/cleanup identity failed')
    rows.append(row);(out/'results.json').write_text(json.dumps(rows,indent=2)+'\n');print(size,sample,mode,round(row['requestsPerSecond'],1),flush=True)
finally:
 if server.poll()is None:server.send_signal(signal.SIGQUIT)
 server.wait(timeout=10);log.close();(out/'active.json').write_text(json.dumps({'status':'TERMINAL','serverExit':server.returncode}))
assert server.returncode==0
(out/'report.json').write_text(json.dumps({'status':'PASS','identity':identity,'samples':rows},indent=2)+'\n')
