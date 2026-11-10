#!/usr/bin/env python3
"""Independent Python/OpenSSL peer: verified TLS, pending waits, bounds and mTLS."""
import argparse, asyncio, hashlib, json, os, pathlib, ssl, subprocess, time, traceback
from run import response

def certificates(out):
    certs=out/'certs';certs.mkdir(mode=0o700)
    def run(*cmd):
        with (out/'cert-generation.log').open('ab') as f:subprocess.run(['openssl',*cmd],cwd=certs,stdout=f,stderr=f,check=True)
    for name in ['ca','wrong']:
        run('req','-x509','-newkey','ec','-pkeyopt','ec_paramgen_curve:P-256','-nodes','-days','2','-subj','/CN='+name,'-keyout',name+'.key','-out',name+'.pem','-addext','basicConstraints=critical,CA:TRUE')
    for name in ['server','client','badclient']:
        run('req','-new','-newkey','ec','-pkeyopt','ec_paramgen_curve:P-256','-nodes','-subj','/CN=localhost','-keyout',name+'.key','-out',name+'.csr')
        (certs/(name+'.ext')).write_text('subjectAltName=DNS:localhost,IP:127.0.0.1\n'+('extendedKeyUsage=serverAuth\n' if name=='server' else 'extendedKeyUsage=clientAuth\n'))
        run('x509','-req','-in',name+'.csr','-CA',('wrong' if name=='badclient' else 'ca')+'.pem','-CAkey',('wrong' if name=='badclient' else 'ca')+'.key','-CAcreateserial','-days','2','-extfile',name+'.ext','-out',name+'.pem')
    for p in certs.glob('*.key'):p.chmod(0o600)
    return certs

async def checks(port, certs, mtls):
    completed=[]
    def context(version=None, auth=mtls, ca='ca', alpn=['h2','http/1.1']):
        ctx=ssl.create_default_context(cafile=str(certs/(ca+'.pem')))
        ctx.set_alpn_protocols(alpn)
        if version:ctx.minimum_version=ctx.maximum_version=version
        if auth:
            who='badclient' if auth=='bad' else 'client'
            ctx.load_cert_chain(certs/(who+'.pem'),certs/(who+'.key'))
        return ctx
    async def connect(ctx=None, name='localhost'):
        return await asyncio.wait_for(asyncio.open_connection('127.0.0.1',port,ssl=ctx or context(),server_hostname=name,ssl_handshake_timeout=3,ssl_shutdown_timeout=3),5)
    async def close(w):
        w.close()
        try:await w.wait_closed()
        except (ConnectionError,ssl.SSLError):pass
    async def request(path,ctx=None,name='localhost',body=b''):
        r,w=await connect(ctx,name)
        try:
            assert w.get_extra_info('ssl_object').selected_alpn_protocol()=='http/1.1'
            w.write((f'{"POST" if body else "GET"} {path} HTTP/1.1\r\nHost: localhost\r\nContent-Length: {len(body)}\r\n\r\n').encode()+body)
            await w.drain();return await response(r)
        finally:await close(w)
    for version in [ssl.TLSVersion.TLSv1_2,ssl.TLSVersion.TLSv1_3]:
        assert (await request('/status',context(version)))[2]==b'ok'
        completed.append(version.name)
    for ctx,name in [(context(ca='wrong'),'localhost'),(context(),'not-localhost'),(context(alpn=['h2']),'localhost')]+([(context(auth=False),'localhost'),(context(auth='bad'),'localhost')] if mtls else []):
        try:await request('/status',ctx,name)
        except (ssl.SSLError,ConnectionError,asyncio.IncompleteReadError):pass
        else:raise AssertionError('Invalid trust/identity/protocol/client certificate accepted')
    completed.append('trust_hostname_alpn_client_auth_rejections')
    if mtls:
        assert (await request('/stop'))[2]==b'stopped'
        return completed
    # A peer with no ClientHello must not block other TLS handshakes or timers.
    idle_r,idle_w=await asyncio.open_connection('127.0.0.1',port)
    r,w=await connect();w.write(b'GET /hold HTTP/1.1\r\nHost: localhost\r\n\r\n');await w.drain()
    task=asyncio.create_task(response(r))
    for _ in range(100):
        metrics=json.loads((await request('/metrics'))[2])
        if metrics['ticks']>=2:break
        await asyncio.sleep(.002)
    assert metrics['ticks']>=2 and metrics['pending']==1 and not task.done(),metrics
    assert (await request('/release'))[0]==200
    assert (await task)[2]==b'released';await close(w)
    assert await asyncio.wait_for(idle_r.read(),4)==b'';await close(idle_w)
    completed.append('two_ticks_during_pending_tls_and_incomplete_handshake')
    payload=bytes(range(256))*4096
    replies=await asyncio.gather(*(request('/echo',body=payload) for _ in range(8)))
    assert all(s==200 and body==payload for s,_,body in replies)
    r,w=await connect();w.write(b'GET /large HTTP/1.1\r\nHost: localhost\r\n\r\n');await w.drain()
    # Reader can be slow while independent requests and timers remain serviceable.
    assert (await request('/status'))[2]==b'ok'
    assert (await response(r))[2]==b'x'*2097152;await close(w)
    completed.append('concurrent_binary_upload_large_response')
    r,w=await connect()
    w.write(b'GET /status HTTP/1.1\r\nHost: localhost\r\n\r\n'*20);await w.drain()
    for _ in range(20):assert (await response(r))[2]==b'ok'
    await close(w);completed.append('tls_keepalive_pipeline')
    for _ in range(30):
        metrics=json.loads((await request('/metrics'))[2])
        if metrics['native']['connections']<=2:break
        await asyncio.sleep(.005)
    assert metrics['native']['bufferBytes']==0 and metrics['native']['timedOut']>=1,metrics
    completed.append('timeout_and_payload_cleanup')
    assert (await request('/stop'))[2]==b'stopped'
    return completed

