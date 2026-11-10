#!/usr/bin/env python3
"""Matched HTTP server comparison. All responses checked; fresh output only."""
import argparse, hashlib, http.client, json, os, pathlib, re, signal, socket, statistics, subprocess, time, sys
p=argparse.ArgumentParser();p.add_argument('--php',required=True);p.add_argument('--module',required=True);p.add_argument('--posix',required=True);p.add_argument('--go',required=True);p.add_argument('--out',required=True);p.add_argument('--samples',type=int,default=3);p.add_argument('--seconds',type=int,default=5);p.add_argument('--workers',default='1,4,6');p.add_argument('--sizes',default='10,1024,65536');p.add_argument('--delay',type=int,default=0);p.add_argument('--connections',type=int,default=128);a=p.parse_args()
root=pathlib.Path(__file__).resolve().parents[2];out=pathlib.Path(a.out);out.mkdir(parents=True,exist_ok=False)
sys.path.insert(0,str(root/'tests/soak'))
from monitor import process_tree
def snapshot(pid):
    records=process_tree(pid)
    for record in records:
        try:
            fields=pathlib.Path('/proc',str(record['pid']),'stat').read_text().rsplit(')',1)[1].split()
            record['cpuTicks']=int(fields[11])+int(fields[12])
        except FileNotFoundError:record['cpuTicks']=0
    return records
