#!/usr/bin/env python3
"""Bounded trace regressions; no FPM or historical campaign mutation."""
import argparse, hashlib, json, os, pathlib, subprocess, time
p=argparse.ArgumentParser();p.add_argument('--php',required=True);p.add_argument('--extension',required=True);p.add_argument('--output',required=True);a=p.parse_args()
root=pathlib.Path(__file__).resolve().parents[2];out=pathlib.Path(a.output).resolve();out.mkdir(parents=True,exist_ok=False)
module=(root/a.extension).resolve();rows=[];start=time.monotonic()
def sha(path):return hashlib.sha256(path.read_bytes()).hexdigest()
(out/'identity.json').write_text(json.dumps({'head':subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip(),'module':str(module),'moduleSha256':sha(module),'manifest':{str(f.relative_to(root)):sha(f)for d in ['ext','src','tests/diagnostics']for f in (root/d).rglob('*')if f.is_file()}},indent=2)+'\n')
def run(name,cmd,env=None):
 t=time.monotonic();log=out/(name+'.log')
 with log.open('xb')as f:
  r=subprocess.run(list(map(str,cmd)),cwd=root,env=os.environ|(env or {}),stdout=f,stderr=subprocess.STDOUT,timeout=60)
 rows.append({'name':name,'command':list(map(str,cmd)),'environment':env,'exit':r.returncode,'seconds':time.monotonic()-t,'log':str(log),'sha256':sha(log)})
 (out/'report.json').write_text(json.dumps({'status':'RUNNING'if r.returncode==0 else'FAIL','commands':rows},indent=2)+'\n')
 print(name,r.returncode,flush=True)
 if r.returncode:raise SystemExit(1)
flags=['cc','-std=c11','-D_GNU_SOURCE','-O2','-g','-Wall','-Wextra','-Werror','-Wno-unused-parameter','-I.local/sysroot/usr/include','-I.local/sysroot/usr/include/x86_64-linux-gnu']
native=['ext/workers.c','ext/backend.c','ext/memory.c'];libs=['-pthread','-l:libsqlite3.so.0','-l:libcurl.so.4']
run('ring-build',flags+['tests/diagnostics/ring.c']+native+libs+['-Wl,--wrap=calloc','-Wl,--wrap=clock_gettime','-o',out/'ring-bin'])
run('ring',[out/'ring-bin'])
run('wait-build',flags+['tests/fast/native_wait.c']+native+libs+['-Wl,--wrap='+w for w in ['write','poll','__poll_chk','epoll_wait']]+['-o',out/'wait-bin'])
(out/'native-traces').mkdir()
run('wait',[out/'wait-bin','8','all'],{'ASYNCARA_TRACE_TEST':str(out/'native-traces')})
for backend in ['epoll','poll']:
 for mode in ['disabled','normal','wrap','maximum']:
  run(backend+'-'+mode,[a.php,'-n','-d','extension='+str(module),'tests/diagnostics/trace.php',mode],{'ASYNCARA_BACKEND':backend})
(out/'report.json').write_text(json.dumps({'status':'PASS','seconds':time.monotonic()-start,'commands':rows},indent=2)+'\n')
