#!/usr/bin/env python3
"""Private CA, loopback-only SQL/Redis TLS fixtures; no global installation."""
import argparse, hashlib, json, os, pathlib, secrets, socket, subprocess, time
root=pathlib.Path(__file__).resolve().parent.parent
parser=argparse.ArgumentParser();parser.add_argument('--output',required=True);parser.add_argument('--oracle-prefix',type=pathlib.Path);args=parser.parse_args()
out=pathlib.Path(args.output).resolve()
if out.exists():parser.error('Use a new evidence directory')
out.mkdir(parents=True,mode=0o700);os.chmod(out,0o700)
def run(command,*,input=None,name='setup'):
    with (out/(name+'.log')).open('a')as log:subprocess.run(command,input=input,text=True,stdout=log,stderr=log,check=True)
def port():
    with socket.socket()as s:s.bind(('127.0.0.1',0));return s.getsockname()[1]
def openssl(*arguments):run(['openssl',*map(str,arguments)],name='certificates')
for ca in ('ca','untrusted'):
    openssl('req','-x509','-newkey','rsa:2048','-nodes','-days','2','-subj','/CN=Asyncara private test '+ca,'-keyout',out/(ca+'.key'),'-out',out/(ca+'.pem'))
for name,san in [('server','DNS:localhost,IP:127.0.0.1'),('mismatch','DNS:wrong.asyncara.invalid')]:
    openssl('req','-new','-newkey','rsa:2048','-nodes','-subj','/CN='+('localhost'if name=='server'else'wrong.asyncara.invalid'),'-keyout',out/(name+'.key'),'-out',out/(name+'.csr'))
    ext=out/(name+'.ext');ext.write_text('subjectAltName='+san+'\nextendedKeyUsage=serverAuth\n')
    openssl('x509','-req','-in',out/(name+'.csr'),'-CA',out/'ca.pem','-CAkey',out/'ca.key','-CAcreateserial','-days','2','-extfile',ext,'-out',out/(name+'.pem'))
    os.chmod(out/(name+'.key'),0o600)
password=secrets.token_hex(24);pgbin=pathlib.Path(os.environ.get('POSTGRES_BIN','/usr/lib/postgresql/16/bin'))
pg=out/'pg';my=out/'maria';redis=out/'redis'
for folder in (pg,my,redis):folder.mkdir(mode=0o700)
run([str(pgbin/'initdb'),'-D',str(pg/'data'),'-A','trust','--no-locale'],name='pg-init')
(pg/'data/pg_hba.conf').write_text('local all all trust\nhostssl all all 127.0.0.1/32 scram-sha-256\nhostnossl all all 127.0.0.1/32 reject\n')
if args.oracle_prefix:
    oracle=args.oracle_prefix.resolve();mysql_server=str(oracle/'usr/sbin/mysqld');mysql_client=str(oracle/'usr/bin/mysql')
    run([mysql_server,'--no-defaults','--initialize-insecure','--basedir='+str(oracle/'usr'),'--datadir='+str(my/'data')],name='mysql-init')
else:
    mysql_server='mariadbd';mysql_client='mariadb'
    run(['mariadb-install-db','--no-defaults','--datadir='+str(my/'data'),'--auth-root-authentication-method=normal','--skip-test-db'],name='maria-init')
