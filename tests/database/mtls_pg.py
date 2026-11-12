#!/usr/bin/env python3
"""Private PostgreSQL certificate authentication; never modifies installed services."""
import argparse
import getpass
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
p.add_argument('--module', required=True)
p.add_argument('--backend', choices=['epoll', 'poll'], required=True)
p.add_argument('--out', type=Path, required=True)
p.add_argument('--owner-pending', action='store_true', help='Owner termination during independently locked SQL')
p.add_argument('--transactions', action='store_true', help='Manual transaction and savepoint ownership regression')
p.add_argument('--probe', action='store_true')
p.add_argument('--server', action='store_true', help='Native Server SQL/HTTP/filesystem lifecycle integration')
p.add_argument('--paths', nargs='*', choices=['relative','invalid','policy','late-policy','native-policy-ca','native-policy-cert','native-policy-key'], help='Run TLS filename ownership controls instead of authentication cases')
p.add_argument('--fpm', type=Path, help='Additional existing one/four-worker prepared-query lifecycle fixture')
a = p.parse_args()
if a.owner_pending and (a.transactions or a.probe or a.server or a.fpm or a.paths is not None):p.error('--owner-pending is a separate run')
if a.transactions and (a.probe or a.server or a.fpm or a.paths is not None):p.error('--transactions is a separate run')
if a.fpm and a.probe: p.error('--fpm and --probe are separate runs')
if a.paths is not None and (a.fpm or a.probe): p.error('--paths is a separate run')
if a.server and (a.fpm or a.probe or a.paths is not None):p.error('--server is a separate run')
root = Path(__file__).resolve().parents[2]
out = a.out.resolve(); out.mkdir(parents=True, exist_ok=False, mode=0o700)
pg = Path(os.environ.get('POSTGRES_BIN', '/usr/lib/postgresql/16/bin'))
sha = lambda f: hashlib.sha256(Path(f).read_bytes()).hexdigest()
module = Path(a.module).resolve()
manifest = {str(f.relative_to(root)): sha(f) for d in ['ext', 'src/Asyncara/PostgreSQL', 'src/Asyncara/Internal', 'tests/database']
            for f in (root/d).rglob('*') if f.is_file() and '__pycache__' not in str(f)}
report = {'status':'RUNNING','backend':a.backend,'sourceManifest':manifest,
          'moduleSha256':sha(module),'phpSha256':sha(a.php),
          'postgres':subprocess.check_output([str(pg/'postgres'),'--version'],text=True).strip()}
