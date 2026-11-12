#!/usr/bin/env python3
"""Isolated, independently labelled MySQL/MariaDB client certificate fixtures."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import secrets
import socket
import subprocess
import tempfile
import time

p = argparse.ArgumentParser()
p.add_argument('--php', required=True)
p.add_argument('--module', type=Path, required=True)
p.add_argument('--backend', choices=['epoll', 'poll'], required=True)
p.add_argument('--out', type=Path, required=True)
p.add_argument('--oracle-prefix', type=Path)
p.add_argument('--fpm', type=Path, help='Additional unchanged audit runner, mTLS request fixture')
p.add_argument('--owner-pending', action='store_true', help='Owner termination during independently locked SQL')
p.add_argument('--transactions', action='store_true', help='Manual transaction and savepoint ownership regression')
p.add_argument('--probe', action='store_true', help='Positive connection only, for old-source control')
p.add_argument('--server', action='store_true', help='Native Server SQL/HTTP/filesystem lifecycle integration')
p.add_argument('--paths', nargs='*', choices=['relative','invalid','policy','late-policy','native-policy-ca','native-policy-cert','native-policy-key'], help='Run TLS filename ownership controls instead of authentication cases')
p.add_argument('--mismatch', action='store_true', help='Trusted server certificate with incorrect IP SAN')
a = p.parse_args()
if a.owner_pending and (a.transactions or a.probe or a.server or a.fpm or a.paths is not None):p.error('--owner-pending is a separate run')
if a.transactions and (a.probe or a.server or a.fpm or a.paths is not None):p.error('--transactions is a separate run')
if a.fpm and (a.probe or a.mismatch): p.error('--fpm requires the full positive certificate fixture')
if a.paths is not None and (a.fpm or a.probe or a.mismatch): p.error('--paths is a separate run')
if a.server and (a.fpm or a.probe or a.mismatch or a.paths is not None):p.error('--server is a separate run')
root = Path(__file__).resolve().parents[2]
out = a.out.resolve(); out.mkdir(parents=True, exist_ok=False, mode=0o700)
sha = lambda f: hashlib.sha256(Path(f).read_bytes()).hexdigest()
module = a.module.resolve()
server_bin = str(a.oracle_prefix.resolve()/'usr/sbin/mysqld') if a.oracle_prefix else 'mariadbd'
client_bin = str(a.oracle_prefix.resolve()/'usr/bin/mysql') if a.oracle_prefix else 'mariadb'
manifest = {str(f.relative_to(root)): sha(f) for d in ['ext', 'src/Asyncara/MySQL', 'src/Asyncara/Internal', 'tests/database']
            for f in (root/d).rglob('*') if f.is_file() and '__pycache__' not in str(f)}
report = {'status':'RUNNING', 'backend':a.backend, 'sourceManifest':manifest,
          'moduleSha256':sha(module), 'phpSha256':sha(a.php), 'identityMismatch':a.mismatch,
          'server':subprocess.check_output([server_bin,'--version'],text=True).strip(),
          'family':'Oracle MySQL' if a.oracle_prefix else 'MariaDB'}
server = None; child = None; sockets = None; start = time.monotonic()
def run(command, name, *, input=None, cwd=None, env=None, timeout=40):
    with (out/(name+'.log')).open('a') as log:
        return subprocess.run(command, input=input, text=True, cwd=cwd, env=env, stdin=None if input is not None else subprocess.DEVNULL,
                              stdout=log, stderr=log, check=True, timeout=timeout)
try:
    certs=out/'certs';certs.mkdir(mode=0o700)
    def openssl(*args, env=None): run(['openssl',*map(str,args)],'certificates',cwd=certs,env=env)
    for ca in ['ca','untrusted-ca']:
        openssl('req','-x509','-newkey','ec','-pkeyopt','ec_paramgen_curve:P-256','-nodes','-days','2','-subj','/CN='+ca,'-keyout',ca+'.key','-out',ca+'.pem','-addext','basicConstraints=critical,CA:TRUE')
    for name,cn,ca,purpose in [('server','localhost','ca','serverAuth'),('mismatch','wrong.invalid','ca','serverAuth'),('client','localhost','ca','clientAuth'),('other','other-client','ca','clientAuth'),('untrusted','localhost','untrusted-ca','clientAuth')]:
        openssl('req','-new','-newkey','ec','-pkeyopt','ec_paramgen_curve:P-256','-nodes','-subj','/CN='+cn,'-keyout',name+'.key','-out',name+'.csr')
        ext='extendedKeyUsage='+purpose+'\n'
        if name=='server': ext+='subjectAltName=DNS:localhost,IP:127.0.0.1\n'
        if name=='mismatch': ext+='subjectAltName=DNS:wrong.invalid\n'
        (certs/(name+'.ext')).write_text(ext)
        openssl('x509','-req','-days','2','-in',name+'.csr','-CA',ca+'.pem','-CAkey',ca+'.key','-CAcreateserial','-extfile',name+'.ext','-out',name+'.pem')
    secret=secrets.token_urlsafe(32)
    (certs/'passphrase').write_text(secret);(certs/'passphrase').chmod(0o600)
    openssl('pkey','-in','client.key','-aes-256-cbc','-passout','env:ASYNCARA_TEST_KEY_PASSPHRASE','-out','encrypted.key',env=os.environ|{'ASYNCARA_TEST_KEY_PASSPHRASE':secret})
    del secret
    for f in certs.glob('*.key'): f.chmod(0o600)
    if a.oracle_prefix:
        run([server_bin,'--no-defaults','--initialize-insecure','--basedir='+str(a.oracle_prefix.resolve()/'usr'),'--datadir='+str(out/'data')],'init',timeout=60)
    else:
        run(['mariadb-install-db','--no-defaults','--datadir='+str(out/'data'),'--auth-root-authentication-method=normal','--skip-test-db'],'init')
    sockets=tempfile.TemporaryDirectory(prefix='asyncara-my-mtls-',dir='/tmp');sock=Path(sockets.name)/'db.sock'
    report['observerSocket']=str(sock)
    with socket.socket() as reserve:
        reserve.bind(('127.0.0.1',0));port=reserve.getsockname()[1]
    name='mismatch' if a.mismatch else 'server'
    command=[server_bin,'--no-defaults','--datadir='+str(out/'data'),'--socket='+str(sock),'--pid-file='+str(out/'server.pid'),
        '--bind-address=127.0.0.1','--port='+str(port),'--skip-name-resolve','--ssl-ca='+str(certs/'ca.pem'),
        '--ssl-cert='+str(certs/(name+'.pem')),'--ssl-key='+str(certs/(name+'.key')),'--require-secure-transport=ON']
    if a.oracle_prefix:command+=['--basedir='+str(a.oracle_prefix.resolve()/'usr'),'--mysqlx=OFF','--secure-file-priv=NULL']
    with (out/'server.log').open('x') as log:server=subprocess.Popen(command,stdout=log,stderr=log)
    admin=[client_bin,'--no-defaults','--socket='+str(sock),'-uroot','--batch','--skip-column-names']
    deadline=time.monotonic()+30
    while subprocess.run(admin+['-e','SELECT 1'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL).returncode:
        if server.poll() is not None:raise RuntimeError('Private SQL startup failed')
        if time.monotonic()>deadline:raise TimeoutError('Private SQL readiness')
        time.sleep(.025)
    password=secrets.token_hex(24)
    sql="CREATE DATABASE asyncara; CREATE USER 'asyncara'@'127.0.0.1' IDENTIFIED BY '"+password+"' REQUIRE SUBJECT '/CN=localhost' AND ISSUER '/CN=ca'; GRANT ALL ON asyncara.* TO 'asyncara'@'127.0.0.1'; SELECT user,plugin,ssl_type,x509_issuer,x509_subject FROM mysql.user WHERE user='asyncara';\n"
    run(admin,'account',input=sql)
    opts={'host':'127.0.0.1','port':port,'user':'asyncara','password':password,'database':'asyncara',
          'caFile':str(certs/'ca.pem'),'certFile':str(certs/'client.pem'),'keyFile':str(certs/'client.key')}
    fixture={'options':opts,'passphraseFile':str(certs/'passphrase'),'encryptedKey':str(certs/'encrypted.key'),
        'untrustedCert':str(certs/'untrusted.pem'),'untrustedKey':str(certs/'untrusted.key'),'untrustedCa':str(certs/'untrusted-ca.pem'),
        'otherCert':str(certs/'other.pem'),'otherKey':str(certs/'other.key'),'missingPath':str(certs/'absent.pem'),
        'serverIdentityValid':not a.mismatch,'family':report['family']}
    (out/'fixture.json').write_text(json.dumps(fixture));(out/'fixture.json').chmod(0o600)
    (out/'options.json').write_text(json.dumps(opts));(out/'options.json').chmod(0o600)
    cmd=[a.php,'-n','-d','extension='+str(module),'-d','zend.exception_ignore_args=0',str(root/'tests/database/mtls_mysql.php')]+(['probe']if a.probe else[])
    if a.paths is not None:
        cmd=['python3',str(root/'tests/database/sql_tls_paths.py'),'--php',a.php,'--module',str(module),'--out',str(out/'paths')]+(['--modes',*a.paths] if a.paths else [])
    if a.server:cmd=['python3',str(root/'tests/database/native_server.py'),'--php',a.php,'--module',str(module),'--out',str(out/'native-server')]
    if a.transactions:cmd=[a.php,'-n','-d','extension='+str(module),str(root/'tests/database/transactions.php')]
    if a.owner_pending:cmd=[a.php,'-n','-d','extension='+str(module),str(root/'tests/database/transaction_owner_pending.php')]
    env=os.environ|{'ASYNCARA_BACKEND':a.backend,'ASYNCARA_MY_MTLS_FIXTURE':str(out/'fixture.json')}
    with (out/'php.log').open('x')as log:
        child=subprocess.Popen(cmd,cwd=root,env=env,stdin=subprocess.DEVNULL,stdout=log,stderr=log)
        (out/'active.json').write_text(json.dumps({'serverPid':server.pid,'childPid':child.pid,'command':cmd}))
        code=child.wait(timeout=90)
    report.update(status='PASS'if code==0 else'FAIL',exit=code,command=cmd)
    if 'Enter PEM pass phrase' in (out/'php.log').read_text():raise AssertionError('Interactive key prompt reached interpreter')
    if code==0:
        if a.fpm:
            cmd=['python3',str(root/'tests/database/mtls_mysql_fpm.py'),'--fpm',str(a.fpm.resolve()),
                '--module',str(module),'--backend',a.backend,'--options',str(out/'options.json'),
                '--observer-client',client_bin,'--observer-socket',str(sock),'--out',str(out/'fpm')]
            with (out/'fpm.log').open('x') as log:
                child=subprocess.Popen(cmd,cwd=root,env=env,stdout=log,stderr=log)
                (out/'active.json').write_text(json.dumps({'serverPid':server.pid,'childPid':child.pid,'command':cmd}))
                fpm_exit=child.wait()
            report['fpm']={'exit':fpm_exit,'command':cmd,'fpmSha256':sha(a.fpm)}
            if fpm_exit:raise RuntimeError('mTLS FPM lifecycle failed')
        deadline=time.monotonic()+5
        while True:
            left=subprocess.check_output(admin+['-e',"SELECT count(*) FROM information_schema.processlist WHERE USER='asyncara'"],text=True,timeout=5).strip()
            if left=='0':break
            if time.monotonic()>deadline:raise TimeoutError('Client session leaked on server')
            time.sleep(.01)
        report['remainingSessions']=0
    assert all(sha(root/name)==digest for name,digest in manifest.items()),'Source changed during test'
except BaseException as exc:report.update(status='FAIL',error=repr(exc))
finally:
    for proc in [child,server]:
        if proc is not None:
            if proc.poll() is None:proc.terminate()
            try:proc.wait(timeout=20)
            except subprocess.TimeoutExpired:proc.kill();proc.wait();report.update(status='FAIL',cleanupTimeout=True)
    if server is not None:report['serverExit']=server.returncode
    if sockets is not None:sockets.cleanup()
    report['seconds']=time.monotonic()-start
    report['logHashes']={f.name:sha(f)for f in out.glob('*.log')}
    (out/'report.json').write_text(json.dumps(report,indent=2)+'\n');(out/'active.json').write_text('{"status":"TERMINAL"}')
print(json.dumps({k:v for k,v in report.items()if k!='sourceManifest'},indent=2))
raise SystemExit(0 if report['status']=='PASS'else 1)