def main():
    p=argparse.ArgumentParser();p.add_argument('--php',required=True);p.add_argument('--module',required=True);p.add_argument('--out',required=True);p.add_argument('--backend',choices=['epoll','poll'],default='epoll');a=p.parse_args()
    root=pathlib.Path(__file__).resolve().parents[2];out=pathlib.Path(a.out).resolve();out.mkdir(parents=True,exist_ok=False);certs=certificates(out)
    result={'backend':a.backend,'moduleSha256':hashlib.sha256(pathlib.Path(a.module).read_bytes()).hexdigest(),'rows':[]};start=time.monotonic()
    try:
        for mode in ['tls','mtls']:
            cmd=[a.php,'-n','-d','extension='+str(pathlib.Path(a.module).resolve()),str(root/'tests/server/tls_fixture.php'),str(certs),mode]
            with (out/(mode+'-stderr.log')).open('w') as err:
                proc=subprocess.Popen(cmd,stdout=subprocess.PIPE,stderr=err,text=True,env=os.environ|{'ASYNCARA_BACKEND':a.backend})
                (out/(mode+'-launch.json')).write_text(json.dumps({'pid':proc.pid,'command':cmd}))
                try:
                    line=proc.stdout.readline();assert line.startswith('READY '),line
                    port=int(line.strip().rsplit(':',1)[1]);completed=asyncio.run(checks(port,certs,mode=='mtls'))
                finally:
                    try:tail=proc.communicate(timeout=8)[0]
                    except subprocess.TimeoutExpired:
                        proc.terminate()
                        try:tail=proc.communicate(timeout=4)[0]
                        except subprocess.TimeoutExpired:proc.kill();tail=proc.communicate()[0]
                    (out/(mode+'-stdout.log')).write_text(tail)
                assert proc.returncode==0,(proc.returncode,tail)
                result['rows'].append({'mode':mode,'checks':completed,'exit':proc.returncode})
        result['status']='PASS'
    except BaseException as e:result.update(status='FAIL',error=repr(e),traceback=traceback.format_exc())
    result['wallSeconds']=time.monotonic()-start
    (out/'report.json').write_text(json.dumps(result,indent=2)+'\n');print(json.dumps(result,indent=2));raise SystemExit(result['status']!='PASS')
if __name__=='__main__':main()