server = None; socket_directory = None; start = time.monotonic()
try:
    with (out/'init.log').open('x') as log:
        subprocess.run([str(pg/'initdb'),'-D',str(out/'data'),'-A','trust','--no-locale'],stdout=log,stderr=log,check=True,timeout=30)
    certs = out/'certs'; certs.mkdir(mode=0o700)
    with (out/'cert.log').open('x') as log:
        def openssl(*args, env=None):
            subprocess.run(['openssl',*map(str,args)],cwd=certs,env=env,stdout=log,stderr=log,check=True,timeout=20)
        for ca in ['ca','untrusted-ca']:
            openssl('req','-x509','-newkey','rsa:2048','-nodes','-days','2','-subj','/CN='+ca,'-keyout',ca+'.key','-out',ca+'.pem')
        user = getpass.getuser()
        for name, cn, ca, purpose in [('server','localhost','ca','serverAuth'),('client',user,'ca','clientAuth'),('other','other-user','ca','clientAuth'),('untrusted',user,'untrusted-ca','clientAuth')]:
            openssl('req','-new','-newkey','rsa:2048','-nodes','-subj','/CN='+cn,'-keyout',name+'.key','-out',name+'.csr')
            (certs/(name+'.ext')).write_text('extendedKeyUsage='+purpose+'\n'+('subjectAltName=DNS:localhost\n' if name=='server' else ''))
            openssl('x509','-req','-days','2','-in',name+'.csr','-CA',ca+'.pem','-CAkey',ca+'.key','-CAcreateserial','-extfile',name+'.ext','-out',name+'.pem')
        secret = secrets.token_urlsafe(32)
        (certs/'passphrase').write_text(secret); (certs/'passphrase').chmod(0o600)
        openssl('pkey','-in','client.key','-aes-256-cbc','-passout','env:ASYNCARA_TEST_KEY_PASSPHRASE','-out','encrypted.key',env=os.environ|{'ASYNCARA_TEST_KEY_PASSPHRASE':secret})
        del secret
    for f in certs.glob('*.key'): f.chmod(0o600)
    with socket.socket() as reserve:
        reserve.bind(('127.0.0.1',0)); port=reserve.getsockname()[1]
    (out/'data/pg_hba.conf').write_text('local all all trust\nhostssl all all 127.0.0.1/32 cert\nhostnossl all all 127.0.0.1/32 reject\n')
    # Unix socket paths are limited to 107 bytes on Linux. Evidence paths may
    # be arbitrarily deep; keep only the live, owned socket in a short directory.
    socket_directory = tempfile.TemporaryDirectory(prefix='asyncara-pg-mtls-', dir='/tmp')
    sock = Path(socket_directory.name)
    report['observerSocketDirectory'] = str(sock)
    opts = {'host':'localhost','hostaddr':'127.0.0.1','port':str(port),'user':user,'dbname':'postgres',
            'application_name':'asyncara_mtls_test','sslmode':'verify-full','sslrootcert':str(certs/'ca.pem'),
            'sslcert':str(certs/'client.pem'),'sslkey':str(certs/'client.key')}
    fixture = {'options':opts,'passphraseFile':str(certs/'passphrase'),'encryptedKey':str(certs/'encrypted.key'),
               'untrustedCert':str(certs/'untrusted.pem'),'untrustedKey':str(certs/'untrusted.key'),
               'untrustedCa':str(certs/'untrusted-ca.pem'),'otherCert':str(certs/'other.pem'),
               'otherKey':str(certs/'other.key'),'missingPath':str(certs/'absent.pem')}
    (out/'fixture.json').write_text(json.dumps(fixture))
    (out/'options.json').write_text(json.dumps(opts))
    with (out/'postgres.log').open('x') as log:
        server = subprocess.Popen([str(pg/'postgres'),'-D',str(out/'data'),'-h','127.0.0.1','-p',str(port),'-k',str(sock),
            '-c','ssl=on','-c','ssl_cert_file='+str(certs/'server.pem'),'-c','ssl_key_file='+str(certs/'server.key'),
            '-c','ssl_ca_file='+str(certs/'ca.pem')],stdout=log,stderr=log)
    deadline = time.monotonic()+15
    while subprocess.run([str(pg/'pg_isready'),'-h',str(sock),'-p',str(port),'-d','postgres','-t','1'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL).returncode:
        if server.poll() is not None: raise RuntimeError('Private PostgreSQL startup failed')
        if time.monotonic()>deadline: raise TimeoutError('Private PostgreSQL readiness')
        time.sleep(.025)
    cmd=[a.php,'-n','-d','extension='+str(module),'-d','zend.exception_ignore_args=0',str(root/'tests/database/mtls_pg.php')]+(['probe'] if a.probe else [])
    if a.paths is not None:
        cmd=['python3',str(root/'tests/database/sql_tls_paths.py'),'--php',a.php,'--module',str(module),'--out',str(out/'paths')]+(['--modes',*a.paths] if a.paths else [])
    if a.server:cmd=['python3',str(root/'tests/database/native_server.py'),'--php',a.php,'--module',str(module),'--out',str(out/'native-server')]
    if a.transactions:cmd=[a.php,'-n','-d','extension='+str(module),str(root/'tests/database/transactions.php')]
    if a.owner_pending:cmd=[a.php,'-n','-d','extension='+str(module),str(root/'tests/database/transaction_owner_pending.php')]
    env=os.environ|{'ASYNCARA_BACKEND':a.backend,'ASYNCARA_PG_MTLS_FIXTURE':str(out/'fixture.json')}
    with (out/'php.log').open('x') as log:
        child=subprocess.Popen(cmd,cwd=root,env=env,stdin=subprocess.DEVNULL,stdout=log,stderr=log)
        (out/'active.json').write_text(json.dumps({'serverPid':server.pid,'childPid':child.pid,'command':cmd}))
        try: code=child.wait(timeout=90)
        except subprocess.TimeoutExpired:
            child.terminate()
            try: child.wait(timeout=5)
            except subprocess.TimeoutExpired: child.kill(); child.wait()
            raise
    report.update(status='PASS' if code==0 else 'FAIL',exit=code,command=cmd)
    log=(out/'php.log').read_text()
    if 'Enter PEM pass phrase' in log: raise AssertionError('Interactive key password prompt reached PHP thread')
    if code==0:
        if a.fpm:
            command=['python3',str(root/'tests/database/prepared_pg_fpm.py'),'--fpm',str(a.fpm.resolve()),
                '--module',str(module),'--backend',a.backend,'--options',str(out/'options.json'),'--out',str(out/'fpm'),
                '--observer-host',str(sock)]
            with (out/'fpm.log').open('x') as log:
                process=subprocess.Popen(command,cwd=root,env=env,stdout=log,stderr=log)
                (out/'active.json').write_text(json.dumps({'serverPid':server.pid,'childPid':process.pid,'command':command}))
                fpm_exit=process.wait()
            report['fpm']={'exit':fpm_exit,'command':command,'fpmSha256':sha(a.fpm)}
            if fpm_exit: raise RuntimeError('mTLS FPM lifecycle failed')
        deadline=time.monotonic()+5
        while True:
            left=subprocess.check_output([str(pg/'psql'),'-h',str(sock),'-p',str(port),'-d','postgres','-Atc',"SELECT count(*) FROM pg_stat_activity WHERE application_name='asyncara_mtls_test'"],text=True,timeout=5).strip()
            if left=='0': break
            if time.monotonic()>deadline: raise TimeoutError('Test connection leaked on server')
            time.sleep(.01)
        report['remainingSessions']=0
    assert all(sha(root/name)==digest for name,digest in manifest.items()), 'Source changed during test'
except BaseException as exc:
    report.update(status='FAIL',error=repr(exc))
finally:
    if server is not None:
        if server.poll() is None:
            subprocess.run([str(pg/'pg_ctl'),'-D',str(out/'data'),'-m','fast','-w','stop'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=True,timeout=20)
        report['serverExit']=server.wait(timeout=5)
    if socket_directory is not None:
        socket_directory.cleanup()
    report['seconds']=time.monotonic()-start
    report['logHashes']={f.name:sha(f) for f in out.glob('*.log')}
    (out/'report.json').write_text(json.dumps(report,indent=2)+'\n')
    (out/'active.json').write_text('{"status":"TERMINAL"}')
print(json.dumps({k:v for k,v in report.items() if k!='sourceManifest'},indent=2))
raise SystemExit(0 if report['status']=='PASS' else 1)
