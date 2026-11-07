#!/usr/bin/env python3
"""Isolated baseline/candidate/corrected comparison. Never install or run FPM."""
import argparse,hashlib,json,os,pathlib,resource,subprocess,time,shutil
ROOT=pathlib.Path(__file__).resolve().parents[2]
PHP={'83':(str(ROOT/'.local/php83/usr/bin/php8.3'),ROOT/'.local/sdk83'),
 '84':(str(ROOT/'.local/php84/usr/bin/php8.4'),ROOT/'.local/sdk84'),
 '85':(str(ROOT/'.local/php85/usr/bin/php8.5'),ROOT/'.local/sysroot/usr/include/php/20250925')}
for version,(binary,include) in list(PHP.items()):
 PHP[version]=(os.environ.get('PHP'+version+'_BIN',binary if pathlib.Path(binary).exists()else shutil.which('php'+version[0]+'.'+version[1])or'php'),pathlib.Path(os.environ.get('PHP'+version+'_INCLUDE_DIR',str(include))))
p=argparse.ArgumentParser();p.add_argument('--output',required=True);p.add_argument('--variants',nargs='+',choices=['baseline','candidate','corrected'],default=['baseline','candidate']);p.add_argument('--php',nargs='+',choices=list(PHP),default=['85']);p.add_argument('--iterations',type=int,default=10000);p.add_argument('--seed',type=int,default=17);a=p.parse_args()
out=pathlib.Path(a.output).resolve();out.mkdir(parents=True,exist_ok=False)
rows=[]
def digest(path):return hashlib.sha256(path.read_bytes()).hexdigest()
def run(name,command,environment=None,expected=0):
 start=time.monotonic();cpu=resource.getrusage(resource.RUSAGE_CHILDREN);log=out/(name+'.log')
 with log.open('xb')as stream:
  try:r=subprocess.run(list(map(str,command)),cwd=ROOT,env=os.environ|(environment or {}),stdout=stream,stderr=subprocess.STDOUT,timeout=300);code=r.returncode
  except subprocess.TimeoutExpired:code=124
 after=resource.getrusage(resource.RUSAGE_CHILDREN)
 row={'name':name,'command':list(map(str,command)),'environment':environment,'exit':code,'expectedExit':expected,'expectationMet':code==expected,'wallSeconds':time.monotonic()-start,'cpuSeconds':after.ru_utime+after.ru_stime-cpu.ru_utime-cpu.ru_stime,'log':str(log),'sha256':digest(log)};rows.append(row);(out/'results.json').write_text(json.dumps(rows,indent=2)+'\n');print(json.dumps({k:row[k]for k in ['name','exit','expectationMet','wallSeconds']}),flush=True);return code
manifest={str(p.relative_to(ROOT)):digest(p)for directory in ['ext','src','tests/fast','tests/audit']for p in (ROOT/directory).rglob('*')if p.is_file()}
(out/'source.json').write_text(json.dumps({'head':subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip(),'status':subprocess.check_output(['git','status','--short'],cwd=ROOT,text=True),'manifest':manifest,'iterations':a.iterations,'seed':a.seed},indent=2)+'\n')
common=['cc','-std=c11','-D_GNU_SOURCE','-g','-O2','-Wall','-Wextra','-Werror','-Wno-unused-parameter','-I.local/sysroot/usr/include','-I.local/sysroot/usr/include/x86_64-linux-gnu']
libs=['-pthread','-l:libsqlite3.so.0','-l:libcurl.so.4']
for variant in a.variants:
 ext=ROOT/'ext'if variant=='corrected'else ROOT/'build'/variant/'ext'
 native=[ext/'workers.c',ext/'backend.c',ext/'memory.c']
 binary=out/(variant+'-wait')
 if run(variant+'-wait-build',common+[ROOT/'tests/fast/native_wait.c',*native,*libs,'-Wl,--wrap=write','-Wl,--wrap=poll','-Wl,--wrap=__poll_chk','-Wl,--wrap=epoll_wait','-o',binary])==0:
  run(variant+'-wait-kernel',[binary,a.iterations,'kernel'])
  for backend in [0,1]:run(variant+'-wait-wakeup-'+str(backend),[binary,a.iterations,'wakeup',backend],expected=0 if variant=='corrected'else 2)
 binary=out/(variant+'-close')
 if run(variant+'-close-build',common+[ROOT/'tests/audit/close_cancellation.c',*native,*libs,'-Wl,--wrap=pthread_create','-o',binary])==0:
  run(variant+'-close-file',[binary,'file'],expected=-6 if variant=='baseline'else 0)
  run(variant+'-close-sqlite',[binary,'sqlite'],expected=-6 if variant=='baseline'else 0)
  run(variant+'-close-control',[binary,'control'])
 for version in a.php:
  php,inc=PHP[version];module=out/(variant+'-'+version+'.so')
  cmd=common+['-fPIC','-shared','-DCOMPILE_DL_ASYNCARA=1']+['-I'+str(inc/x)for x in ['','main','Zend','TSRM','ext']]+[ext/'php_asyncara.c',*native,*libs,'-o',module]
  if run(variant+'-'+version+'-build',cmd):continue
  (out/(module.name+'.sha256')).write_text(digest(module)+'\n')
  run(variant+'-'+version+'-info',[php,'-n','-d','extension='+str(module),'--ri','asyncara'])
  for backend in ['epoll','poll']:
   env={'ASYNCARA_BACKEND':backend}
   for test in ['kernel','virtual_time','sqlite_encoding']:
    run(variant+'-'+version+'-'+backend+'-'+test,[php,'-n','-d','extension='+str(module),'tests/fast/'+test+'.php',a.iterations,a.seed],env)
   run(variant+'-'+version+'-'+backend+'-negative-oracle',[php,'-n','-d','extension='+str(module),'tests/fast/kernel.php',1,a.seed,'--negative-early-io'],env,expected=1)
print('Recorded',len(rows),'commands; expected historical failures remain failures, not passing implementations.')
raise SystemExit(0 if all(x['expectationMet']for x in rows)else 1)
