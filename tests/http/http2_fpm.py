#!/usr/bin/env python3
"""Four-worker HTTP/2 integration with independent delayed multiplexed streams."""
import argparse,hashlib,importlib.util,json,os,pathlib,subprocess,sys,time
import h2
p=argparse.ArgumentParser()
for key in ['module','fpm','out']:p.add_argument('--'+key,required=True)
p.add_argument('--backend',choices=['epoll','poll'],required=True);a=p.parse_args()
root=pathlib.Path(__file__).resolve().parents[2];out=pathlib.Path(a.out).resolve();out.mkdir(parents=True,exist_ok=False,mode=0o700)
sys.path.insert(0,str(root/'tests/server'));from tls import certificates
certs=certificates(out);os.environ.update(ASYNCARA_FPM_DIR=str(out),ASYNCARA_EXTENSION=str(pathlib.Path(a.module).resolve()),FPM_BIN=a.fpm)
spec=importlib.util.spec_from_file_location('audit',root/'scripts/audit-fpm.py');audit=importlib.util.module_from_spec(spec);spec.loader.exec_module(audit);audit.server.shutdown();audit.server.server_close()
sys.path.insert(0,str(root/'tests/fast'));from fpm_client import request
sha=lambda f:hashlib.sha256(f.read_bytes()).hexdigest();rows=[];peer=None;start=time.monotonic()
manifest={str(f.relative_to(root)):sha(f)for d in ['ext','src','tests/http']for f in(root/d).rglob('*')if f.is_file()and '__pycache__'not in f.parts}
for f in [root/'scripts/audit-fpm.py',root/'tests/fast/fpm_client.py']:manifest[str(f.relative_to(root))]=sha(f)
(out/'source-manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
try:
 with(out/'peer.log').open('x')as log:peer=subprocess.Popen([sys.executable,str(root/'tests/http/http2_peer.py'),str(certs)],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=log,text=True)
 ports=json.loads(peer.stdout.readline());config={'backend':a.backend,'url':'https://localhost:'+str(ports['https']),'ca':str(certs/'ca.pem')};(out/'config.json').write_text(json.dumps(config))
 wrapper=out/'selected.php';fixture=root/'tests/http/http2_fpm_request.php'
 wrapper.write_text('<?php $config=json_decode(file_get_contents('+json.dumps(str(out/'config.json'))+'),true,flags:JSON_THROW_ON_ERROR); require '+json.dumps(str(fixture))+';\n')
 def checked(port,index,mode):return request(audit,port,index,mode,wrapper)
 audit.request=checked
 for case in [('A-lifecycle',1,20,'io',False),('H2-four-workers',4,40,'io',True)]:rows.append(audit.run(*case));print(case[0],'PASS',flush=True)
 assert all(sha(root/name)==digest for name,digest in manifest.items()),'Changed source'
 peer.stdin.close();peer.wait(timeout=5);tail=peer.stdout.read();(out/'peer-final.log').write_text(tail);stats=json.loads(tail);assert peer.returncode==0 and not stats['errors'] and stats['rejectedAlpn']==0
 (out/'report.json').write_text(json.dumps({'status':'PASS','seconds':time.monotonic()-start,'backend':a.backend,'h2Version':h2.__version__,'moduleSha256':sha(pathlib.Path(a.module)),'cases':rows,'peer':stats},indent=2)+'\n')
except BaseException as error:(out/'failure.json').write_text(json.dumps({'status':'FAIL','error':repr(error),'completedCases':rows},indent=2)+'\n');raise
finally:
 if peer is not None and peer.poll()is None:
  if not peer.stdin.closed:peer.stdin.close()
  try:peer.wait(timeout=5)
  except subprocess.TimeoutExpired:peer.terminate();peer.wait(timeout=5)
  (out/'peer-interrupted.log').write_text(peer.stdout.read())
