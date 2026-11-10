#!/usr/bin/env python3
"""Short prototype FPM check: real peer barrier/two ticks and fatal teardown.
This is separate from both the curl feature fixture and historical acceptance.
"""
import argparse,hashlib,importlib.util,json,os,pathlib,sys,time
p=argparse.ArgumentParser();p.add_argument('--extension',required=True);p.add_argument('--output',required=True);p.add_argument('--backend',choices=['epoll','poll']);modes=p.add_mutually_exclusive_group();modes.add_argument('--dns',action='store_true',help='Additional worker-resolved origin lifecycle fixture');modes.add_argument('--requests',action='store_true',help='Bounded POST bodies and request teardown');a=p.parse_args()
root=pathlib.Path(__file__).resolve().parents[2];out=pathlib.Path(a.output).resolve();out.mkdir(parents=True,exist_ok=False)
os.environ['ASYNCARA_FPM_DIR']=str(out);os.environ['ASYNCARA_EXTENSION']=str(pathlib.Path(a.extension).resolve())
spec=importlib.util.spec_from_file_location('audit',root/'scripts/audit-fpm.py');audit=importlib.util.module_from_spec(spec);spec.loader.exec_module(audit)
sys.path.insert(0,str(root/'tests/fast'));from fpm_client import request
fixture=root/('tests/http/hyper_post_fpm_request.php' if a.requests else 'tests/http/hyper_dns_fpm_request.php' if a.dns else 'tests/http/hyper_fpm_request.php')
if a.requests:
 def post(self):
  size=int(self.headers.get('Content-Length','-1'))
  if size!=65536 or self.headers.get('Transfer-Encoding'):
   self.send_error(400,'Unexpected body framing');return
  body=self.rfile.read(size)
  if body!=b'x'*size or self.headers.get('X-Body-SHA256')!=hashlib.sha256(body).hexdigest():
   self.send_error(400,'Body identity mismatch');return
  self.do_GET() # Retain the actual four-operation barrier and original 0.2s peer wait.
 audit.Handler.do_POST=post
source_fixture=fixture
if a.backend:
 # clear_env=yes remains unchanged. Select explicitly inside this test request,
 # rather than assuming a shell environment survived FPM's isolation policy.
 fixture=out/'selected-backend.php'
 fixture.write_text('<?php putenv('+json.dumps('ASYNCARA_BACKEND='+a.backend)+'); require '+json.dumps(str(source_fixture))+';\n')
def checked_request(port,index,mode):
 value=request(audit,port,index,mode,fixture)
 if a.backend and 'before' in value:
  assert value['before']['eventLoopBackend']==a.backend,'Unexpected FPM backend'
 return value
audit.request=checked_request
results=[];start=time.monotonic()
try:
 for case in [('A-lifecycle',1,20,'io',False),('Hyper-four-workers',4,40,'io',True)]:
  results.append(audit.run(*case));print(case[0],'PASS',flush=True)
 (out/'report.json').write_text(json.dumps({'status':'PASS','seconds':time.monotonic()-start,'backend':a.backend,'sourceFixture':str(source_fixture),'sourceFixtureSha256':hashlib.sha256(source_fixture.read_bytes()).hexdigest(),'fixtureSha256':hashlib.sha256(fixture.read_bytes()).hexdigest(),'moduleSha256':hashlib.sha256(pathlib.Path(a.extension).read_bytes()).hexdigest(),'cases':results},indent=2)+'\n')
except BaseException as e:
 (out/'failure.json').write_text(json.dumps({'status':'FAIL','error':repr(e),'completedCases':results},indent=2)+'\n');raise
finally:audit.server.shutdown();audit.server.server_close()
