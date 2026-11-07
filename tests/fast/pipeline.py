#!/usr/bin/env python3
"""Bounded fast reliability gates; explicit operation and FPM coverage domains."""
import argparse,hashlib,json,os,pathlib,resource,subprocess,time,shlex,sys
ROOT=pathlib.Path(__file__).resolve().parents[2]
p=argparse.ArgumentParser();p.add_argument('gate',choices=['A','B1','B2','B','C']);p.add_argument('--output',required=True);p.add_argument('--php',required=True);p.add_argument('--extension',required=True);p.add_argument('--seconds',type=int,default=None);p.add_argument('--prior-a');p.add_argument('--prior-b');a=p.parse_args()
out=pathlib.Path(a.output).resolve();out.mkdir(parents=True,exist_ok=False);module=pathlib.Path(a.extension).resolve();start=time.monotonic();rows=[]
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
manifest={str(p.relative_to(ROOT)):sha(p)for d in ['ext','src','tests/fast'] for p in(ROOT/d).rglob('*')if p.is_file() and '__pycache__'not in str(p)}
identity={'head':subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip(),'status':subprocess.check_output(['git','status','--short'],cwd=ROOT,text=True),'module':str(module),'moduleSha256':sha(module),'manifest':manifest,'arguments':vars(a)}
(out/'identity.json').write_text(json.dumps(identity,indent=2)+'\n')
def save(status,extra=None):
 (out/'report.json').write_text(json.dumps({'gate':a.gate,'status':status,'wallSeconds':time.monotonic()-start,'cpuSeconds':sum(r['cpuSeconds']for r in rows),'commands':rows,**(extra or {})},indent=2)+'\n')
def run(name,cmd,env=None,timeout=600):
 before=resource.getrusage(resource.RUSAGE_CHILDREN);t=time.monotonic();log=out/(name+'.log')
 with log.open('xb')as f:
  child=subprocess.Popen(list(map(str,cmd)),cwd=ROOT,env=os.environ|(env or {}),stdout=f,stderr=subprocess.STDOUT)
  (out/'active.json').write_text(json.dumps({'pid':child.pid,'command':list(map(str,cmd)),'source':identity['head'],'output':str(log),'moduleSha256':identity['moduleSha256'],'status':'RUNNING'},indent=2)+'\n')
  try:code=child.wait(timeout=timeout)
  except subprocess.TimeoutExpired:child.terminate();child.wait(timeout=20);code=124
 after=resource.getrusage(resource.RUSAGE_CHILDREN)
 row={'name':name,'command':list(map(str,cmd)),'environment':env or {},'exit':code,'wallSeconds':time.monotonic()-t,'cpuSeconds':after.ru_utime+after.ru_stime-before.ru_utime-before.ru_stime,'log':str(log),'sha256':sha(log)};rows.append(row)
 (out/'active.json').write_text(json.dumps({'status':'TERMINAL','exit':code,'pid':child.pid})+'\n');save('RUNNING'if code==0 else'FAIL');print(name,code,flush=True)
 if code:raise RuntimeError(f'{name}: exit {code}; preserved {log}')
 return log
php=[a.php,'-n','-d','extension='+str(module)];cc=shlex.split(os.environ.get('CC','cc'));flags=['-std=c11','-D_GNU_SOURCE','-g','-O2','-Wall','-Wextra','-Werror','-Wno-unused-parameter','-I.local/sysroot/usr/include','-I.local/sysroot/usr/include/x86_64-linux-gnu'];native=['ext/workers.c','ext/backend.c','ext/memory.c'];libs=['-pthread','-l:libsqlite3.so.0','-l:libcurl.so.4']
try:
 if a.gate=='A':run('A',['python3','tests/fast/gate_a.py','--php',a.php,'--extension',module,'--output',out/'A'])
 if a.gate in ['B1','B']:
  run('build-native',cc+flags+['tests/fast/native_wait.c']+native+libs+['-Wl,--wrap=write','-Wl,--wrap=poll','-Wl,--wrap=__poll_chk','-Wl,--wrap=epoll_wait','-o',out/'native-wait'])
  log=run('native-25000',[out/'native-wait',25000,'all']);native_rows=[json.loads(line)for line in log.read_text().splitlines()]
  count=sum(r['realKernelWaitCalls']for r in native_rows);assert count>=200000,'Native wait volume gate'
  for backend in ['epoll','poll']:
   for seed in [17,271828,104729]:run(f'{backend}-kernel-{seed}',php+['tests/fast/kernel.php',10000,seed],{'ASYNCARA_BACKEND':backend})
   run(f'{backend}-resources',php+['tests/fast/resources.php',15000],{'ASYNCARA_BACKEND':backend})
  (out/'native-counts.json').write_text(json.dumps({'actualKernelWaitCalls':count,'backends':native_rows,'definition':'Actual production ar_wait kernel syscalls after changing completion queue, socket readiness, partial reads, descriptor reuse or HUP. Injected syscall failures and virtual clocks excluded.'},indent=2)+'\n')
 if a.gate=='C':
  if not a.prior_a or not a.prior_b:raise ValueError('Gate C requires completed A and B evidence')
  for prior in [a.prior_a,a.prior_b]:
   prior=pathlib.Path(prior);assert json.loads((prior/'report.json').read_text())['status']=='PASS'
   old=json.loads((prior/'identity.json').read_text());assert old['moduleSha256']==identity['moduleSha256'],'C prerequisite binary differs'
   assert all(old['manifest'].get(k)==v for k,v in manifest.items()if k.startswith(('ext/','src/'))),'C prerequisite source differs'
  for backend in ['epoll','poll']:
   for seed in [65537,999983]:run(f'{backend}-extended-{seed}',php+['tests/fast/kernel.php',25000,seed],{'ASYNCARA_BACKEND':backend})
   run(f'{backend}-resources',php+['tests/fast/resources.php',30000],{'ASYNCARA_BACKEND':backend})
 if a.gate in ['B2','B','C']:
  seconds=a.seconds if a.seconds is not None else 1800 if a.gate=='C'else 600
  run('fpm',['python3','tests/fast/fpm_integration.py','--seconds',seconds,'--output',out/'fpm'],{'ASYNCARA_EXTENSION':str(module)},timeout=seconds+180)
  run('independent-review',['python3','tests/fast/review_integration.py',out/'fpm','--output',out/'review'])
 # No mutation of source or module permitted within a gate.
 assert sha(module)==identity['moduleSha256']
 assert all(sha(ROOT/k)==v for k,v in manifest.items()),'Gate source changed during execution'
 save('PASS');print(json.dumps({'gate':a.gate,'status':'PASS','wallSeconds':time.monotonic()-start}))
except BaseException as e:save('FAIL',{'error':repr(e)});raise
