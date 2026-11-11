#!/usr/bin/env python3
"""Run SQL mTLS + pooled transactions + file/HTTP I/O in actual server Fibers."""
import argparse,concurrent.futures,hashlib,http.client,json,os,pathlib,select,socket,subprocess,sys,time,traceback
p=argparse.ArgumentParser()
for k in ['php','module','out']:p.add_argument('--'+k,required=True)
a=p.parse_args();root=pathlib.Path(__file__).resolve().parents[2];out=pathlib.Path(a.out).resolve();out.mkdir(parents=True,exist_ok=False)
sha=lambda f:hashlib.sha256(pathlib.Path(f).read_bytes()).hexdigest();rows=[];started=time.monotonic();child=None;redis=None
report={'status':'RUNNING','moduleSha256':sha(a.module),'rows':rows,'backend':os.environ['ASYNCARA_BACKEND']}
try:
    sys.path.insert(0,str(root/'tests/server'));from tls import certificates
    certs=certificates(out)
    with socket.socket()as reserve:reserve.bind(('127.0.0.1',0));port=reserve.getsockname()[1]
    acl=out/'users.acl';acl.write_text('user default off\nuser asyncara on #'+hashlib.sha256(b'synthetic-server-password').hexdigest()+' ~asyncara:* &asyncara:* +@all\n')
    redis_cmd=['redis-server','--bind','127.0.0.1','--port','0','--tls-port',str(port),'--tls-cert-file',str(certs/'server.pem'),'--tls-key-file',str(certs/'server.key'),'--tls-ca-cert-file',str(certs/'ca.pem'),'--tls-auth-clients','no','--aclfile',str(acl),'--save','','--appendonly','no','--dir',str(out),'--maxmemory','64mb']
    with(out/'redis.log').open('x')as log:redis=subprocess.Popen(redis_cmd,stdout=log,stderr=log)
    deadline=time.monotonic()+5
    while True:
        if redis.poll()is not None:raise RuntimeError('Private Redis failed')
        try:
            with socket.create_connection(('127.0.0.1',port),.1):break
        except OSError:
            if time.monotonic()>deadline:raise
            time.sleep(.01)
    env=os.environ|{'ASYNCARA_SERVER_REDIS_PORT':str(port),'ASYNCARA_SERVER_REDIS_CA':str(certs/'ca.pem')}
    for key in ['http_proxy','HTTP_PROXY','https_proxy','HTTPS_PROXY','all_proxy','ALL_PROXY']:env.pop(key,None)
    for transport in ['curl-multi','hyper-prototype']:
        cmd=[a.php,'-n','-d','extension='+str(pathlib.Path(a.module).resolve()),str(root/'tests/database/native_server.php')]
        with(out/(transport+'.log')).open('x')as log:
            child=subprocess.Popen(cmd,cwd=root,env=env|{'ASYNCARA_HTTP_BACKEND':transport},stdout=subprocess.PIPE,stderr=log,text=True)
            (out/'active.json').write_text(json.dumps({'pid':child.pid,'redisPid':redis.pid,'command':cmd,'transport':transport}))
            assert select.select([child.stdout],[],[],10)[0],'Ready deadline'
            line=child.stdout.readline();log.write(line);log.flush();assert line.startswith('READY '),line
            address=line.strip().split(' ',1)[1]
            def request(path,expected=200):
                conn=http.client.HTTPConnection(address,timeout=15)
                try:
                    conn.request('GET',path);r=conn.getresponse();data=r.read();assert r.status==expected,(path,r.status,data);return json.loads(data)if data else None
                finally:
                    conn.close()
                    with(out/(transport+'-requests.jsonl')).open('a')as events:events.write(json.dumps({'path':path,'monotonic':time.monotonic()})+'\n')
            with concurrent.futures.ThreadPoolExecutor(max_workers=16)as workers:
                values=list(workers.map(lambda i:request('/work/'+str(i)),range(1,17)))
            assert {v['id']for v in values}==set(range(1,17))
            assert all(v['redis']and v['http']and v['transaction']for v in values)
            for mode in ['release','cancel','timeout']:
                assert request('/pending/'+mode)=={'mode':mode,'ticksWhilePending':2}
            request('/failure',500)
            assert request('/work/42')['id']==42
            summary=request('/stop');assert summary['ticks']==6 and summary['completed']==17 and summary['pool']['closed'] and summary['created']<=5,summary
            code=child.wait(timeout=10);log.write(child.stdout.read());log.flush();assert code==0,code
        terminal=json.loads((out/(transport+'.log')).read_text().splitlines()[-1]);assert terminal['status']=='PASS'
        observer=['redis-cli','--tls','--cacert',str(certs/'ca.pem'),'--user','asyncara','-p',str(port)]
        clients=subprocess.check_output(observer+['CLIENT','LIST'],env=os.environ|{'REDISCLI_AUTH':'synthetic-server-password'},text=True,timeout=5)
        assert len(clients.strip().splitlines())==1,'Redis clients retained after server exit'
        assert subprocess.check_output(observer+['DBSIZE'],env=os.environ|{'REDISCLI_AUTH':'synthetic-server-password'},text=True,timeout=5).strip()=='0','Redis keys not removed'
        rows.append({'transport':transport,'status':'PASS','requests':22,'summary':summary,'remainingRedisClients':0,'remainingRedisKeys':0,'command':cmd,'logSha256':sha(out/(transport+'.log'))})
    report['status']='PASS'
except BaseException as e:
    report.update(status='FAIL',error=repr(e));(out/'failure.log').write_text(traceback.format_exc())
finally:
    if child and child.poll()is None:child.terminate();child.wait(timeout=10)
    if child and child.stdout:
        remaining=child.stdout.read()
        if remaining:(out/'unexpected-stdout.log').write_text(remaining)
    if redis and redis.poll()is None:redis.terminate();redis.wait(timeout=10)
    report['redisExit']=redis.returncode if redis else None
    report['seconds']=time.monotonic()-started
    (out/'report.json').write_text(json.dumps(report,indent=2)+'\n');(out/'active.json').write_text('{"status":"TERMINAL"}\n')
print(json.dumps(report,indent=2));raise SystemExit(report['status']!='PASS')
