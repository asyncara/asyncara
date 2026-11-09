#!/usr/bin/env python3
"""Additional HTTP feature lifecycle run; historical FPM fixture is unchanged."""
import argparse, hashlib, importlib.util, json, os, pathlib, subprocess, sys, time
p=argparse.ArgumentParser();p.add_argument('--extension',required=True);p.add_argument('--output',required=True);a=p.parse_args()
root=pathlib.Path(__file__).resolve().parents[2];out=pathlib.Path(a.output).resolve();out.mkdir(parents=True,exist_ok=False)
os.environ['ASYNCARA_FPM_DIR']=str(out);os.environ['ASYNCARA_EXTENSION']=str(pathlib.Path(a.extension).resolve())
spec=importlib.util.spec_from_file_location('audit',root/'scripts/audit-fpm.py');audit=importlib.util.module_from_spec(spec);spec.loader.exec_module(audit)
sys.path.insert(0,str(root/'tests/fast'));from fpm_client import request
audit.request=lambda port,index,mode:request(audit,port,index,mode,root/'tests/http/fpm_request.php')
(out/'http-input.bin').write_bytes(b'm'*65536)
log=(out/'http-origin.log').open('xb');origin=subprocess.Popen(['python3',str(root/'tests/fixtures/http_stream_server.py'),str(root/'build/test-certs/cert.pem'),str(root/'build/test-certs/key.pem')],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=log,text=True)
ports=json.loads(origin.stdout.readline());(out/'http-origin.txt').write_text('http://127.0.0.1:'+str(ports['http']))
started=time.monotonic();results=[]
try:
    # mode io keeps the independent peer's four-operation barrier and .2s wait.
    for case in [('A-lifecycle',1,20,'io',False),('HTTP-four-workers',4,40,'io',True)]:
        results.append(audit.run(*case));print(case[0],'PASS',flush=True)
        assert not list(out.glob('.asyncara-*')),'Staged entry escaped normal/fatal cleanup'
    (out/'report.json').write_text(json.dumps({'status':'PASS','seconds':time.monotonic()-started,
        'source':subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip(),
        'moduleSha256':hashlib.sha256(pathlib.Path(a.extension).read_bytes()).hexdigest(),
        'fixtureSha256':hashlib.sha256((root/'tests/http/fpm_request.php').read_bytes()).hexdigest(),
        'cases':results},indent=2)+'\n')
finally:
    origin.stdin.close();origin.stdout.read();origin.wait(timeout=10);log.close()
    audit.server.shutdown();audit.server.server_close()