sha=lambda f:hashlib.sha256(pathlib.Path(f).read_bytes()).hexdigest()
identity={'command':__import__('sys').argv,'source':subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip(),'moduleSha256':sha(a.module),'goSha256':sha(a.go),'phpSha256':sha(a.php),'affinity':sorted(os.sched_getaffinity(0)),'kernel':os.uname().release,'scope':'HTTP/1 loopback, buffered dynamic JSON, immutable payload cache; not TLS/endurance','phpOpcache':True,'phpJit':'tracing','goGOMAXPROCS':len(os.sched_getaffinity(0))}
identity['backend']=os.environ.get('ASYNCARA_BACKEND','epoll')
identity['workloadSha256']={str(f.relative_to(root)):sha(f) for f in (root/'benchmarks/server').rglob('*') if f.is_file() and '__pycache__' not in f.parts}
manifest={f:sha(root/f) for f in subprocess.check_output(['git','ls-files'],cwd=root,text=True).splitlines()}
(out/'source-manifest.json').write_text(json.dumps(manifest,indent=2))
(out/'source.patch').write_bytes(subprocess.check_output(['git','diff','HEAD'],cwd=root))
(out/'identity.json').write_text(json.dumps(identity,indent=2))
engines=[('Go-fasthttp',None)]+[('Asyncara-'+n,int(n)) for n in a.workers.split(',')]
rows=[]
for sample in range(a.samples):
 for size in map(int,a.sizes.split(',')):
  for name,workers in engines if sample%2==0 else list(reversed(engines)):
   dest=out/f'{name}-{size}-{sample}';dest.mkdir()
   with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
   env=os.environ|{'GOMAXPROCS':str(len(os.sched_getaffinity(0)))}
   cmd=[a.go,str(port)] if workers is None else [a.php,'-n','-d','opcache.enable_cli=1','-d','opcache.jit=tracing','-d','extension='+a.posix,'-d','extension='+str(pathlib.Path(a.module).resolve()),str(root/'benchmarks/server/app.php'),str(port),str(workers)]
   row={'engine':name,'workers':workers,'size':size,'delayMs':a.delay,'sample':sample,'command':cmd}
   with (dest/'stderr.log').open('w') as stderr:
    child=subprocess.Popen(cmd,cwd=root,env=env,stdout=subprocess.PIPE,stderr=stderr,text=True,start_new_session=True)
    (dest/'launch.json').write_text(json.dumps(row|{'pid':child.pid},indent=2))
    try:
     line=child.stdout.readline();assert line.startswith('READY '),line
     path=f'/work?payload_bytes={size}&id=42&delay_ms={a.delay}'
     conn=http.client.HTTPConnection('127.0.0.1',port,timeout=5);conn.request('GET',path);response=conn.getresponse();body=response.read();conn.close()
     expected=b'{"id":42,"payload":"'+b'x'*size+b'"}';assert response.status==200 and body==expected,(response.status,body[:100])
     script=dest/'validate.lua'
     # wrk runs response() on worker Lua VMs. Aggregate errors explicitly.
     script.write_text('''threads = {}
function setup(thread) table.insert(threads, thread) end
errors = 0
local expected = '{"id":42,"payload":"' .. string.rep('x', SIZE) .. '"}'
function response(status, headers, body)
  if status ~= 200 or body ~= expected then errors = errors + 1 end
end
function done(summary, latency, requests)
  local failed = 0
  for _,thread in ipairs(threads) do failed = failed + thread:get('errors') end
  io.write(string.format('VERIFIED requests=%d validationErrors=%d p50us=%.0f p95us=%.0f p99us=%.0f\\n', summary.requests, failed, latency:percentile(50), latency:percentile(95), latency:percentile(99)))
end
'''.replace('SIZE',str(size)))
     base=['wrk','-t2','-c'+str(a.connections),'--latency','-s',str(script)]
     warm=subprocess.run(base+['-d2s',f'http://127.0.0.1:{port}'+path],capture_output=True,text=True);(dest/'warmup.log').write_text(warm.stdout+warm.stderr);assert warm.returncode==0
     pressureBefore=pathlib.Path('/proc/pressure/cpu').read_text()
     before=snapshot(child.pid);samples=[];started=time.monotonic()
     with (dest/'wrk.log').open('w') as log:
      measured=subprocess.Popen(base+['-d'+str(a.seconds)+'s',f'http://127.0.0.1:{port}'+path],stdout=log,stderr=subprocess.STDOUT)
      (dest/'load-generator.json').write_text(json.dumps({'pid':measured.pid,'command':measured.args}))
      while measured.poll() is None:
       if time.monotonic()-started>a.seconds+20:
        measured.terminate();measured.wait(timeout=5);raise RuntimeError('Load generator deadline exceeded')
       samples.append({'elapsed':time.monotonic()-started,'processes':snapshot(child.pid)})
       time.sleep(.5)
     wall=time.monotonic()-started;after=snapshot(child.pid)
     (dest/'resources.json').write_text(json.dumps({'before':before,'samples':samples,'after':after,'hostCpuPressureBefore':pressureBefore,'hostCpuPressureAfter':pathlib.Path('/proc/pressure/cpu').read_text()},indent=2))
     before_ticks=sum(p['cpuTicks'] for p in before);after_ticks=sum(p['cpuTicks'] for p in after)
     row.update(cpuSeconds=(after_ticks-before_ticks)/os.sysconf('SC_CLK_TCK'),peakRssKiB=sum(p['rssKiB'] for p in after) if not samples else max(sum(p['rssKiB'] for p in x['processes']) for x in samples),maxThreads=max(sum(p['threads'] for p in x['processes']) for x in samples),processes=len(after))
     text=(dest/'wrk.log').read_text()
     assert measured.returncode==0 and 'Socket errors:' not in text and 'Non-2xx' not in text,text
     match=re.search(r'VERIFIED requests=(\d+) validationErrors=(\d+) p50us=(\d+) p95us=(\d+) p99us=(\d+)',text);assert match,text
     requests,errors,p50,p95,p99=map(int,match.groups());assert requests>0 and errors==0,text
     row.update(status='PASS',rps=float(re.search(r'Requests/sec:\s+([\d.]+)',text)[1]),requests=requests,wallSeconds=wall,p50us=p50,p95us=p95,p99us=p99,logSha256=sha(dest/'wrk.log'))
    except BaseException as e:row.update(status='FAIL',error=repr(e))
    finally:
     if child.poll() is None:child.send_signal(signal.SIGTERM)
     try:tail=child.communicate(timeout=40)[0]
     except subprocess.TimeoutExpired:os.killpg(child.pid,signal.SIGKILL);tail=child.communicate()[0];row.update(status='FAIL',shutdown='forced')
     (dest/'stdout.log').write_text(tail);row['serverExit']=child.returncode
     if child.returncode:row['status']='FAIL'
   rows.append(row);(out/'results.json').write_text(json.dumps({'identity':identity,'rows':rows},indent=2)+'\n');print(json.dumps(row),flush=True)
   if row['status']!='PASS':raise SystemExit(1)
medians={f'{name}/{size}':statistics.median(r['rps'] for r in rows if r['engine']==name and r['size']==size) for name,_ in engines for size in map(int,a.sizes.split(','))}
(out/'summary.json').write_text(json.dumps({'status':'PASS','measuredRpsMedians':medians,'rows':len(rows)},indent=2)+'\n');print(json.dumps(medians,indent=2))
