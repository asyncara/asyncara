#!/usr/bin/env python3
"""Developer gate: own binaries/logs; real kernel tests + separate virtual time."""
import argparse,hashlib,json,os,pathlib,resource,shlex,subprocess,time
root=pathlib.Path(__file__).resolve().parents[2]
p=argparse.ArgumentParser();p.add_argument('--output',required=True);p.add_argument('--php',default=os.environ.get('PHP_BIN','php'));p.add_argument('--extension',default=os.environ.get('ASYNCARA_EXTENSION','build/asyncara.so'));p.add_argument('--iterations',type=int,default=10000);p.add_argument('--seeds',type=int,nargs='+',default=[17,271828,104729]);a=p.parse_args()
if a.iterations<1:p.error('positive iteration count required')
out=pathlib.Path(a.output).resolve();out.mkdir(parents=True,exist_ok=False)
module=pathlib.Path(a.extension);module=(root/module).resolve();start=time.monotonic();rows=[]
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
manifest={str(p.relative_to(root)):sha(p)for d in ['ext','src','tests/fast']for p in (root/d).rglob('*')if p.is_file()}
(out/'identity.json').write_text(json.dumps({'source':subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip(),'status':subprocess.check_output(['git','status','--short'],cwd=root,text=True),'manifest':manifest,'module':str(module),'moduleSha256':sha(module),'arguments':vars(a)},indent=2)+'\n')
def run(name,cmd,env=None,expected=0):
 before=resource.getrusage(resource.RUSAGE_CHILDREN);t=time.monotonic();log=out/(name+'.log')
 with log.open('xb')as f:
  try:r=subprocess.run(list(map(str,cmd)),cwd=root,env=os.environ|(env or {}),stdout=f,stderr=subprocess.STDOUT,timeout=300);code=r.returncode
  except subprocess.TimeoutExpired:code=124
 after=resource.getrusage(resource.RUSAGE_CHILDREN)
 row={'name':name,'command':list(map(str,cmd)),'environment':env,'exit':code,'expectedExit':expected,'wallSeconds':time.monotonic()-t,'cpuSeconds':after.ru_utime+after.ru_stime-before.ru_utime-before.ru_stime,'log':str(log),'sha256':sha(log)};rows.append(row)
 report={'status':'RUNNING'if code==expected else'FAIL','wallSeconds':time.monotonic()-start,'commands':rows}
 (out/'report.json').write_text(json.dumps(report,indent=2)+'\n');print(name,code,flush=True)
 if code!=expected:raise SystemExit(1)
cc=shlex.split(os.environ.get('CC','cc'));flags=['-std=c11','-D_GNU_SOURCE','-g','-O2','-Wall','-Wextra','-Werror','-Wno-unused-parameter','-I.local/sysroot/usr/include','-I.local/sysroot/usr/include/x86_64-linux-gnu'];native=['ext/workers.c','ext/backend.c','ext/memory.c'];libs=['-pthread','-l:libsqlite3.so.0','-l:libcurl.so.4']
run('build-native-wait',cc+flags+['tests/fast/native_wait.c']+native+libs+['-Wl,--wrap=write','-Wl,--wrap=poll','-Wl,--wrap=__poll_chk','-Wl,--wrap=epoll_wait','-o',out/'native-wait'])
run('native-wait',[out/'native-wait',a.iterations,'all'])
run('build-native-close',cc+flags+['tests/audit/close_cancellation.c']+native+libs+['-Wl,--wrap=pthread_create','-o',out/'native-close'])
run('native-close',[out/'native-close','all'])
run('build-wait-budget',cc+flags+['tests/fast/wait_budget.c']+native+libs+['-Wl,--wrap=clock_gettime','-Wl,--wrap=calloc','-Wl,--wrap=epoll_ctl','-Wl,--wrap=epoll_wait','-Wl,--wrap=poll','-Wl,--wrap=__poll_chk','-o',out/'wait-budget'])
run('wait-budget',[out/'wait-budget',a.iterations])
run('build-sqlite-conversion',cc+flags+['tests/fast/sqlite_conversion.c']+native+libs+['-Wl,--wrap='+s for s in ['sqlite3_column_name','sqlite3_column_blob','sqlite3_column_text','sqlite3_column_bytes','sqlite3_errcode']]+['-o',out/'sqlite-conversion'])
for mode in range(5):run('sqlite-conversion-'+str(mode),[out/'sqlite-conversion',mode])
php=[a.php,'-n','-d','extension='+str(module)]
run('php-identity',php+['-v'])
run('extension-identity',php+['--ri','asyncara'])
for backend in ['epoll','poll']:
 env={'ASYNCARA_BACKEND':backend}
 for seed in a.seeds:
  for test in ['kernel','virtual_time']:
   run(f'{backend}-{test}-{seed}',php+[f'tests/fast/{test}.php',a.iterations,seed],env)
 run(f'{backend}-negative-oracle',php+['tests/fast/kernel.php',1,a.seeds[0],'--negative-early-io'],env,expected=1)
 for test in ['deadlines','resources','sqlite_encoding']:
  run(f'{backend}-{test}',php+[f'tests/fast/{test}.php',a.iterations],env)
 run(f'{backend}-buffered-readiness',php+['tests/stream_readiness.php'],env)
report={'status':'PASS','wallSeconds':time.monotonic()-start,'cpuSeconds':sum(r['cpuSeconds']for r in rows),'iterationsPerPath':a.iterations,'seeds':a.seeds,'commands':rows}
(out/'report.json').write_text(json.dumps(report,indent=2)+'\n');print(json.dumps({k:v for k,v in report.items()if k!='commands'}),flush=True)
