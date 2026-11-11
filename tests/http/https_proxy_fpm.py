#!/usr/bin/env python3
"""Four-worker curl TLS proxy integration; original overlap/ticks/fatal recovery."""
import argparse,hashlib,importlib.util,json,os,pathlib,ssl,subprocess,sys,threading,time
p=argparse.ArgumentParser()
for key in ['module','fpm','out']:p.add_argument('--'+key,required=True)
p.add_argument('--backend',choices=['epoll','poll'],required=True)
a=p.parse_args();root=pathlib.Path(__file__).resolve().parents[2];out=pathlib.Path(a.out).resolve();out.mkdir(parents=True,exist_ok=False,mode=0o700)
sys.path.insert(0,str(root/'tests/server'));from tls import certificates
for name in ['origin','proxy']:(out/name).mkdir(mode=0o700)
certs=certificates(out/'origin');proxy_certs=certificates(out/'proxy')
os.environ.update(ASYNCARA_FPM_DIR=str(out),ASYNCARA_EXTENSION=str(pathlib.Path(a.module).resolve()),FPM_BIN=a.fpm)
spec=importlib.util.spec_from_file_location('audit',root/'scripts/audit-fpm.py');audit=importlib.util.module_from_spec(spec);spec.loader.exec_module(audit)
audit.server.shutdown();audit.server.server_close()
ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(certs/'server.pem',certs/'server.key')
audit.server=audit.Server(('127.0.0.1',0),audit.Handler);audit.server.socket=ctx.wrap_socket(audit.server.socket,server_side=True)
threading.Thread(target=audit.server.serve_forever,daemon=True).start()
sys.path.insert(0,str(root/'tests/fast'));from fpm_client import request
fixture=root/'tests/http/https_proxy_fpm_request.php';wrapper=out/'selected.php';rows=[];peer=None;start=time.monotonic();sha=lambda f:hashlib.sha256(f.read_bytes()).hexdigest()
manifest={str(f.relative_to(root)):sha(f) for d in ['ext','src','tests/http'] for f in (root/d).rglob('*') if f.is_file() and '__pycache__' not in f.parts}
for f in [root/'scripts/audit-fpm.py',root/'tests/fast/fpm_client.py']:manifest[str(f.relative_to(root))]=sha(f)
(out/'source-manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
try:
    cfg={'http':0,'https':audit.server.server_port,'validCert':str(proxy_certs/'server.pem'),'mismatchCert':str(proxy_certs/'server.pem'),'key':str(proxy_certs/'server.key')}
    (out/'peer-config.json').write_text(json.dumps(cfg))
    with(out/'proxy-peer.log').open('x')as log:peer=subprocess.Popen(['python3',str(root/'tests/http/https_proxy_peer.py'),str(out/'peer-config.json')],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=log,text=True)
    ports=json.loads(peer.stdout.readline())
    config={'backend':a.backend,'transport':'curl-multi','trustDirectory':str(certs),'wrongDirectory':str(out),'proxy':'https://localhost:'+str(ports['proxy']),'proxyCA':str(proxy_certs/'ca.pem')}
    (out/'ca.pem').write_bytes((certs/'wrong.pem').read_bytes());(out/'config.json').write_text(json.dumps(config))
    wrapper.write_text('<?php $caConfig=json_decode(file_get_contents('+json.dumps(str(out/'config.json'))+'),true,flags:JSON_THROW_ON_ERROR); require '+json.dumps(str(fixture))+';\n')
    def checked(port,index,mode):
        value=request(audit,port,index,mode,wrapper)
        if 'before'in value:
            assert value['before']['eventLoopBackend']==a.backend
            assert value['before']['httpBackend']=='curl-multi'
        return value
    audit.request=checked
    for case in [('A-lifecycle',1,20,'io',False),('TLS-proxy-four-workers',4,40,'io',True)]:
        rows.append(audit.run(*case));print(case[0],'PASS',flush=True)
    assert all(sha(root/name)==digest for name,digest in manifest.items()),'Source changed during validation'
    (out/'report.json').write_text(json.dumps({'status':'PASS','seconds':time.monotonic()-start,'backend':a.backend,'sourceFixture':str(fixture),'sourceFixtureSha256':sha(fixture),'moduleSha256':sha(pathlib.Path(a.module)),'cases':rows},indent=2)+'\n')
except BaseException as error:
    (out/'failure.json').write_text(json.dumps({'status':'FAIL','error':repr(error),'completedCases':rows},indent=2)+'\n');raise
finally:
    if peer is not None:
        peer.stdin.close()
        try:peer.wait(timeout=5)
        except subprocess.TimeoutExpired:peer.terminate();peer.wait(timeout=5)
        (out/'proxy-peer-final.log').write_text(peer.stdout.read())
    audit.server.shutdown();audit.server.server_close()
