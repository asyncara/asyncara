#!/usr/bin/env python3
"""Focused wait errno regression with an explicit vulnerable negative control."""
import pathlib,subprocess,os,json,resource,argparse
p=argparse.ArgumentParser();p.add_argument('--output',required=True);p.add_argument('--library',required=True);a=p.parse_args()
resource.setrlimit(resource.RLIMIT_CORE,(0,0));r=pathlib.Path(__file__).resolve().parents[2];os.chdir(r);out=pathlib.Path(a.output).resolve();out.mkdir(parents=True,exist_ok=False)
s=(r/'ext/backend.c').read_text().replace('int wait_errno = rc < 0 ? errno : 0;', 'int wait_errno = rc < 0 ? errno : 0; (void)wait_errno;').replace('int saved = rc < 0 ? wait_errno : errno;', 'int saved = errno;');(out/'vulnerable-backend.c').write_text(s)
rows=[]
for label,backend in [('vulnerable',out/'vulnerable-backend.c'),('corrected',r/'ext/backend.c')]:
 cmd=['cc','-std=c11','-D_GNU_SOURCE','-DHAVE_ASYNCARA_HYPER=1','-O2','-g','-Wall','-Wextra','-Werror','-Iext','-I.local/sysroot/usr/include','-I.local/sysroot/usr/include/x86_64-linux-gnu','tests/server/wait_errno.c',str(backend),'ext/workers.c','ext/memory.c',str(pathlib.Path(a.library).resolve()),'-pthread','-l:libcurl.so.4','-l:libsqlite3.so.0','-ldl','-lm','-Wl,--wrap=epoll_wait','-Wl,--wrap=poll','-Wl,--wrap=__poll_chk','-o',str(out/label)]
 built=subprocess.run(cmd,capture_output=True,text=True);(out/(label+'-build.log')).write_text(built.stdout+built.stderr);assert built.returncode==0,built.stderr
 for b in ['epoll','poll']:
  x=subprocess.run([str(out/label)],env=os.environ|{'ASYNCARA_BACKEND':b},capture_output=True,text=True)
  (out/(label+'-'+b+'.log')).write_text(x.stdout+x.stderr);rows.append({'variant':label,'backend':b,'exit':x.returncode,'output':x.stdout+x.stderr})
status='PASS' if all((x['exit']==0)==(x['variant']=='corrected') for x in rows) else 'FAIL'
(out/'report.json').write_text(json.dumps({'status':status,'rows':rows},indent=2));print(json.dumps(rows,indent=2));raise SystemExit(status!='PASS')
