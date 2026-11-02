#!/usr/bin/env python3
"""Start only isolated project-owned database fixtures; never touch host services."""
import argparse, os, pathlib, socket, subprocess, sys, time
parser=argparse.ArgumentParser()
parser.add_argument('--valgrind',action='store_true')
parser.add_argument('--test-file',default='tests/databases.php')
parser.add_argument('--timeout',type=float,default=180,help='Child deadline in seconds, including long soak workloads')
options=parser.parse_args()
root=pathlib.Path(__file__).resolve().parent.parent
os.chdir(root)
subprocess.run([sys.executable,'scripts/check-build.py',os.environ.get('ASYNCARA_EXTENSION','build/asyncara.so')],check=True)
pg=root/'build/postgresql';my=root/'build/mariadb';rd=root/'build/redis'
for d in (pg,my,rd):d.mkdir(parents=True,exist_ok=True)
pgbin=pathlib.Path(os.environ.get('POSTGRES_BIN','/usr/lib/postgresql/16/bin'))
if not (pg/'data/PG_VERSION').exists():
 with (pg/'init.log').open('w') as log:subprocess.run([str(pgbin/'initdb'),'-D',str(pg/'data'),'-A','trust','--no-locale'],stdout=log,stderr=log,check=True)
if not (my/'data/mysql').exists():
 with (my/'init.log').open('w') as log:subprocess.run(['mariadb-install-db','--no-defaults','--datadir='+str(my/'data'),'--auth-root-authentication-method=normal','--skip-test-db'],stdout=log,stderr=log,check=True)
processes=[];pg_started=False
try:
 subprocess.run([str(pgbin/'pg_ctl'),'-D',str(pg/'data'),'-o',f"-k {pg} -h '' -p 55499",'-l',str(pg/'server.log'),'-w','start'],check=True,stdout=subprocess.DEVNULL);pg_started=True
 mp=subprocess.Popen(['mariadbd','--no-defaults','--datadir='+str(my/'data'),'--socket='+str(my/'mysql.sock'),'--pid-file='+str(my/'server.pid'),'--skip-networking','--skip-ssl','--log-error='+str(my/'server.log')],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL);processes.append(mp)
 with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
 redis_log=(rd/'server.log').open('w')
 rp=subprocess.Popen(['redis-server','--bind','127.0.0.1','--port',str(port),'--save','','--appendonly','no','--dir',str(rd)],stdout=redis_log,stderr=redis_log);processes.append(rp)
 deadline=time.monotonic()+20
 while time.monotonic()<deadline:
  if mp.poll() is not None or rp.poll() is not None:raise RuntimeError('Database startup failed; inspect build/*/server.log')
  if (my/'mysql.sock').exists():
   try:
    with socket.create_connection(('127.0.0.1',port),timeout=.1):break
   except OSError:pass
  time.sleep(.05)
 else:raise RuntimeError('Database fixture startup timeout')
 env=os.environ|{'ASYNCARA_PG_SOCKET':str(pg),'ASYNCARA_MY_SOCKET':str(my/'mysql.sock'),'ASYNCARA_REDIS_PORT':str(port)}
 env['ASYNCARA_AUDIT_EXTENSION']=str(root/os.environ.get('ASYNCARA_EXTENSION','build/asyncara.so'))
 command=[os.environ.get('PHP_BIN','php'),'-n','-d','extension='+env['ASYNCARA_AUDIT_EXTENSION'],options.test_file]
 if options.valgrind:
  env|={'USE_ZEND_ALLOC':'0','VALGRIND_LIB':str(root/'.local/sysroot/usr/libexec/valgrind')}
  command=[str(root/'.local/sysroot/usr/bin/valgrind'),'--extra-debuginfo-path='+str(root/'.local/debug'),'--keep-debuginfo=yes','--leak-check=full','--show-leak-kinds=definite','--errors-for-leak-kinds=definite','--error-exitcode=99','--log-file=build/valgrind-databases.log']+command
 result=subprocess.run(command,env=env,timeout=options.timeout)
finally:
 for p in processes:
  if p.poll() is None:p.terminate()
 for p in processes:
  try:p.wait(timeout=20)
  except subprocess.TimeoutExpired:p.kill();p.wait()
 if pg_started:subprocess.run([str(pgbin/'pg_ctl'),'-D',str(pg/'data'),'-m','fast','-w','stop'],check=True,stdout=subprocess.DEVNULL)
raise SystemExit(result.returncode)
