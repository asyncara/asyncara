#!/usr/bin/env python3
"""Short TLS trust-path FPM isolation with original four-operation barrier/ticks."""
import argparse, hashlib, importlib.util, json, os, pathlib, ssl, sys, threading, time
p=argparse.ArgumentParser()
for key in ['php','module','fpm','out']:p.add_argument('--'+key,required=True)
p.add_argument('--backend',choices=['epoll','poll'],required=True)
p.add_argument('--transport',choices=['curl-multi','hyper-prototype'],required=True)
a=p.parse_args();root=pathlib.Path(__file__).resolve().parents[2]
out=pathlib.Path(a.out).resolve();out.mkdir(parents=True,exist_ok=False,mode=0o700)
sys.path.insert(0,str(root/'tests/server'))
from tls import certificates
certs=certificates(out)
os.environ.update(ASYNCARA_FPM_DIR=str(out),ASYNCARA_EXTENSION=str(pathlib.Path(a.module).resolve()),FPM_BIN=a.fpm)
spec=importlib.util.spec_from_file_location('audit',root/'scripts/audit-fpm.py');audit=importlib.util.module_from_spec(spec);spec.loader.exec_module(audit)
# Replace only this newly created fixture peer, before any request or FPM starts.
# Historical acceptance sources/configuration are never changed.
audit.server.shutdown();audit.server.server_close()
ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(certs/'server.pem',certs/'server.key')
audit.server=audit.Server(('127.0.0.1',0),audit.Handler)
audit.server.socket=ctx.wrap_socket(audit.server.socket,server_side=True)
threading.Thread(target=audit.server.serve_forever,daemon=True).start()
sys.path.insert(0,str(root/'tests/fast'))
from fpm_client import request
fixture=root/'tests/http/ca_fpm_request.php';wrapper=out/'selected.php'
config={'backend':a.backend,'transport':a.transport,'trustDirectory':str(certs),'wrongDirectory':str(out)}
# Same filename, different trust. A deferred relative lookup must not use this.
(out/'ca.pem').write_bytes((certs/'wrong.pem').read_bytes())
(out/'config.json').write_text(json.dumps(config))
wrapper.write_text('<?php $caConfig=json_decode(file_get_contents('+json.dumps(str(out/'config.json'))+'),true,flags:JSON_THROW_ON_ERROR); require '+json.dumps(str(fixture))+';\n')
def checked(port,index,mode):
    value=request(audit,port,index,mode,wrapper)
    if 'before' in value:
        assert value['before']['eventLoopBackend']==a.backend
        assert value['before']['httpBackend']==a.transport
    return value
audit.request=checked
rows=[];start=time.monotonic();sha=lambda f:hashlib.sha256(f.read_bytes()).hexdigest()
try:
    for case in [('A-lifecycle',1,20,'io',False),('TLS-four-workers',4,40,'io',True)]:
        rows.append(audit.run(*case));print(case[0],'PASS',flush=True)
    (out/'report.json').write_text(json.dumps({'status':'PASS','seconds':time.monotonic()-start,'backend':a.backend,'transport':a.transport,'sourceFixture':str(fixture),'sourceFixtureSha256':sha(fixture),'moduleSha256':sha(pathlib.Path(a.module)),'cases':rows},indent=2)+'\n')
except BaseException as error:
    (out/'failure.json').write_text(json.dumps({'status':'FAIL','error':repr(error),'completedCases':rows},indent=2)+'\n');raise
finally:audit.server.shutdown();audit.server.server_close()