acl=redis/'users.acl';acl.write_text('user default off\nuser asyncara on #'+hashlib.sha256(password.encode()).hexdigest()+' ~asyncara:* &asyncara:* +@all\n');os.chmod(acl,0o600)
reports=[]
for phase in ('server','mismatch'):
    ports={'postgres':port(),'maria':port(),'redis':port()};processes=[];logs=[]
    def launch(command,name):
        log=(out/(phase+'-'+name+'.log')).open('w');logs.append(log)
        process=subprocess.Popen(command,stdout=log,stderr=log);processes.append(process);return process
    try:
        launch([str(pgbin/'postgres'),'-D',str(pg/'data'),'-k',str(pg),'-h','127.0.0.1','-p',str(ports['postgres']),'-c','ssl=on','-c','ssl_cert_file='+str(out/(phase+'.pem')),'-c','ssl_key_file='+str(out/(phase+'.key')),'-c','password_encryption=scram-sha-256'],'pg')
        launch([mysql_server,'--no-defaults','--datadir='+str(my/'data'),'--socket='+str(my/'mysql.sock'),'--pid-file='+str(my/'pid'),'--bind-address=127.0.0.1','--port='+str(ports['maria']),'--ssl-ca='+str(out/'ca.pem'),'--ssl-cert='+str(out/(phase+'.pem')),'--ssl-key='+str(out/(phase+'.key')),'--require-secure-transport=ON']+(['--basedir='+str(oracle/'usr'),'--mysqlx=OFF','--secure-file-priv=NULL']if args.oracle_prefix else[]),'maria')
        launch(['redis-server','--bind','127.0.0.1','--port','0','--tls-port',str(ports['redis']),'--tls-cert-file',str(out/(phase+'.pem')),'--tls-key-file',str(out/(phase+'.key')),'--tls-ca-cert-file',str(out/'ca.pem'),'--tls-auth-clients','no','--aclfile',str(acl),'--save','','--appendonly','no','--dir',str(redis)],'redis')
        deadline=time.monotonic()+30
        for service in ports:
            while True:
                if any(p.poll()is not None for p in processes):raise RuntimeError('Fixture startup failed; inspect service logs')
                try:
                    with socket.create_connection(('127.0.0.1',ports[service]),timeout=.1):break
                except OSError:
                    if time.monotonic()>deadline:raise RuntimeError('Fixture readiness timeout')
                    time.sleep(.05)
        if phase=='server':
            probe=os.environ.get('ASYNCARA_PG_TLS_UNLOAD_PROBE')
            if probe:
                with (out/'libpq-only-unload.log').open('w')as log:
                    result=subprocess.run([probe,'host=localhost hostaddr=127.0.0.1 port='+str(ports['postgres'])+' dbname=postgres user=nonexistent sslmode=verify-full sslrootcert='+str(out/'ca.pem')],stdout=log,stderr=log)
                (out/'libpq-only-exit.json').write_text(json.dumps({'exit':result.returncode})+'\n')
            run([str(pgbin/'psql'),'-X','-h',str(pg),'-p',str(ports['postgres']),'-d','postgres','-v','ON_ERROR_STOP=1'],input="CREATE ROLE asyncara LOGIN PASSWORD '"+password+"';\n",name='pg-account')
            run([mysql_client,'--no-defaults','--socket='+str(my/'mysql.sock'),'-uroot'],input="CREATE DATABASE asyncara; CREATE USER 'asyncara'@'127.0.0.1' IDENTIFIED BY '"+password+"' REQUIRE SSL; GRANT ALL ON asyncara.* TO 'asyncara'@'127.0.0.1';\n",name='maria-account')
        if phase=='server' and args.oracle_prefix:
            run([mysql_client,'--no-defaults','--socket='+str(my/'mysql.sock'),'-uroot'],input="CREATE USER 'asyncara_sha256'@'127.0.0.1' IDENTIFIED WITH sha256_password BY '"+password+"' REQUIRE SSL; GRANT ALL ON asyncara.* TO 'asyncara_sha256'@'127.0.0.1'; SELECT user,plugin FROM mysql.user WHERE user LIKE 'asyncara%';\n",name='oracle-auth-plugins')
        config=out/'client.json';config.write_text(json.dumps({'phase':phase,'ports':ports,'ca':str(out/'ca.pem'),'untrustedCa':str(out/'untrusted.pem'),'password':password,'mysqlLabel':'Oracle MySQL'if args.oracle_prefix else'MariaDB'}));os.chmod(config,0o600)
        command=[os.environ.get('PHP_BIN','php'),'-n','-d','extension='+str(root/os.environ.get('ASYNCARA_EXTENSION','build/asyncara.so')),str(root/'tests/database/tls.php'),str(config)]
        with (out/(phase+'-client.log')).open('w')as log:
            result=subprocess.run(command,stdout=log,stderr=log,timeout=90)
        reports.append({'phase':phase,'exit':result.returncode});(out/'report.json').write_text(json.dumps(reports,indent=2)+'\n')
        if result.returncode:raise RuntimeError('TLS client checks failed; inspect '+str(out/(phase+'-client.log')))
    finally:
        for p in processes:
            if p.poll()is None:p.terminate()
        for p in processes:
            try:p.wait(timeout=20)
            except subprocess.TimeoutExpired:p.kill();p.wait();raise RuntimeError('Fixture shutdown timeout')
        for log in logs:log.close()
print('Private-CA SQL/Redis TLS and authentication: PASS')
