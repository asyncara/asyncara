#!/usr/bin/env python3
"""Start only isolated project-owned database fixtures; never touch host services."""
import argparse, json, os, pathlib, shlex, socket, subprocess, sys, tempfile, time
parser=argparse.ArgumentParser()
parser.add_argument('--valgrind',action='store_true')
parser.add_argument('--test-file',default='tests/databases.php')
parser.add_argument('--timeout',type=float,default=180,help='Child deadline in seconds, including long soak workloads')
parser.add_argument('--oracle-prefix',type=pathlib.Path,help='Optional isolated Oracle package root, tested alongside MariaDB')
parser.add_argument('--output',type=pathlib.Path,help='New private fixture/log directory; must not exist')
options=parser.parse_args()
root=pathlib.Path(__file__).resolve().parent.parent
os.chdir(root)
subprocess.run([sys.executable,'scripts/check-build.py',os.environ.get('ASYNCARA_EXTENSION','build/asyncara.so')],check=True)
fixture_root=options.output.resolve() if options.output else root/'build'
if options.output:fixture_root.mkdir(parents=True,exist_ok=False,mode=0o700)
pg=fixture_root/'postgresql';my=fixture_root/'mariadb';rd=fixture_root/'redis'
for d in (pg,my,rd):d.mkdir(parents=True,exist_ok=True,mode=0o700)
pgbin=pathlib.Path(os.environ.get('POSTGRES_BIN','/usr/lib/postgresql/16/bin'))
if not (pg/'data/PG_VERSION').exists():
 with (pg/'init.log').open('w') as log:subprocess.run([str(pgbin/'initdb'),'-D',str(pg/'data'),'-A','trust','--no-locale'],stdout=log,stderr=log,check=True)
if not (my/'data/mysql').exists():
 with (my/'init.log').open('w') as log:subprocess.run(['mariadb-install-db','--no-defaults','--datadir='+str(my/'data'),'--auth-root-authentication-method=normal','--skip-test-db'],stdout=log,stderr=log,check=True)
oracle=fixture_root/'oracle';oracle_server=None
if options.oracle_prefix:
 prefix=options.oracle_prefix.resolve();oracle_server=prefix/'usr/sbin/mysqld';oracle.mkdir(parents=True,exist_ok=True,mode=0o700)
 if not oracle_server.is_file():raise RuntimeError('Oracle MySQL binary missing from supplied prefix')
 if not (oracle/'data/auto.cnf').exists():
  with (oracle/'init.log').open('w')as log:subprocess.run([str(oracle_server),'--no-defaults','--initialize-insecure','--basedir='+str(prefix/'usr'),'--datadir='+str(oracle/'data')],stdout=log,stderr=log,check=True)
socket_directory=tempfile.TemporaryDirectory(prefix='asyncara-db-') if options.output else None
socket_root=pathlib.Path(socket_directory.name) if socket_directory else None
pg_socket=socket_root if socket_root else pg
my_socket=(socket_root if socket_root else my)/'mysql.sock'
oracle_socket=(socket_root/'oracle.sock') if socket_root else oracle/'mysql.sock'
if options.output:(fixture_root/'paths.json').write_text(json.dumps({'postgresData':str(pg/'data'),'postgresSocket':str(pg_socket),'mariaSocket':str(my_socket),'oracleSocket':str(oracle_socket)}))
processes=[];pg_started=False;oracle_log=None
try:
 subprocess.run([str(pgbin/'pg_ctl'),'-D',str(pg/'data'),'-o',f"-k {shlex.quote(str(pg_socket))} -h '' -p 55499",'-l',str(pg/'server.log'),'-w','start'],check=True,stdout=subprocess.DEVNULL);pg_started=True
 mp=subprocess.Popen(['mariadbd','--no-defaults','--datadir='+str(my/'data'),'--socket='+str(my_socket),'--pid-file='+str(my/'server.pid'),'--skip-networking','--skip-ssl','--log-error='+str(my/'server.log')],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL);processes.append(mp)
 if oracle_server:
  oracle_log=(oracle/'server.log').open('a')
  op=subprocess.Popen([str(oracle_server),'--no-defaults','--basedir='+str(prefix/'usr'),'--datadir='+str(oracle/'data'),'--socket='+str(oracle_socket),'--pid-file='+str(oracle/'server.pid'),'--skip-networking','--mysqlx=OFF','--secure-file-priv=NULL'],stdout=oracle_log,stderr=oracle_log);processes.append(op)
 with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
 redis_log=(rd/'server.log').open('w')
 rp=subprocess.Popen(['redis-server','--bind','127.0.0.1','--port',str(port),'--save','','--appendonly','no','--dir',str(rd)],stdout=redis_log,stderr=redis_log);processes.append(rp)
 deadline=time.monotonic()+20
 while time.monotonic()<deadline:
  if any(p.poll() is not None for p in processes):raise RuntimeError('Database startup failed; inspect build/*/server.log')
  if my_socket.exists()and(not oracle_server or oracle_socket.exists()):
   try:
    with socket.create_connection(('127.0.0.1',port),timeout=.1):break
   except OSError:pass
  time.sleep(.05)
 else:raise RuntimeError('Database fixture startup timeout')
 env=os.environ|{'ASYNCARA_PG_SOCKET':str(pg_socket),'ASYNCARA_MY_SOCKET':str(my_socket),'ASYNCARA_REDIS_PORT':str(port)}
 if oracle_server:env['ASYNCARA_ORACLE_SOCKET']=str(oracle_socket)
 env['ASYNCARA_AUDIT_EXTENSION']=str(root/os.environ.get('ASYNCARA_EXTENSION','build/asyncara.so'))
 command=[os.environ.get('PHP_BIN','php'),'-n','-d','extension='+env['ASYNCARA_AUDIT_EXTENSION'],options.test_file]
 if options.valgrind:
  env|={'USE_ZEND_ALLOC':'0','VALGRIND_LIB':str(root/'.local/sysroot/usr/libexec/valgrind')}
  command=[str(root/'.local/sysroot/usr/bin/valgrind'),'--extra-debuginfo-path='+str(root/'.local/debug'),'--keep-debuginfo=yes','--leak-check=full','--show-leak-kinds=definite','--errors-for-leak-kinds=definite','--error-exitcode=99','--log-file='+str(fixture_root/'valgrind-databases.log')]+command
 result=subprocess.run(command,env=env,timeout=options.timeout)
 if options.valgrind and result.returncode:
  print((fixture_root/'valgrind-databases.log').read_text(),flush=True)
finally:
 for p in processes:
  if p.poll() is None:p.terminate()
 for p in processes:
  try:p.wait(timeout=20)
  except subprocess.TimeoutExpired:p.kill();p.wait()
 if pg_started:subprocess.run([str(pgbin/'pg_ctl'),'-D',str(pg/'data'),'-m','fast','-w','stop'],check=True,stdout=subprocess.DEVNULL)
 if oracle_log is not None:oracle_log.close()
 if socket_directory is not None:socket_directory.cleanup()
raise SystemExit(result.returncode)
